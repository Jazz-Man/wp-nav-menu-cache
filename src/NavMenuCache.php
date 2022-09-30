<?php

namespace JazzMan\WpNavMenuCache;

use JazzMan\AutoloadInterface\AutoloadInterface;
use JazzMan\WpNavMenuCacheStub\MenuItem;
use JazzMan\WpNavMenuCacheStub\NavMenuArgs;
use WP_Error;
use WP_Term;

class NavMenuCache implements AutoloadInterface {
    /**
     * @var string
     */
    public const CACHE_GROUP = 'wp-nav-menu-cache';

    public function load(): void {
        /**
         * We clear the cache when the post is updated.
         */
        add_action('delete_post', [__CLASS__, 'resetMenuCacheByMenuId']);
        add_action('wp_update_nav_menu_item', [__CLASS__, 'resetMenuCacheByMenuId']);
        add_action('wp_add_nav_menu_item', [__CLASS__, 'resetMenuCacheByMenuId']);

        /**
         * We clear the cache when the term is updated.
         */
        add_action('delete_term', [__CLASS__, 'resetMenuCacheByTermId']);
        add_action('wp_create_nav_menu', [__CLASS__, 'resetMenuCacheByTermId']);
        add_action('saved_nav_menu', [__CLASS__, 'resetMenuCacheByTermId']);

        add_filter('wp_nav_menu_args', [__CLASS__, 'setMenuFallbackParams']);
        add_filter('pre_wp_nav_menu', [$this, 'buildWpNavMenu'], 10, 2);

        add_action('saved_term', [__CLASS__, 'termsCache'], 10, 3);
    }

    public static function termsCache(int $termId, int $termTaxId, string $taxonomy): void {
        wp_cache_delete(sprintf('taxonomy_ancestors_%d_%s', $termId, $taxonomy), self::CACHE_GROUP);
        wp_cache_delete(sprintf('term_all_children_%d', $termId), self::CACHE_GROUP);

        app_term_get_all_children($termId);
    }

    /**
     * @param NavMenuArgs|\stdClass $args
     *
     * @return false|mixed|string
     */
    public function buildWpNavMenu(?string $output, $args) {
        $menu = wp_get_nav_menu_object((string) $args->menu);

        if (false === $menu) {
            return $output;
        }

        $menuItems = MenuItems::getItems($menu);

        if (!empty($menuItems) && !is_admin()) {
            $menuItems = array_filter($menuItems, '_is_valid_nav_menu_item');
        }

        /** @var MenuItem[]|\stdClass[] $menuItems */
        $menuItems = apply_filters('wp_get_nav_menu_items', $menuItems, $menu, $args);

        if (empty($menuItems) && (!empty($args->fallback_cb) && \is_callable($args->fallback_cb))) {
            return \call_user_func($args->fallback_cb, (array) $args);
        }

        $menuCssClassses = new MenuItemClasses();

        $menuCssClassses->setMenuItemClassesByContext($menuItems);

        /** @var array<int,MenuItem> $sortedMenuItems */
        $sortedMenuItems = [];

        /** @var array<int,boolean> $menuWithChildren */
        $menuWithChildren = [];

        foreach ($menuItems as $menuItem) {
            $sortedMenuItems[(int) $menuItem->menu_order] = $menuItem;

            if ($menuItem->menu_item_parent) {
                $menuWithChildren[(int) $menuItem->menu_item_parent] = true;
            }
        }

        // Add the menu-item-has-children class where applicable.
        if ([] !== $menuWithChildren) {
            /** @var MenuItem $sortedMenuItem */
            foreach ($sortedMenuItems as &$sortedMenuItem) {
                if (isset($menuWithChildren[$sortedMenuItem->ID])) {
                    $classes = (array) $sortedMenuItem->classes;

                    $classes[] = 'menu-item-has-children';

                    $sortedMenuItem->classes = $classes;
                }
            }
        }

        unset($menuItems, $menuItem, $menuWithChildren);

        /** @var array<int,MenuItem> $sortedMenuItems */
        $sortedMenuItems = apply_filters('wp_nav_menu_objects', $sortedMenuItems, $args);

        $items = walk_nav_menu_tree($sortedMenuItems, (int) $args->depth, $args);
        unset($sortedMenuItems);

        $wrapId = $this->getMenuWrapId($menu, $args);

        $wrapClass = !empty($args->menu_class) ? (string) $args->menu_class : '';

        $items = (string) apply_filters('wp_nav_menu_items', $items, $args);

        $items = (string) apply_filters(sprintf('wp_nav_menu_%s_items', $menu->slug), $items, $args);

        if (empty($items)) {
            return false;
        }

        $navMenu = sprintf(
            (string) $args->items_wrap,
            esc_attr($wrapId),
            esc_attr($wrapClass),
            $items
        );
        unset($items);

        $navMenu = $this->wrapToContainer($args, $menu, $navMenu);

        return (string) apply_filters('wp_nav_menu', $navMenu, $args);
    }

    /**
     * @param array<string,mixed> $args
     *
     * @psalm-return array<string, mixed>
     *
     * @return array<string, mixed>
     */
    public static function setMenuFallbackParams(array $args): array {
        $args['fallback_cb'] = '__return_empty_string';

        return $args;
    }

    public static function resetMenuCacheByTermId(int $termId): void {
        /** @var WP_Error|WP_Term $term */
        $term = get_term($termId, 'nav_menu');

        if ($term instanceof WP_Term) {
            self::deleteMenuItemCache($term);
        }
    }

    public static function resetMenuCacheByMenuId(int $menuId): void {
        /** @var WP_Error|WP_Term[] $terms */
        $terms = wp_get_post_terms($menuId, 'nav_menu');

        if (!$terms instanceof WP_Error) {
            foreach ($terms as $term) {
                self::deleteMenuItemCache($term);
            }
        }
    }

    public static function getMenuItemCacheKey(WP_Term $wpTerm): string {
        return sprintf('%s_%s', $wpTerm->taxonomy, $wpTerm->slug);
    }

    /**
     * @param NavMenuArgs|\stdClass $args
     */
    private function getMenuWrapId(WP_Term $wpTerm, $args): string {
        /** @var string[] $menuIdSlugs */
        static $menuIdSlugs = [];

        // Attributes.
        if (!empty($args->menu_id)) {
            return (string) $args->menu_id;
        }

        $wrapId = sprintf('menu-%s', $wpTerm->slug);

        while (\in_array($wrapId, $menuIdSlugs, true)) {
            $pattern = '#-(\d+)$#';
            preg_match($pattern, $wrapId, $matches);

            /** @var null|int[] $matches */
            $wrapId = empty($matches) ? $wrapId.'-1' : (string) preg_replace($pattern, '-'.++$matches[1], $wrapId);
        }

        $menuIdSlugs[] = $wrapId;

        return $wrapId;
    }

    /**
     * @param NavMenuArgs|\stdClass $args
     */
    private function wrapToContainer($args, WP_Term $wpTerm, string $navMenu): string {
        /** @var string[] $allowedTags */
        $allowedTags = (array) apply_filters('wp_nav_menu_container_allowedtags', ['div', 'nav']);

        if (!empty($args->container) && \in_array($args->container, $allowedTags, true)) {
            /** @var array<string,string|string[]> $attributes */
            $attributes = [
                'class' => $args->container_class ?: sprintf('menu-%s-container', $wpTerm->slug),
            ];

            if (!empty($args->container_id)) {
                $attributes['id'] = (string) $args->container_id;
            }

            if ('nav' === $args->container && !empty($args->container_aria_label)) {
                $attributes['aria-label'] = (string) $args->container_aria_label;
            }

            return sprintf(
                '<%1$s %2$s>%3$s</%1$s>',
                $args->container,
                app_add_attr_to_el($attributes),
                $navMenu
            );
        }

        return $navMenu;
    }

    private static function deleteMenuItemCache(WP_Term $wpTerm): void {
        wp_cache_delete(self::getMenuItemCacheKey($wpTerm), 'menu_items');
    }
}
