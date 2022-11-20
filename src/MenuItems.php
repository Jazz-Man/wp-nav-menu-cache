<?php

namespace JazzMan\WpNavMenuCache;

use JazzMan\WpNavMenuCacheStub\MenuItem;

use function Latitude\QueryBuilder\alias;
use function Latitude\QueryBuilder\field;
use function Latitude\QueryBuilder\on;

use Latitude\QueryBuilder\Query;
use Latitude\QueryBuilder\QueryFactory;

final class MenuItems {
    /**
     * @return MenuItem[]|\stdClass[]
     */
    public static function getItems(\WP_Term $wpTerm): array {
        $cacheKey = NavMenuCache::getMenuItemCacheKey($wpTerm);

        /** @var false|MenuItem[]|\stdClass[] $menuItems */
        $menuItems = wp_cache_get($cacheKey, 'menu_items');

        if (false === $menuItems) {
            try {
                $pdo = app_db_pdo();

                $query = self::generateSql($wpTerm);

                $pdoStatement = $pdo->prepare($query->sql());

                $pdoStatement->execute($query->params());

                /** @var MenuItem[]|\stdClass[] $menuItems */
                $menuItems = $pdoStatement->fetchAll(\PDO::FETCH_OBJ);

                foreach ($menuItems as $key => $item) {
                    $menuItems[$key] = self::setupNavMenuItem($item);
                }

                wp_cache_set($cacheKey, $menuItems, 'menu_items');
            } catch (\Exception $exception) {
                /** @var MenuItem|\stdClass $item */
                $item = new \stdClass();
                $item->_invalid = true;

                $menuItems = [];
                $menuItems[] = $item;

                app_error_log($exception, __METHOD__);
            }
        }

        if (!empty($menuItems) && !is_admin()) {
            return array_filter($menuItems, '_is_valid_nav_menu_item');
        }

        return $menuItems;
    }

    private static function generateSql(\WP_Term $wpTerm): Query {
        global $wpdb;

        $queryFactory = new QueryFactory();

        $selectQuery = $queryFactory
            ->select(
                'menu.*',
                alias('menu.post_content', 'description'),
                alias('menu.post_excerpt', 'attr_title')
            )
            ->from(alias($wpdb->posts, 'menu'))
            ->leftJoin(
                alias($wpdb->term_relationships, 'tr'),
                on('menu.ID', 'tr.object_id')
            )
            ->where(
                field('tr.term_taxonomy_id')
                    ->eq($wpTerm->term_taxonomy_id)
                    ->and(field('menu.post_type')->eq('nav_menu_item'))
                    ->and(field('menu.post_status')->eq('publish'))
            )
            ->groupBy('menu.ID', 'menu.menu_order')
            ->orderBy('menu.menu_order', 'asc')
        ;

        $menuMetaFields = [
            'classes' => '_menu_item_classes',
            'menu_item_parent' => '_menu_item_menu_item_parent',
            'object' => '_menu_item_object',
            'object_id' => '_menu_item_object_id',
            'target' => '_menu_item_target',
            'type' => '_menu_item_type',
            'url' => '_menu_item_url',
            'xfn' => '_menu_item_xfn',
        ];

        foreach ($menuMetaFields as $field => $metaKey) {
            $selectQuery->addColumns(alias("{$field}.meta_value", $field));
            $selectQuery->leftJoin(
                alias($wpdb->postmeta, $field),
                on('menu.ID', "{$field}.post_id")
                    ->and(
                        field("{$field}.meta_key")->eq($metaKey)
                    )
            );
        }

        $selectQuery->addColumns(
            'term_tax.taxonomy',
            'term_tax.term_id',
            alias('term.name', 'term_name'),
            alias('term_tax.description', 'term_description'),
            alias('term_tax.parent', 'term_parent'),
            alias('original_post.ID', 'original_id'),
            alias('original_post.post_status', 'original_post_status'),
            alias('original_post.post_title', 'original_post_title'),
        );
        $selectQuery->leftJoin(
            alias($wpdb->term_taxonomy, 'term_tax'),
            on('term_tax.term_id', 'object_id.meta_value')
                ->and(field('type.meta_value')->eq('taxonomy'))
        );

        $selectQuery->leftJoin(
            alias($wpdb->terms, 'term'),
            on('term_tax.term_id', 'term.term_id')
        );

        $selectQuery->leftJoin(
            alias($wpdb->posts, 'original_post'),
            on('original_post.ID', 'object_id.meta_value')
                ->and(field('type.meta_value')->eq('post_type'))
        );

        return $selectQuery->compile();
    }

    /**
     * @param MenuItem|\stdClass $menuItem
     *
     * @return MenuItem|\stdClass
     */
    private static function setupNavMenuItem($menuItem) {
        /**
         * Wrap the element in the WP_Post class for backward compatibility.
         *
         * @var MenuItem|\stdClass $menuItem
         */
        $menuItem = new \WP_Post($menuItem);

        self::setMenuItemLabels($menuItem);

        self::setMenuItemIds($menuItem);

        $menuItem->_invalid = self::checkIfInvalid($menuItem);

        $menuItem->url = self::getMenuItemUrl($menuItem);

        $menuItem->classes = self::setMenuClasses($menuItem);
        $menuItem->attr_title = (string) apply_filters('nav_menu_attr_title', $menuItem->attr_title);

        $menuItem->description = self::getMenuItemDescription($menuItem);

        return $menuItem;
    }

    /**
     * @param MenuItem|\stdClass $menuItem
     */
    private static function getMenuItemPostTitle($menuItem): string {
        return '' === $menuItem->post_title ? sprintf(__('#%d (no title)'), (int) $menuItem->ID) : (string) $menuItem->post_title;
    }

    /**
     * @param MenuItem|\stdClass $menuItem
     */
    private static function setMenuItemLabels(&$menuItem): void {
        $currentMenuTitle = (string) $menuItem->post_title;

        $menuItem->post_title = self::getMenuItemPostTitle($menuItem);

        $menuTitle = $menuItem->post_title;

        switch ($menuItem->type) {
            case 'taxonomy':
                $typeLabel = sprintf('Taxonomy%s', self::getMenuTaxonomyLabels($menuItem));

                $menuTitle = empty($currentMenuTitle) ? (string) $menuItem->term_name : $currentMenuTitle;

                break;

            case 'post_type_archive':
                $postTypeLabel = '';

                $postTypeObject = get_post_type_object((string) $menuItem->object);

                if ($postTypeObject instanceof \WP_Post_Type) {
                    $menuTitle = empty($currentMenuTitle) ? (string) $postTypeObject->labels->archives : $currentMenuTitle;

                    $postTypeLabel .= sprintf(': %s', $postTypeObject->label);
                }

                $typeLabel = sprintf('Post Type Archive%s', $postTypeLabel);

                break;

            case 'post_type':
                $typeLabel = (string) $menuItem->object;

                $postTypeObject = get_post_type_object((string) $menuItem->object);

                if ($postTypeObject instanceof \WP_Post_Type) {
                    $typeLabel = (string) $postTypeObject->labels->singular_name;
                }

                $postTitle = '';

                if (!empty($currentMenuTitle)) {
                    $postTitle = $currentMenuTitle;
                } elseif (!empty($menuItem->original_post_title)) {
                    $postTitle = (string) $menuItem->original_post_title;
                }

                if (!empty($postTitle)) {
                    $menuTitle = (string) apply_filters('the_title', $postTitle, $menuItem->object_id);
                }

                if (!empty($menuItem->original_post_status) && 'publish' !== $menuItem->original_post_status && \function_exists('get_post_states')) {
                    /** @var \WP_Post $originalPost */
                    $originalPost = get_post((int) $menuItem->object_id);

                    $typeLabel = wp_strip_all_tags(implode(', ', get_post_states($originalPost)));
                }

                break;

            default:
                $typeLabel = __('Custom Link');

                break;
        }

        $menuItem->title = $menuTitle;

        $menuItem->type_label = $typeLabel;
    }

    /**
     * @param MenuItem|\stdClass $menuItem
     */
    private static function getMenuTaxonomyLabels($menuItem): string {
        $label = '';

        $taxonomy = get_taxonomy((string) $menuItem->object);

        if ($taxonomy instanceof \WP_Taxonomy) {
            $labels = get_taxonomy_labels($taxonomy);

            $label = sprintf(
                ': %s',
                empty($labels->singular_name) ? $taxonomy->label : (string) $labels->singular_name
            );
        }

        return $label;
    }

    /**
     * @param MenuItem|\stdClass $menuItem
     */
    private static function setMenuItemIds(&$menuItem): void {
        $menuItem->db_id = (int) $menuItem->ID;
        $menuItem->menu_item_parent = (int) $menuItem->menu_item_parent;
        $menuItem->object_id = (int) $menuItem->object_id;

        if ('taxonomy' === $menuItem->type) {
            $menuItem->post_parent = (int) $menuItem->term_parent;
        }
    }

    /**
     * @param MenuItem|\stdClass $menuItem
     */
    private static function getMenuItemDescription($menuItem): string {
        switch ($menuItem->type) {
            case 'taxonomy':
                $description = empty($menuItem->term_description) ? '' : (string) $menuItem->term_description;

                break;

            case 'post_type_archive':
                $object = get_post_type_object((string) $menuItem->object);

                $description = $object instanceof \WP_Post_Type ? $object->description : '';

                break;

            case 'post_type':
            default:
                $description = (string) $menuItem->post_content;

                break;
        }

        $description = empty($description) ? '' : wp_trim_words($description, 200);

        return (string) apply_filters('nav_menu_description', $description);
    }

    /**
     * @param MenuItem|\stdClass $menuItem
     */
    private static function getMenuItemUrl($menuItem): string {
        switch ($menuItem->type) {
            case 'post_type':
                $url = get_permalink((int) $menuItem->object_id);

                break;

            case 'post_type_archive':
                $url = get_post_type_archive_link((string) $menuItem->object);

                break;

            case 'taxonomy':
                $url = app_get_term_link((int) $menuItem->object_id, (string) $menuItem->object);

                break;

            default:
                $url = (string) $menuItem->url;

                break;
        }

        return empty($url) ? '' : $url;
    }

    /**
     * @param MenuItem|\stdClass $menuItem
     */
    private static function checkIfInvalid($menuItem): bool {
        switch ($menuItem->type) {
            case 'post_type':
                return !post_type_exists((string) $menuItem->object) || 'trash' === $menuItem->original_post_status;

            case 'post_type_archive':
                return !post_type_exists((string) $menuItem->object);

            case 'taxonomy':
                $term = get_term((int) $menuItem->object_id, (string) $menuItem->object);

                return !$term instanceof \WP_Term;

            default:
                return false;
        }
    }

    /**
     * @param MenuItem|\stdClass $menuItem
     *
     * @return string[]
     */
    private static function setMenuClasses($menuItem): array {
        if (empty($menuItem->classes)) {
            return [];
        }

        if (!\is_string($menuItem->classes)) {
            return [];
        }

        return maybe_unserialize($menuItem->classes);
    }
}
