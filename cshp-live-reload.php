<?php
/**
Plugin Name: Cornershop Live Reload
Plugin URI: https://cornershopcreative.com/
Description: Reload the website when a CSS, JS, or PHP file is updated. Helpful during theme development. Works with crate and blocksy-child. Replaces Gulp's Browsersync live reload feature for development.
Version: 0.1.0
Text Domain: cshp-pt
Author: Cornershop Creative
Author URI: https://cornershopcreative.com/
License: GPLv2 or later
Requires PHP: 8.0.0
*/

namespace Cshp\lr;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

if ( ! function_exists( '\get_plugins' ) ||
	 ! function_exists( '\get_plugin_data' ) ||
	 ! function_exists( '\plugin_dir_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
// load the libraries installed with composer
require 'composer-vendor/autoload.php';
// include file with functions needed to update this plugin
require_once 'inc/updater.php';

/**
 * Try to detect if this script is loading on the Cornershop server.
 * 
 * @return bool True if the hostname is the Cornershop server. False, if the hostname is not the Cornershop server.
 */ 
function is_cornershop_server() {
	$_SERVER['HTTP_HOST'] = gethostname();
	$cshp_co_expected_hostname = 'cshp.co';
	$cshp_dev_expected_hostname = 'cshp.dev';
    $reload = false;
	if ( false !== stripos( $_SERVER['HTTP_HOST'], $cshp_co_expected_hostname ) ) {
		$reload = true;
	} elseif ( false !== stripos( $_SERVER['HTTP_HOST'], $cshp_dev_expected_hostname ) ) {
		$reload = true;
	}

    return $reload;
}

/**
 * Determine if the currently logged-in user has a Cornershop Creative email address
 *
 * @return bool True if the user is a Cornershop employee. False if the user is not using a Cornershop email.
 */
function is_cornershop_user() {
	if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
		$user = wp_get_current_user();
		// Cornershop user?
		if ( '@cshp.co' === substr( $user->user_email, -8 ) || '@cornershopcreative.com' === substr( $user->user_email, -23 ) ) {
			return true;
		}
	}

	return false;
}

function enqueue() {
	if ( ! is_cornershop_server() ) {
		return;
	}

	// defer the script instead of async since we want to wait until all the other scripts are loaded since we
	// need to determine if script is loaded
	wp_register_script( 'cshp-live-reload', get_this_plugin_file_uri( '/assets/js/cshp-live-reload.js' ), [ 'lodash' ], get_version(), [ 'strategy' => 'defer', 'in_footer' => true ] );
	wp_enqueue_script( 'cshp-live-reload' );
	wp_localize_script( 'cshp-live-reload', 'cshp_live_reload', [
		'endpoint' => get_rest_url( null, '/cshp-live-reload/watch' ),
		'reload_hash' => get_reload_file_hash(),
		'css_hash' => get_css_file_uri_hash_array(),
		'js_hash' => get_js_file_uri_hash_array(),
	] );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue', 9999 );

/**
 * Force a script to load using defer or async strategy if the WordPress version is less than 6.3.
 *
 * @see https://stackoverflow.com/a/40553706/1069914
 *
 * @param string $tag The <script> tag that will load
 * @param string $handle The reference ID/handle of the script
 *
 * @return string The script that that will be loaded on the page.
 */
function add_asyncdefer_attribute( $tag, $handle ) {
	global $wp_version;

	// if the WordPress version is less than 6.3 which added core support for defer and async strategy, go old school with this feature to force defer or async
	if ( 'cshp-live-reload' === $handle && version_compare( $wp_version, '6.3.0', '<' ) ) {
		return str_replace( '<script ', '<script defer ', $tag );
		//return str_replace( '<script ', '<script async ', $tag );
	}

	return $tag;
}
add_filter( 'script_loader_tag', __NAMESPACE__ . '\add_asyncdefer_attribute', 10, 2 );

function add_rest_api_endpoint() {
	if ( ! is_cornershop_server() ) {
		return;
	}

	$route_args = [
		[
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\live_reload_endpoint',
			'permission_callback' => '__return_true',
		],
	];

	register_rest_route( 'cshp-live-reload', '/watch', $route_args );
}
add_action( 'rest_api_init', __NAMESPACE__ . '\add_rest_api_endpoint' );

function theme_paths() {
	$theme_paths = [
		'blocksy-child' => [
			'watch_css_path' => [ 'css', 'build' ],
			'watch_js_path' => [ 'js', 'build' ],
		],
		'crate' => [
			'watch_css_path' => [ 'css', 'build' ],
			'watch_js_path' => [ 'js', 'build' ]
		],
	];

	return apply_filters( 'cshp_lr_theme_paths', $theme_paths );
}

function plugin_paths() {
	$plugin_paths = [
		'cshp-accordion' => [
			'watch_css_path' => [ 'css', 'build' ],
			'watch_js_path' => [ 'js', 'build' ],
		],
		'cshp-people' => [
			'watch_css_path' => [ 'css', 'build' ],
			'watch_js_path' => [ 'js', 'build' ],
		],
	];

	return apply_filters( 'cshp_lr_plugin_paths', $plugin_paths );
}

/**
 * Returns an array of themes and plugins folders, the plugin should watch for.
 * This includes CSS and JavaScript build directories for our custom developed themes and plugins.
 *
 * Currently, this function watches for the following themes and plugins:
 * - Theme: blocksy-child (css, js)
 * - Theme: crate (css, js)
 * - Plugin: cshp-accordion (css, js)
 * - Plugin: cshp-people (css, js)
 *
 * @return array An array of themes and plugins each with their respective paths to be watched.
 */
function watch_paths() {
	$paths = [
		'theme' => theme_paths(),
		'plugin' => plugin_paths(),
	];

	return $paths;
}

function create_directory_regex_iterator( $path, $file_type_pattern_regex = '' ) {
	$directory_iterator = new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS );
	$iterator = new \RecursiveIteratorIterator( $directory_iterator, \RecursiveIteratorIterator::SELF_FIRST );
	$iterator->setMaxDepth( 3 );
	$regex_iterator = new \RegexIterator( $iterator, '/node_modules/', \RegexIterator::MATCH, \RegexIterator::INVERT_MATCH ); // exclude node_modules by using the INVERT_MATCH flag

	if ( ! empty( $file_type_pattern_regex ) ) {
		$regex_iterator = new \RegexIterator( $regex_iterator, $file_type_pattern_regex, \RegexIterator::MATCH );
	}

	$regex_iterator->setFlags( \RegexIterator::USE_KEY );
	return $regex_iterator;
}

function get_recursive_css_watch_paths() {
	$watch_paths = watch_paths();
	$paths = [];
	$main_iterator = new \AppendIterator();
	foreach ( $watch_paths as $type => $data ) {
		if ( 'theme' === $type ) {
			foreach ( $watch_paths['theme'] as $theme_name => $watch_path ) {
				if ( ! empty( $watch_path['watch_css_path'] ) ) {
					$theme = wp_get_theme( $theme_name );

					if ( ! empty( $theme ) && $theme instanceof \WP_Theme ) {
						$watch_path = $watch_path['watch_css_path'];

						// if there is just one path supplied instead of an array of paths where css can come from  multiple places, convert to an array
						if ( is_string( $watch_path ) ) {
							$watch_path = [ $watch_path ];
						}

						foreach ( $watch_path as $maybe_path ) {
							$check_path = $theme->get_file_path( $maybe_path );
							$paths[] = $check_path;
						}
					}
				}
			}
		}

		if ( 'plugin' === $type ) {
			foreach ( $watch_paths['plugin'] as $plugin_name => $watch_path ) {
				if ( ! is_plugin_active( $plugin_name ) ) {
					continue;
				}

				if ( ! empty( $watch_path['watch_css_path'] ) ) {
					$watch_path = $watch_path['watch_css_path'];

					// if there is just one path supplied instead of an array of paths where css can come from  multiple places, convert to an array
					if ( is_string( $watch_path ) ) {
						$watch_path = [ $watch_path ];
					}

					foreach ( $watch_path as $maybe_path ) {
						$plugin_watch_path = get_plugin_file_full_path( $plugin_name . '/' . $maybe_path );
						$paths[] = $plugin_watch_path;
					}
				}
			}
		}
	}

	foreach ( $paths as $path ) {
		if ( is_readable( $path ) && ( file_exists( $path ) || is_dir( $path ) ) ) {
			$main_iterator->append( create_directory_regex_iterator( $path, '/\.css$/i' ) );
		}
	}

	return $main_iterator;
}

function get_recursive_js_watch_paths() {
	$watch_paths = watch_paths();
	$paths = [];
	$main_iterator = new \AppendIterator();
	foreach ( $watch_paths as $type => $data ) {
		if ( 'theme' === $type ) {
			foreach ( $watch_paths['theme'] as $theme_name => $watch_path ) {
				if ( isset( $watch_path['watch_js_path'] ) ) {
					$theme = wp_get_theme( $theme_name );

					if ( ! empty( $theme ) && $theme instanceof \WP_Theme ) {
						$watch_path = $watch_path['watch_js_path'];

						// if there is just one path supplied instead of an array of paths where js can come from  multiple places, convert to an array
						if ( is_string( $watch_path ) ) {
							$watch_path = [ $watch_path ];
						}

						foreach ( $watch_path as $maybe_path ) {
							$check_path = $theme->get_file_path( $maybe_path );
							$paths[] = $check_path;
						}
					}
				}
			}
		}

		if ( 'plugin' === $type ) {
			foreach ( $watch_paths['plugin'] as $plugin_name => $watch_path ) {
				if ( ! is_plugin_active( $plugin_name ) ) {
					continue;
				}

				if ( isset( $paths['watch_js_path'] ) ) {
					$watch_path = $watch_path['watch_js_path'];

					// if there is just one path supplied instead of an array of paths where js can come from  multiple places, convert to an array
					if ( is_string( $watch_path ) ) {
						$watch_path = [ $watch_path ];
					}

					foreach ( $watch_path as $maybe_path ) {
						$plugin_watch_path = get_plugin_file_full_path( $plugin_name . '/' . $maybe_path );
						$paths[] = $plugin_watch_path;
					}
				}
			}
		}
	}

	foreach ( $paths as $path ) {
		if ( is_readable( $path ) && ( file_exists( $path ) || is_dir( $path ) ) ) {
			$main_iterator->append( create_directory_regex_iterator( $path, '/\.js$/i' ) );
		}
	}

	return $main_iterator;
}

function get_recursive_php_watch_paths() {
	$watch_paths = watch_paths();
	$paths = [];
	$main_iterator = new \AppendIterator();
	foreach ( $watch_paths as $type => $data ) {
		if ( 'theme' === $type ) {
			foreach ( $watch_paths['theme'] as $theme_name => $watch_path ) {
				$theme = wp_get_theme( $theme_name );

				if ( ! empty( $theme ) && $theme instanceof \WP_Theme ) {
					$paths[] = $theme->get_file_path();
				}
			}
		}

		if ( 'plugin' === $type ) {
			foreach ( $watch_paths['plugin'] as $plugin_name => $watch_path ) {
				if ( ! is_plugin_active( $plugin_name ) ) {
					continue;
				}

				$plugin_watch_path = get_plugin_file_full_path( $plugin_name );
				$paths[] = $plugin_watch_path;
			}
		}
	}

	foreach ( $paths as $path ) {
		if ( is_readable( $path ) && ( file_exists( $path ) || is_dir( $path ) ) ) {
			$main_iterator->append( create_directory_regex_iterator( $path, '/\.(?:php|html)$/i' ) );
		}
	}

	return $main_iterator;
}


/**
 * Iterates over all CSS files in the watch paths and computes a SHA1 hash for each.
 *
 * This function retrieves all CSS files using the `get_recursive_css_watch_paths()`
 * method. For each file, it checks if the file is readable and is a file not a directory.
 * If these conditions are met, it computes a SHA1 hash of the file's content. The computed
 * hash is then stored in an associative array with the file's real path as the
 * key and the hash as the value.
 *
 * @return array An associative array where each key is the real path of a CSS file and each value is a SHA1 hash of the file's content.
 */
function get_css_file_hash_array() {
	foreach ( get_recursive_css_watch_paths() as $file ) {
		if ( ! is_readable( $file->getRealPath() ) || ! $file->isFile() ) {
			continue;
		}

		$hash[$file->getRealPath()] = sha1_file( $file->getRealPath() );
	}

    return $hash;
}

/**
 * Iterates over all JS files in the watch paths and computes a SHA1 hash for each.
 *
 * This function retrieves all JS files using the `get_recursive_js_watch_paths()`
 * method. For each file, it checks if the file is readable and is a file not a directory.
 * If these conditions are met, it computes a SHA1 hash of the file's content. The computed
 * hash is then stored in an associative array with the file's real path as the
 * key and the hash as the value.
 *
 * @return array An associative array where each key is the real path of a JS file and each value is a SHA1 hash of the file's content.
 */
function get_js_file_hash_array() {
	foreach ( get_recursive_js_watch_paths() as $file ) {
		if ( ! is_readable( $file->getRealPath() ) || ! $file->isFile() ) {
			continue;
		}

		$hash[$file->getRealPath()] = sha1_file( $file->getRealPath() );
	}

	return $hash;
}

/**
 * Generates a hash array for all CSS files, with the file URI as the key and the SHA1 hash as the value.
 *
 * This function first retrieves the hash array from the `get_css_file_hash_array` function,
 * where each key is the real path of a CSS file and each value is a SHA1 hash of the file's content.
 *
 * It then iterates over this array, and for each file, it computes the relative path from the theme's root directory or plugin's root,
 * converts this relative path to a URI format using the `get_theme_file_uri` or the `plugin_dir_url` function, and then stores the URI and
 * its corresponding hash to the `$uri_hash` array.
 *
 * @return array An associative array where each key is the URI of a CSS file and each value is a SHA1 hash of the file's content.
 */
function get_css_file_uri_hash_array() {
	$hash = get_css_file_hash_array();
	$uri_hash = [];
	foreach ( $hash as $file_path => $checksum ) {
		if ( str_contains( $file_path, get_theme_root() ) ) {
			$theme_file_path = str_replace_first( get_theme_root() . '/', '', $file_path );
			$parts = explode( DIRECTORY_SEPARATOR, $theme_file_path );
			$theme_name = $parts[0];
			$relative_path = str_replace_first( $theme_name . '/', '', $theme_file_path );
			$theme = wp_get_theme( $theme_name );
			if ( ! empty( $theme ) && $theme instanceof \WP_Theme ) {
				$uri = $theme->get_stylesheet_directory_uri() . '/' . $relative_path;
				$uri_hash[$uri] = $checksum;
			}
		} elseif ( str_contains( $file_path, plugin_dir_path( __DIR__ ) ) ) {
			$uri = plugin_dir_url( $file_path );
			$uri_hash[$uri] = $checksum;
		}
	}

	return $uri_hash;
}

/**
 * Generates a hash array for all JS files, with the file URI as the key and the SHA1 hash as the value.
 *
 * This function first retrieves the hash array from the `get_css_file_hash_array` function,
 * where each key is the real path of a JS file and each value is a SHA1 hash of the file's content.
 *
 * It then iterates over this array, and for each file, it computes the relative path from the theme's root directory or plugin's root,
 * converts this relative path to a URI format using the `get_theme_file_uri` or the `plugin_dir_url` function, and then stores the URI and
 * its corresponding hash to the `$uri_hash` array.
 *
 * @return array An associative array where each key is the URI of a JS file and each value is a SHA1 hash of the file's content.
 */
function get_js_file_uri_hash_array() {
	$hash = get_js_file_hash_array();
	$uri_hash = [];
	foreach ( $hash as $file_path => $checksum ) {
		if ( str_contains( $file_path, get_theme_root() ) ) {
			$theme_file_path = str_replace_first( get_theme_root() . '/', '', $file_path );
			$parts = explode( DIRECTORY_SEPARATOR, $theme_file_path );
			$theme_name = $parts[0];
			$relative_path = str_replace_first( $theme_name . '/', '', $theme_file_path );
			$theme = wp_get_theme( $theme_name );
			if ( ! empty( $theme ) && $theme instanceof \WP_Theme ) {
				$uri = $theme->get_stylesheet_directory_uri() . '/' . $relative_path;
				$uri_hash[$uri] = $checksum;
			}
		} elseif ( str_contains( $file_path, plugin_dir_path( __DIR__ ) ) ) {
			$uri = plugin_dir_url( $file_path );
			$uri_hash[$uri] = $checksum;
		}
	}

	return $uri_hash;
}

/**
 * Generates a SHA1 hash from the file contents of PHP, HTML, and JavaScript files.
 *
 * For each file, it generates a SHA1 hash from the file content and appends it to a string (initially empty).
 * After it iterates through all files, it generates a SHA1 hash from the entire string and returns it.
 *
 * This function skips directories, unreadable files, and files that are not PHP or HTML.
 *
 * @return string A SHA1 hash representing the file contents of PHP and HTML in the watched theme and plugin directories.
 */
function get_reload_file_hash() {
	$reload_hash = '';
    $main_iterator = new \AppendIterator();
	$main_iterator->append( get_recursive_php_watch_paths() );
	foreach ( $main_iterator as $file ) {
		if ( ! is_readable( $file->getRealPath() ) || ! $file->isFile() ) {
			continue;
		}

		$reload_hash .= sha1_file( $file->getRealPath() );
	}

	if ( ! empty( $reload_hash ) ) {
		$reload_hash = sha1( $reload_hash );
	}

    return $reload_hash;
}

function live_reload_endpoint( $request ) {
	header( "X-Accel-Buffering: no" );
	header( "Content-Type: text/event-stream" );
	header( "Cache-Control: no-cache" );

	while (1) {
		// 1 is always true, so repeat the while loop forever (aka event-loop)
		$cur_date = date( DATE_ATOM );
		//echo "event: ping\n",
		//'data: {"time": "' . $curDate . '"}', "\n\n";

		echo format_sse_output( [ 
			'reload_hash' => get_reload_file_hash(),
			'css_hash' => get_css_file_uri_hash_array(), // return the css files as an array of file urls to only reload the css that are included on the page
			'js_hash' => get_js_file_uri_hash_array(), // return the js files as an array of file urls to only reload the js files that are included on the page
			'time' => 'This is a message at time ' . $cur_date
		] );

		// flush the output buffer and send echoed messages to the browser
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		flush();

		// break the loop if the client aborted the connection (closed the page)
		if ( connection_aborted() ) {
			break;
			exit;
		}

		// sleep for 1 second before running the loop again
		sleep(1);
	}
}

function format_sse_output( $output ) {
	$data = $output;

	if ( is_string( $data ) && str_starts_with( $data, 'data: ') ) {
		return $data . PHP_EOL . PHP_EOL;
	} elseif ( is_array( $data ) || is_object( $data ) ) {
		$data = json_encode( $data );

		if ( empty( $data ) ) {
			$data = serialize( $data );
		}

		return sprintf( 'data: %s', $data ) . PHP_EOL . PHP_EOL;
	}

	return $output;
}

/**
 * Replace only the first instance of a substring in a string.
 *
 * @see https://stackoverflow.com/a/2606638
 *
 * @param string $seach String to search for.
 * @param string $replace String to replace with.
 * @param string $subject String to search through.
 *
 * @return string New string with the first instance of the substring replaced.
 */
function str_replace_first( $search, $replace, $subject ) {
	$pos = strpos( $subject, $search );
	if ( false !== $pos ) {
		return substr_replace( $subject, $replace, $pos, strlen( $search ) );
	}

	return $subject;
}

/**
 * Get the plugin's version from the Plugin info docblock so that we don't have to update this in multiple places
 * when the version number is updated.
 *
 * @return int Version of the plugin.
 */
function get_version() {
	$this_plugin = get_plugin_data( __FILE__, false );
	return $this_plugin['Version'] ?? '1.0.0';
}

/**
 * Get the name of this plugin folder
 *
 * @return string Plugin folder name of this plugin.
 */
function get_this_plugin_folder() {
	return basename( dirname( __FILE__ ) );
}

/**
 * Get the name of this main plugin file
 *
 * @return string Name of plugin file without .php extension.
 */
function get_this_plugin_slug() {
	return basename( __FILE__, '.php' );
}

/**
 * Get the URL to a file relative to the root folder of this plugin.
 *
 * @param string $file File to load.
 *
 * @return string URL to the file in the plugin.
 */
function get_this_plugin_file_uri( $file ) {
	$file = ltrim( $file, '/' );

	$url = null;
	if ( empty( $file ) ) {
		$url = plugin_dir_url( __FILE__ );
	} elseif ( file_exists( plugin_dir_path( __FILE__ ) . '/' . $file ) ) {
		$url = plugin_dir_url( __FILE__ ) . $file;
	}

	return $url;
}

/**
 * Get the URL to a file relative to the root folder of the plugin
 *
 * @param string $file File to load.
 *
 * @return string URL to the file in the plugin.
 */
function get_plugin_file_uri( $file ) {
	// remove the beginning full path to the plugin file so we just get the name of the plugin + the file relative to that plugin.
	$plugin_relative_file = str_replace_first( plugin_dir_path( __DIR__ ), '', $file );
	return plugin_dir_url( $plugin_relative_file );
}

/* Get the full path to a wp-content/plugins folder.
 *
 * WordPress has no built-in way to get the full path to a plugins folder.
 *
 * @return string Absolute path to a wp-content/plugins folder.
 */
function get_plugin_folders_path() {
	require_once ABSPATH . '/wp-admin/includes/file.php';
	WP_Filesystem();

	global $wp_filesystem;
	// have to use WP_PLUGIN_DIR even though we should not use the constant directly
	$plugin_directory = WP_PLUGIN_DIR;

	// add a try catch in case the WordPress site is in FTP mode
	// $wp_filesytem->wp_plugins_dir throws errors on FTP FS https://github.com/pods-framework/pods/issues/6242
	try {
		if ( ! empty( $wp_filesystem ) &&
			 is_callable( [ $wp_filesystem, 'wp_plugins_dir' ] ) &&
			 ! empty( $wp_filesystem->wp_plugins_dir() ) ) {
			$plugin_directory = $wp_filesystem->wp_plugins_dir();
		}
	} catch ( \TypeError $error ) {
	}

	return $plugin_directory;
}

function get_plugin_name_from_file( $file ) {
	$remove_plugins_path = str_replace_first( plugin_dir_path( __DIR__ ), '', $file );
	$parts = explode( DIRECTORY_SEPARATOR, $remove_plugins_path );
	$plugin_name = $parts[0];
	return $plugin_name;
}

/**
 * Override WordPress's built-in is_plugin_active function. This new one will determine if a plugin is active using the full path of any file in the plugin's folder.
 *
 * @param string $file Absolute path to the plugin file.
 *
 * @return bool True if the plugin is active and false of not active.
 */
function is_plugin_active( $file ) {
	$plugin_name = get_plugin_name_from_file( $file );
	$active_plugins = get_option( 'active_plugins' );
    foreach ( $active_plugins as $active_plugin ) {
		if ( 0 === strpos( $active_plugin, $plugin_name ) ) {
			return true;
		}
    }
	// use WP core's is_plugin_active function which needs the plugin folder name and the plugin file
	if ( \is_plugin_active( $file ) ) {
		return true;
	}

	return false;
}

/**
 * Get the full path to a plugin file
 *
 * WordPress has no built-in way to get the full path to a plugin's main file when getting a list of plugins from the site.
 *
 * @param string $plugin_folder_name_and_main_file Plugin folder and main file (e.g. cshp-plugin-tracker/cshp-plugin-tracker.php).
 *
 * @return string Absolute path to a plugin file.
 */
function get_plugin_file_full_path( $plugin_folder_name_and_main_file ) {
	// remove the directory separator slash at the end of the plugin folder since we add the director separator explicitly
	$clean_plugin_folder_path = rtrim( get_plugin_folders_path(), DIRECTORY_SEPARATOR );
	return sprintf( '%s%s%s', $clean_plugin_folder_path, DIRECTORY_SEPARATOR, $plugin_folder_name_and_main_file );
}