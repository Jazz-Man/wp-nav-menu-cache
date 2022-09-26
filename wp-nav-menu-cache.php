<?php
/**
 * Plugin Name:         wp-nav-menu-cache
 * Plugin URI:          https://github.com/Jazz-Man/wp-nav-menu-cache
 * Author:              Vasyl Sokolyk
 * Author URI:          https://www.linkedin.com/in/sokolyk-vasyl
 * Requires at least:   5.2
 * Requires PHP:        7.4
 * License:             MIT
 * Update URI:          https://github.com/Jazz-Man/wp-nav-menu-cache
 */

use JazzMan\WpNavMenuCache\NavMenuCache;

if ( function_exists('app_autoload_classes') && class_exists(NavMenuCache::class)) {
	app_autoload_classes([
		NavMenuCache::class,
	]);
}