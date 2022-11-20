<?php

namespace JazzMan\WpNavMenuCache;

use JazzMan\Traits\SingletonTrait;
use JazzMan\WpNavMenuCacheStub\MenuItem;

final class MenuItemClasses {
    use SingletonTrait;

    /**
     * @var null|\WP_Post|\WP_Post_Type|\WP_Term|\WP_User
     */
    private $queriedObject;

    private string $frontPageUrl;

    /**
     * Fron page ID.
     */
    private int $frontId;

    private bool $isFrontPage;

    private int $privacyPageId;

    /**
     * Home page ID.
     */
    private int $homeId;

    /**
     * Current queried object ID.
     */
    private int $queriedObjectId;

    /**
     * @var int[]
     */
    private array $possibleObjectParents = [];

    /**
     * @var array<string,int[]>
     */
    private array $possibleTaxonomyAncestors = [];

    private bool $isPostTypeHierarchical;

    public function __construct() {
        global $wp_query;

        $this->queriedObjectId = $wp_query->get_queried_object_id();

        $this->queriedObject = $wp_query->get_queried_object();

        $this->isPostTypeHierarchical = !empty($this->queriedObject->post_type) && is_post_type_hierarchical((string) $this->queriedObject->post_type);

        $this->frontId = self::getIntOption('page_on_front');

        $this->isFrontPage = $wp_query->is_front_page();

        $this->homeId = self::getIntOption('page_for_posts');

        $this->privacyPageId = self::getIntOption('wp_page_for_privacy_policy');

        $this->frontPageUrl = home_url();

        $this->setTaxonomyAncestors();
    }

    /**
     * @param MenuItem[]|\stdClass[] $menuItems
     */
    public static function setMenuItemClassesByContext(array &$menuItems): void {
        global $wp_rewrite, $wp_query;

        $_this = self::getInstance();

        $activeObject = '';
        $ancestorItemIds = [];
        $activeParentItemIds = [];
        $activeParentObjectIds = [];

        foreach ($menuItems as $menuItem) {
            $menuItem->current = false;

            $menuItemType = (string) $menuItem->type;
            $menuItemObject = (string) $menuItem->object;

            /** @var string[] $classes */
            $classes = (array) $menuItem->classes;
            $classes[] = 'menu-item';
            $classes[] = sprintf('menu-item-type-%s', $menuItemType);
            $classes[] = sprintf('menu-item-object-%s', $menuItemObject);

            if ('post_type' === $menuItemType) {
                if ($_this->frontId === (int) $menuItem->object_id) {
                    $classes[] = 'menu-item-home';
                }

                if ($_this->privacyPageId === (int) $menuItem->object_id) {
                    $classes[] = 'menu-item-privacy-policy';
                }
            }

            if ($wp_query->is_singular && 'taxonomy' === $menuItemType && \in_array((int) $menuItem->object_id, $_this->possibleObjectParents, true)) {
                $activeParentObjectIds[] = (int) $menuItem->object_id;
                $activeParentItemIds[] = (int) $menuItem->db_id;

                if (!empty($_this->queriedObject->post_type)) {
                    $activeObject = (string) $_this->queriedObject->post_type;
                }
            } elseif ($_this->isCurrentMenuItem($menuItem)) {
                $classes[] = 'current-menu-item';
                $menuItem->current = true;

                if (!\in_array((int) $menuItem->db_id, $ancestorItemIds, true)) {
                    $ancestorItemIds[] = (int) $menuItem->db_id;
                }

                if ('post_type' === $menuItemType && 'page' === $menuItemObject) {
                    $classes[] = 'page_item';
                    $classes[] = sprintf('page-item-%d', (int) $menuItem->object_id);
                    $classes[] = 'current_page_item';
                }

                $activeParentItemIds[] = (int) $menuItem->menu_item_parent;
                $activeParentObjectIds[] = (int) $menuItem->post_parent;
                $activeObject = $menuItemObject;
            } elseif ('post_type_archive' === $menuItemType && $wp_query->is_post_type_archive($menuItemObject)) {
                $classes[] = 'current-menu-item';
                $menuItem->current = true;

                if (!\in_array((int) $menuItem->db_id, $ancestorItemIds, true)) {
                    $ancestorItemIds[] = (int) $menuItem->db_id;
                }
                $activeParentItemIds[] = (int) $menuItem->menu_item_parent;
            } elseif ('custom' === $menuItemObject && filter_input(INPUT_SERVER, 'HTTP_HOST')) {
                $rootRelativeCurrent = app_get_current_relative_url();

                $currentUrl = app_get_current_url();

                $isUrlHash = strpos((string) $menuItem->url, '#');

                $rawItemUrl = $isUrlHash ? (string) substr((string) $menuItem->url, 0, $isUrlHash) : (string) $menuItem->url;
                unset($isUrlHash);

                $itemUrl = set_url_scheme(untrailingslashit($rawItemUrl));
                $indexlessCurrent = untrailingslashit(
                    (string) preg_replace('/'.preg_quote($wp_rewrite->index, '/').'$/', '', $currentUrl)
                );

                $matches = [
                    $currentUrl,
                    urldecode($currentUrl),
                    $indexlessCurrent,
                    urldecode($indexlessCurrent),
                    $rootRelativeCurrent,
                    urldecode($rootRelativeCurrent),
                ];

                if ($rawItemUrl && \in_array($itemUrl, $matches, true)) {
                    $classes[] = 'current-menu-item';
                    $menuItem->current = true;

                    if (!\in_array((int) $menuItem->db_id, $ancestorItemIds, true)) {
                        $ancestorItemIds[] = (int) $menuItem->db_id;
                    }

                    if (\in_array(
                        $_this->frontPageUrl,
                        [
                            untrailingslashit($currentUrl),
                            untrailingslashit($indexlessCurrent),
                        ],
                        true
                    )) {
                        // Back compat for home link to match wp_page_menu().
                        $classes[] = 'current_page_item';
                    }
                    $activeParentItemIds[] = (int) $menuItem->menu_item_parent;
                    $activeParentObjectIds[] = (int) $menuItem->post_parent;
                    $activeObject = $menuItemObject;
                } elseif ($itemUrl === $_this->frontPageUrl && $_this->isFrontPage) {
                    $classes[] = 'current-menu-item';
                }

                if (untrailingslashit($itemUrl) === $_this->frontPageUrl) {
                    $classes[] = 'menu-item-home';
                }
            }

            // Back-compat with wp_page_menu(): add "current_page_parent" to static home page link for any non-page query.
            if ('post_type' === $menuItemType && empty($wp_query->is_page) && $_this->homeId === (int) $menuItem->object_id) {
                $classes[] = 'current_page_parent';
            }

            /** @var string[] $classes */
            $menuItem->classes = array_unique($classes);
        }

        $ancestorItemIds = array_filter(array_unique($ancestorItemIds));
        $activeParentItemIds = array_filter(array_unique($activeParentItemIds));
        $activeParentObjectIds = array_filter(array_unique($activeParentObjectIds));

        // Set parent's class.
        foreach ($menuItems as $parentItem) {
            /** @var string[] $classes */
            $classes = (array) $parentItem->classes;
            $parentItem->current_item_ancestor = false;
            $parentItem->current_item_parent = false;

            if ($_this->isCurrentMenuItemAncestor($parentItem)) {
                $ancestorType = false;

                if (!empty($_this->queriedObject->taxonomy)) {
                    $ancestorType = (string) $_this->queriedObject->taxonomy;
                }

                if (!empty($_this->queriedObject->post_type)) {
                    $ancestorType = (string) $_this->queriedObject->post_type;
                }

                if (!empty($ancestorType)) {
                    $classes[] = sprintf('current-%s-ancestor', $ancestorType);
                }

                unset($ancestorType);
            }

            if (\in_array((int) $parentItem->db_id, $ancestorItemIds, true)) {
                $classes[] = 'current-menu-ancestor';

                $parentItem->current_item_ancestor = true;
            }

            if (\in_array((int) $parentItem->db_id, $activeParentItemIds, true)) {
                $classes[] = 'current-menu-parent';

                $parentItem->current_item_parent = true;
            }

            if (\in_array((int) $parentItem->object_id, $activeParentObjectIds, true)) {
                $classes[] = sprintf('current-%s-parent', $activeObject);
            }

            if ('post_type' === $parentItem->type && 'page' === $parentItem->object) {
                // Back compat classes for pages to match wp_page_menu().
                if (\in_array('current-menu-parent', $classes, true)) {
                    $classes[] = 'current_page_parent';
                }

                if (\in_array('current-menu-ancestor', $classes, true)) {
                    $classes[] = 'current_page_ancestor';
                }
            }

            $parentItem->classes = array_unique($classes);
        }
    }

    private static function getIntOption(string $optionName): int {
        /** @var false|string $option */
        $option = get_option($optionName);

        return empty($option) ? 0 : (int) $option;
    }

    private function setTaxonomyAncestors(): void {
        global $wp_query;

        if ($wp_query->is_singular && !empty($this->queriedObject->post_type) && !$this->isPostTypeHierarchical) {
            /** @var array<string,\WP_Taxonomy> $taxonomies */
            $taxonomies = get_object_taxonomies((string) $this->queriedObject->post_type, 'objects');

            foreach ($taxonomies as $taxonomy => $taxonomyObject) {
                if ($taxonomyObject->hierarchical && $taxonomyObject->public) {
                    /** @var array<int,int> $termHierarchy */
                    $termHierarchy = _get_term_hierarchy($taxonomy);

                    /** @var int[]|\WP_Error $terms */
                    $terms = wp_get_object_terms($this->queriedObjectId, $taxonomy, ['fields' => 'ids']);

                    if (!$terms instanceof \WP_Error) {
                        $this->possibleObjectParents = array_merge($this->possibleObjectParents, $terms);

                        /** @var array<int,int> $termToAncestor */
                        $termToAncestor = [];

                        foreach ($termHierarchy as $anc => $descs) {
                            foreach ((array) $descs as $desc) {
                                $termToAncestor[$desc] = $anc;
                            }
                        }

                        foreach ($terms as $desc) {
                            do {
                                $this->possibleTaxonomyAncestors[$taxonomy][] = $desc;

                                if (isset($termToAncestor[$desc])) {
                                    $_desc = $termToAncestor[$desc];
                                    unset($termToAncestor[$desc]);
                                    $desc = $_desc;
                                } else {
                                    $desc = 0;
                                }
                            } while (!empty($desc));
                        }
                    }
                }
            }
        } elseif ($this->queriedObject instanceof \WP_Term && is_taxonomy_hierarchical($this->queriedObject->taxonomy)) {
            /** @var array<int,int> $termHierarchy */
            $termHierarchy = _get_term_hierarchy($this->queriedObject->taxonomy);

            /** @var array<int,int> $termToAncestor */
            $termToAncestor = [];

            foreach ($termHierarchy as $anc => $descs) {
                foreach ((array) $descs as $desc) {
                    $termToAncestor[$desc] = $anc;
                }
            }

            $desc = $this->queriedObject->term_id;

            do {
                $this->possibleTaxonomyAncestors[$this->queriedObject->taxonomy][] = $desc;

                if (isset($termToAncestor[$desc])) {
                    $_desc = $termToAncestor[$desc];
                    unset($termToAncestor[$desc]);
                    $desc = $_desc;
                } else {
                    $desc = 0;
                }
            } while (!empty($desc));
        }

        $this->possibleObjectParents = array_map('absint', array_filter($this->possibleObjectParents));
    }

    /**
     * @param MenuItem|\stdClass $menuItem
     */
    private function isCurrentMenuItem($menuItem): bool {
        global $wp_query;

        $postTypeCondition = false;
        $taxonomyCondition = false;

        switch ((string) $menuItem->type) {
            case 'post_type':
                $postTypeCondition = ($wp_query->is_home && $this->homeId === (int) $menuItem->object_id) || $wp_query->is_singular;

                break;

            case 'taxonomy':
                $isCategory = $wp_query->is_category || $wp_query->is_tag || $wp_query->is_tax;

                $taxonomyCondition = $isCategory && (!empty($this->queriedObject->taxonomy) && $this->queriedObject->taxonomy === (string) $menuItem->object);

                break;
        }

        return (int) $menuItem->object_id === $this->queriedObjectId && ($postTypeCondition || $taxonomyCondition);
    }

    /**
     * @param MenuItem|\stdClass $parent
     */
    private function isCurrentMenuItemAncestor($parent): bool {
        if (empty($parent->type)) {
            return false;
        }

        switch ((string) $parent->type) {
            case 'post_type':
                if (!$this->isPostTypeHierarchical) {
                    return false;
                }

                if ($this->queriedObject instanceof \WP_Post) {
                    return \in_array((int) $parent->object_id, $this->queriedObject->ancestors, true) && $parent->object != $this->queriedObject->ID;
                }

                break;

            case 'taxonomy':
                $inTaxonomyAncestors = !empty($this->possibleTaxonomyAncestors[(string) $parent->object])
                                       && \in_array((int) $parent->object_id, $this->possibleTaxonomyAncestors[(string) $parent->object], true);

                return $inTaxonomyAncestors && (!isset($this->queriedObject->term_id) || $parent->object_id != $this->queriedObject->term_id);
        }

        return false;
    }
}
