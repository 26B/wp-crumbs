<?php
/**
 * Plugin specific helpers.
 *
 * @package TSB\WP\Plugin\Crumbs
 */

namespace TSB\WP\Plugin\Crumbs;

/**
 * Get an initialized class by its full class name, including namespace.
 *
 * @param string $class_name The class name including the namespace.
 *
 * @return false|Module
 */
function get_module( $class_name ) {
	return \TenupFramework\ModuleInitialization::instance()->get_class( $class_name );
}
