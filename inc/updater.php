<?php
/**
 * Functions used to update this plugin from an external website.
 */

namespace Cshp\lr;

/**
 * Check if the external URL domain is blocked by the WP_HTTP_BLOCK_EXTERNAL constant.
 *
 * Only checks if the site is blocked by WordPress, not of the site is actually reachable by the site.
 *
 * @param string $url URL to check.
 *
 * @return bool True if the domain is blocked. False if the domain is not explicitly blocked by WordPress.
 */
function is_external_domain_blocked( $url ) {
	$is_url_blocked = false;

	if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && true === WP_HTTP_BLOCK_EXTERNAL ) {
		$url_parts      = wp_parse_url( $url );
		$url_domain     = sprintf( '%s://%s', $url_parts['scheme'], $url_parts['host'] );
		$check_host     = new \WP_Http();
		$is_url_blocked = $check_host->block_request( $url_domain );
	}

	return $is_url_blocked;
}


/**
 * Initialize a way for the plugin to be updated since it will not be hosted on wordpress.org.
 *
 * @return void
 */
function plugin_update_checker() {
	$update_url       = sprintf( '%s/wp-json/cshp-plugin-updater/%s', get_plugin_update_url(), get_this_plugin_folder() );
	$plugin_file_path = get_plugin_file_full_path( sprintf( '%s/%s.php', get_this_plugin_folder(), get_this_plugin_slug() ) );

	// make sure the update will not be blocked before trying to update it
	if ( ! is_external_domain_blocked( $update_url ) && class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			$update_url,
			$plugin_file_path,
			get_this_plugin_folder()
		);
	}
}
add_action( 'init', __NAMESPACE__ . '\plugin_update_checker' );

/**
 * Get the URL where we can get the plugin updates since this plugin is not hosted on wordpress.org.
 *
 * @return string URL to ping to get plugin updates.
 */
function get_plugin_update_url() {
	return 'https://plugins.cornershopcreative.com';
}