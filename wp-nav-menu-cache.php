<?php
/**
 * Plugin Name:         wp-nav-menu-cache
 * Plugin URI:          https://github.com/Jazz-Man/wp-nav-menu-cache
 * Description:         Caches WordPress menus to improve page loading time.
 * Author:              Vasyl Sokolyk
 * Author URI:          https://www.linkedin.com/in/sokolyk-vasyl
 * Requires at least:   5.2
 * Requires PHP:        7.4
 * License:             MIT
 * Update URI:          https://github.com/Jazz-Man/wp-nav-menu-cache.
 */

use JazzMan\WpNavMenuCache\NavMenuCache;

if (!function_exists('app_autoload_classes')) {
    return;
}

if (!class_exists(NavMenuCache::class)) {
    return;
}
app_autoload_classes([
    NavMenuCache::class,
]);
