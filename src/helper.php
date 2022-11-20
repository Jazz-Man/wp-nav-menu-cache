<?php

use JazzMan\WpNavMenuCache\NavMenuCache;

if (!function_exists('app_get_term_link')) {
    /**
     * @return false|string
     */
    function app_get_term_link(int $termId, string $termTaxonomy) {
        global $wp_rewrite;

        $term = get_term($termId, $termTaxonomy);

        if (!$term instanceof WP_Term) {
            return false;
        }

        $taxonomy = get_taxonomy($term->taxonomy);

        if (!$taxonomy instanceof WP_Taxonomy) {
            return false;
        }

        $termlink = $wp_rewrite->get_extra_permastruct($term->taxonomy);

        /** @var false|string $termlink */
        $termlink = apply_filters('pre_term_link', $termlink, $term);

        $termlinkSlug = $term->slug;

        if (empty($termlink)) {
            switch (true) {
                case 'category' === $term->taxonomy:
                    $termlink = sprintf('?cat=%s', $term->term_id);

                    break;

                case !empty($taxonomy->query_var):
                    $termlink = sprintf('?%s=%s', $taxonomy->query_var, $term->slug);

                    break;

                default:
                    $termlink = sprintf('?taxonomy=%s&term=%s', $term->taxonomy, $term->slug);

                    break;
            }

            return app_term_link_filter($term, home_url($termlink));
        }

        if (!empty($taxonomy->rewrite) && $taxonomy->rewrite['hierarchical']) {
            /** @var string[] $hierarchicalSlugs */
            $hierarchicalSlugs = [];

            if ($term->parent) {
                $ancestorsKey = sprintf('taxonomy_ancestors_%d_%s', $term->term_id, $term->taxonomy);

                /** @var false|string[] $hierarchicalSlugs */
                $hierarchicalSlugs = wp_cache_get($ancestorsKey, NavMenuCache::CACHE_GROUP);

                if (empty($hierarchicalSlugs)) {
                    /** @var string[] $result */
                    $result = [];

                    /** @var false|\stdClass[] $ancestors */
                    $ancestors = app_get_taxonomy_ancestors($term->term_id, $term->taxonomy, PDO::FETCH_CLASS);

                    if (!empty($ancestors)) {
                        foreach ($ancestors as $ancestor) {
                            $result[] = (string) $ancestor->term_slug;
                        }
                    }

                    $hierarchicalSlugs = $result;

                    wp_cache_set($ancestorsKey, $result, NavMenuCache::CACHE_GROUP);
                }

                $hierarchicalSlugs = array_reverse($hierarchicalSlugs);
            }

            $hierarchicalSlugs[] = $term->slug;

            $termlinkSlug = implode('/', $hierarchicalSlugs);
        }

        $termlink = str_replace(sprintf('%%%s%%', $term->taxonomy), $termlinkSlug, $termlink);

        $termlink = home_url(user_trailingslashit($termlink, 'category'));

        return app_term_link_filter($term, $termlink);
    }
}

if (!function_exists('app_term_link_filter')) {
    /**
     * @param \WP_Term $wpTerm
     */
    function app_term_link_filter(WP_Term $wpTerm, string $termlink): string {
        if ('post_tag' == $wpTerm->taxonomy) {
            $termlink = (string) apply_filters('tag_link', $termlink, $wpTerm->term_id);
        } elseif ('category' == $wpTerm->taxonomy) {
            $termlink = (string) apply_filters('category_link', $termlink, $wpTerm->term_id);
        }

        return (string) apply_filters('term_link', $termlink, $wpTerm, $wpTerm->taxonomy);
    }
}

if (!function_exists('app_get_taxonomy_ancestors')) {
    /**
     * @param array<array-key, mixed> ...$args PDO fetch options
     *
     * @return array<string,int|string>|false
     */
    function app_get_taxonomy_ancestors(int $termId, string $taxonomy, int $mode = PDO::FETCH_COLUMN, ...$args) {
        global $wpdb;

        try {
            $pdo = app_db_pdo();

            $pdoStatement = $pdo->prepare(
                <<<SQL
                    with recursive ancestors as (
                      select
                        cat_1.term_id,
                        cat_1.taxonomy,
                        cat_1.parent
                      from {$wpdb->term_taxonomy} as cat_1
                      where
                        cat_1.term_id = :term_id
                      union all
                      select
                        a.term_id,
                        cat_2.taxonomy,
                        cat_2.parent
                      from ancestors a
                        inner join {$wpdb->term_taxonomy} cat_2 on cat_2.term_id = a.parent
                      where
                        cat_2.parent > 0
                        and cat_2.taxonomy = :taxonomy
                      )
                    select
                      a.parent as term_id,
                      a.taxonomy as taxonomy,
                      term.name as term_name,
                      term.slug as term_slug
                    from ancestors a
                      left join {$wpdb->terms} as term on term.term_id = a.parent
                    SQL
            );

            $pdoStatement->execute(['term_id' => $termId, 'taxonomy' => $taxonomy]);

            /** @var array<string,int|string> $ancestors */
            $ancestors = $pdoStatement->fetchAll($mode, ...$args);

            if (!empty($ancestors)) {
                return $ancestors;
            }

            return false;
        } catch (Exception $exception) {
            app_error_log($exception, 'app_get_taxonomy_ancestors');

            return false;
        }
    }
}

if (!function_exists('app_term_get_all_children')) {
    /**
     * @return false|int[]
     */
    function app_term_get_all_children(int $termId) {
        global $wpdb;

        /** @var int[] $children */
        $children = wp_cache_get(sprintf('term_all_children_%d', $termId), NavMenuCache::CACHE_GROUP);

        if (empty($children)) {
            try {
                $pdo = app_db_pdo();

                $pdoStatement = $pdo->prepare(
                    <<<SQL
                        with recursive children as (
                          select
                            d.term_id,
                            d.parent
                          from {$wpdb->term_taxonomy} d
                          where
                            d.term_id = :term_id
                          union all
                          select
                            d.term_id,
                            d.parent
                          from {$wpdb->term_taxonomy} d
                            inner join children c on c.term_id = d.parent
                          )
                        select
                          c.term_id as term_id
                        from children c
                        SQL
                );

                $pdoStatement->execute(['term_id' => $termId]);

                /** @var false|int[] $children */
                $children = $pdoStatement->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($children)) {
                    sort($children);

                    wp_cache_set(sprintf('term_all_children_%d', $termId), $children, NavMenuCache::CACHE_GROUP);
                }
            } catch (Exception $exception) {
                app_error_log($exception, __FUNCTION__);
            }
        }

        return $children;
    }
}
