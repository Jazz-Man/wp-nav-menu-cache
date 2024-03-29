<?php

namespace JazzMan\WpNavMenuCache;

use JazzMan\WpNavMenuCacheStub\MenuItem;

final class MenuItems {
    /**
     * @return MenuItem[]|\stdClass[]
     */
    public static function getItems(\WP_Term $wpTerm): array {
	    global $wpdb;

        $cacheKey = NavMenuCache::getMenuItemCacheKey($wpTerm);

        /** @var false|MenuItem[]|\stdClass[] $menuItems */
        $menuItems = wp_cache_get($cacheKey, 'menu_items');

        if (false === $menuItems) {
            try {
                $pdo = app_db_pdo();

                $pdoStatement = $pdo->prepare(
                    <<<SQL
                        select
                          menu.*,
                          menu.post_content as description,
                          menu.post_excerpt as attr_title,
                          classes.meta_value as classes,
                          parent.meta_value as menu_item_parent,
                          object.meta_value as object,
                          object_id.meta_value as object_id,
                          target.meta_value as target,
                          type.meta_value as type,
                          url.meta_value as url,
                          xfn.meta_value as xfn,
                          term_tax.taxonomy,
                          term_tax.term_id,
                          term.name as term_name,
                          term_tax.description as term_description,
                          term_tax.parent as term_parent,
                          original_post.ID as original_id,
                          original_post.post_status as original_post_status,
                          original_post.post_title as original_post_title
                        from $wpdb->posts as menu
                        left join $wpdb->term_relationships as tr on menu.ID = tr.object_id
                        left join $wpdb->postmeta as classes on menu.ID = classes.post_id and classes.meta_key = '_menu_item_classes'
                        left join $wpdb->postmeta as parent on menu.ID = parent.post_id and parent.meta_key = '_menu_item_menu_item_parent'
                        left join $wpdb->postmeta as object on menu.ID = object.post_id and object.meta_key = '_menu_item_object'
                        left join $wpdb->postmeta as object_id on menu.ID = object_id.post_id and object_id.meta_key = '_menu_item_object_id'
                        left join $wpdb->postmeta as target on menu.ID = target.post_id and target.meta_key = '_menu_item_target'
                        left join $wpdb->postmeta as type on menu.ID = type.post_id and type.meta_key = '_menu_item_type'
                        left join $wpdb->postmeta as url on menu.ID = url.post_id and url.meta_key = '_menu_item_url'
                        left join $wpdb->postmeta as xfn on menu.ID = xfn.post_id and xfn.meta_key = '_menu_item_xfn'
                        left join $wpdb->term_taxonomy as term_tax on term_tax.term_id = object_id.meta_value and type.meta_value = 'taxonomy'
                        left join $wpdb->terms as term on term_tax.term_id = term.term_id
                        left join $wpdb->posts as original_post on original_post.ID = object_id.meta_value and type.meta_value = 'post_type'
                        where
                            tr.term_taxonomy_id = :taxonomy_id
                        and menu.post_type = 'nav_menu_item'
                        and menu.post_status = 'publish'
                        group by
                          menu.ID,
                          menu.menu_order
                        order by
                          menu.menu_order asc
                        SQL
                );

                $pdoStatement->execute([
                    ':taxonomy_id' => $wpTerm->term_id,
                ]);

                /** @var MenuItem[]|\stdClass[] $menuItems */
                $menuItems = $pdoStatement->fetchAll(\PDO::FETCH_OBJ);

                foreach ($menuItems as $key => $item) {
                    $menuItems[$key] = self::setupNavMenuItem($item);
                }

                wp_cache_set($cacheKey, $menuItems, 'menu_items');
            } catch (\Exception $exception) {
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
     * @psalm-return string[]
     * @return string[]
     */
    private static function setMenuClasses($menuItem): array {
        if (empty($menuItem->classes)) {
            return [];
        }

        if (!\is_string($menuItem->classes)) {
            return [];
        }

        return (array) maybe_unserialize($menuItem->classes);
    }
}
