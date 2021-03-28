<?php

namespace JazzMan\WpNavMenuCache;

add_filter('pre_wp_nav_menu', __NAMESPACE__ .'\build_wp_nav_menu', 10, 2);

/**
 * @param  null|string  $output
 * @param  \stdClass|null  $args
 *
 * @return mixed|null
 */
function build_wp_nav_menu($output = null, \stdClass $args = null)
{
    $menu = wp_get_nav_menu_object($args->menu);

    if (empty($menu)) {
        return $output;
    }

    static $menu_id_slugs = [];

    $menu_items = wp_cache_get("{$menu->taxonomy}_{$args->menu}", 'menu_items');

    if ($menu_items === false) {
        global $wpdb;

        $pdo = app_db_pdo();

        $nav_st = $pdo->prepare(
            <<<SQL
select
  m.ID,
  m.post_title,
  m.post_name,
  m.post_parent,
  m.menu_order,
  m.post_type,
  m.post_content,
  m.post_excerpt,
  group_concat(if(meta.meta_key = '_menu_item_classes', meta.meta_value, null)) as classes,
  group_concat(if(meta.meta_key = '_menu_item_menu_item_parent', meta.meta_value, null)) as menu_item_parent,
  group_concat(if(meta.meta_key = '_menu_item_object', meta.meta_value, null)) as object,
  group_concat(if(meta.meta_key = '_menu_item_object_id', meta.meta_value, null)) as object_id,
  group_concat(if(meta.meta_key = '_menu_item_target', meta.meta_value, null)) as target,
  group_concat(if(meta.meta_key = '_menu_item_type', meta.meta_value, null)) as type,
  group_concat(if(meta.meta_key = '_menu_item_url', meta.meta_value, null)) as url,
  group_concat(if(meta.meta_key = '_menu_item_xfn', meta.meta_value, null)) as xfn
from $wpdb->posts m
left join $wpdb->term_relationships as tr on m.ID = tr.object_id
left join $wpdb->postmeta meta on m.ID = meta.post_id
where
    tr.term_taxonomy_id = :term_taxonomy_id
and m.post_type = 'nav_menu_item'
and m.post_status = 'publish'
group by
  m.ID,
  m.menu_order
order by
  m.menu_order asc
SQL
        );

        $nav_st->execute(
            [
                'term_taxonomy_id' => $menu->term_taxonomy_id,
            ]
        );

        $menu_items = $nav_st->fetchAll(\PDO::FETCH_OBJ);

        wp_cache_set("{$menu->taxonomy}_{$args->menu}", $menu_items, 'menu_items');
    }

    $menu_items = \array_map(__NAMESPACE__ .'\wp_setup_nav_menu_item', $menu_items);
//    $menu_items = \array_map([$this, 'wp_setup_nav_menu_item'], $menu_items);

    if (! is_admin()) {
        $menu_items = \array_filter($menu_items, '_is_valid_nav_menu_item');
    }

    if ((empty($menu_items) && ! $args->theme_location)
        && isset($args->fallback_cb) && $args->fallback_cb && \is_callable($args->fallback_cb)) {
        return \call_user_func($args->fallback_cb, (array) $args);
    }

    $nav_menu = '';
    $items = '';

    $show_container = false;
    if ($args->container) {
        $allowed_tags = apply_filters('wp_nav_menu_container_allowedtags', ['div', 'nav']);

        if (\is_string($args->container) && \in_array($args->container, $allowed_tags, true)) {
            $show_container = true;
            $class = $args->container_class ? ' class="' . esc_attr($args->container_class) . '"' : ' class="menu-' . $menu->slug . '-container"';
            $id = $args->container_id ? ' id="' . esc_attr($args->container_id) . '"' : '';
            $aria_label = ('nav' === $args->container && $args->container_aria_label) ? ' aria-label="' . esc_attr($args->container_aria_label) . '"' : '';
            $nav_menu .= '<' . $args->container . $id . $class . $aria_label . '>';
        }
    }

    $this->wp_menu_item_classes_by_context($menu_items);

    $sorted_menu_items = [];
    $menu_items_with_children = [];
    foreach ((array) $menu_items as $menu_item) {
        $sorted_menu_items[$menu_item->menu_order] = $menu_item;
        if ($menu_item->menu_item_parent) {
            $menu_items_with_children[$menu_item->menu_item_parent] = true;
        }
    }

    // Add the menu-item-has-children class where applicable.
    if ($menu_items_with_children) {
        foreach ($sorted_menu_items as &$menu_item) {
            if (isset($menu_items_with_children[$menu_item->ID])) {
                $menu_item->classes[] = 'menu-item-has-children';
            }
        }
    }

    unset($menu_items, $menu_item);

    $sorted_menu_items = apply_filters('wp_nav_menu_objects', $sorted_menu_items, $args);

    $items .= walk_nav_menu_tree($sorted_menu_items, $args->depth, $args);
    unset($sorted_menu_items);

    // Attributes.
    if (! empty($args->menu_id)) {
        $wrap_id = $args->menu_id;
    } else {
        $wrap_id = 'menu-' . $menu->slug;

        while (\in_array($wrap_id, $menu_id_slugs, true)) {
            if (\preg_match('#-(\d+)$#', $wrap_id, $matches)) {
                $wrap_id = \preg_replace('#-(\d+)$#', '-' . ++$matches[1], $wrap_id);
            } else {
                $wrap_id = $wrap_id . '-1';
            }
        }
    }
    $menu_id_slugs[] = $wrap_id;

    $wrap_class = $args->menu_class ? $args->menu_class : '';

    $items = apply_filters('wp_nav_menu_items', $items, $args);

    $items = apply_filters("wp_nav_menu_{$menu->slug}_items", $items, $args);

    if (empty($items)) {
        return false;
    }

    $nav_menu .= \sprintf($args->items_wrap, esc_attr($wrap_id), esc_attr($wrap_class), $items);
    unset($items);

    if ($show_container) {
        $nav_menu .= '</' . $args->container . '>';
    }

    return apply_filters('wp_nav_menu', $nav_menu, $args);
}

function wp_setup_nav_menu_item(\stdClass $menu_item): \stdClass
{
    if (isset($menu_item->post_type)) {
        if ('nav_menu_item' === $menu_item->post_type) {
            $menu_item->db_id = (int) $menu_item->ID;
            $menu_item->menu_item_parent = (int) $menu_item->menu_item_parent;
            $menu_item->object_id = (int) $menu_item->object_id;

            switch ($menu_item->type) {
                case 'post_type':
                    $object = get_post_type_object($menu_item->object);
                    if ($object) {
                        $menu_item->type_label = $object->labels->singular_name;
                        if (\function_exists('get_post_states')) {
                            $menu_post = get_post($menu_item->object_id);
                            $post_states = get_post_states($menu_post);
                            if ($post_states) {
                                $menu_item->type_label = wp_strip_all_tags(\implode(', ', $post_states));
                            }
                        }
                    } else {
                        $menu_item->type_label = $menu_item->object;
                        $menu_item->_invalid = true;
                    }

                    if ('trash' === get_post_status($menu_item->object_id)) {
                        $menu_item->_invalid = true;
                    }

                    $original_object = get_post($menu_item->object_id);

                    if ($original_object) {
                        $menu_item->url = get_permalink($original_object->ID);
                        $original_title = apply_filters(
                            'the_title',
                            $original_object->post_title,
                            $original_object->ID
                        );
                    } else {
                        $menu_item->url = '';
                        $original_title = '';
                        $menu_item->_invalid = true;
                    }

                    if ('' === $original_title) {
                        $original_title = \sprintf(__('#%d (no title)'), $menu_item->object_id);
                    }

                    $menu_item->title = ('' === $menu_item->post_title) ? $original_title : $menu_item->post_title;

                    break;
                case 'post_type_archive':
                    $object = get_post_type_object($menu_item->object);
                    if ($object) {
                        $menu_item->title = ('' === $menu_item->post_title) ? $object->labels->archives : $menu_item->post_title;
                    } else {
                        $menu_item->_invalid = true;
                    }

                    $menu_item->type_label = __('Post Type Archive');
                    $menu_item->url = get_post_type_archive_link($menu_item->object);
                    break;
                case 'taxonomy':
                    $object = get_taxonomy($menu_item->object);
                    if ($object) {
                        $menu_item->type_label = $object->labels->singular_name;
                    } else {
                        $menu_item->type_label = $menu_item->object;
                        $menu_item->_invalid = true;
                    }

                    $original_object = get_term((int) $menu_item->object_id, $menu_item->object);

                    if ($original_object && ! is_wp_error($original_object)) {
                        $menu_item->url = app_get_term_link((int) $menu_item->object_id, $menu_item->object);
                        $original_title = $original_object->name;
                    } else {
                        $menu_item->url = '';
                        $original_title = '';
                        $menu_item->_invalid = true;
                    }

                    if ('' === $original_title) {
                        $original_title = \sprintf(__('#%d (no title)'), $menu_item->object_id);
                    }

                    $menu_item->title = ('' === $menu_item->post_title) ? $original_title : $menu_item->post_title;
                    break;
                default:
                    $menu_item->type_label = __('Custom Link');
                    $menu_item->title = $menu_item->post_title;
                    break;
            }

            $menu_item->attr_title = ! isset($menu_item->attr_title) ? apply_filters(
                'nav_menu_attr_title',
                $menu_item->post_excerpt
            ) : $menu_item->attr_title;

            if (! isset($menu_item->description)) {
                $menu_item->description = apply_filters(
                    'nav_menu_description',
                    wp_trim_words($menu_item->post_content, 200)
                );
            }

            $menu_item->classes = (array) maybe_unserialize($menu_item->classes);
        } else {
            $menu_item->db_id = 0;
            $menu_item->menu_item_parent = 0;
            $menu_item->object_id = (int) $menu_item->ID;
            $menu_item->type = 'post_type';

            $object = get_post_type_object($menu_item->post_type);
            $menu_item->object = $object->name;
            $menu_item->type_label = $object->labels->singular_name;

            if ('' === $menu_item->post_title) {
                $menu_item->post_title = \sprintf(__('#%d (no title)'), $menu_item->ID);
            }

            $menu_item->title = $menu_item->post_title;
            $menu_item->url = get_permalink($menu_item->ID);
            $menu_item->target = '';
            $menu_item->attr_title = apply_filters('nav_menu_attr_title', '');
            $menu_item->description = apply_filters('nav_menu_description', '');
            $menu_item->classes = [];
            $menu_item->xfn = '';
        }
    } elseif (isset($menu_item->taxonomy)) {
        $menu_item->ID = $menu_item->term_id;
        $menu_item->db_id = 0;
        $menu_item->menu_item_parent = 0;
        $menu_item->object_id = (int) $menu_item->term_id;
        $menu_item->post_parent = (int) $menu_item->parent;
        $menu_item->type = 'taxonomy';

        $object = get_taxonomy($menu_item->taxonomy);
        $menu_item->object = $object->name;
        $menu_item->type_label = $object->labels->singular_name;

        $menu_item->title = $menu_item->name;
        $menu_item->url = app_get_term_link((int)$menu_item, (string) $menu_item->taxonomy);
        $menu_item->target = '';
        $menu_item->attr_title = '';
        $menu_item->description = get_term_field('description', $menu_item->term_id, $menu_item->taxonomy);
        $menu_item->classes = [];
        $menu_item->xfn = '';
    }

    return $menu_item;
}