# Cornershop Live Reload

"Cornershop Live Reload" is a WordPress plugin developed to assist in theme development. It monitors changes in your CSS, JavaScript, and PHP files, and reloads the website whenever any of these files are updated.

## Key Features
* __Live Reload:__ The plugin makes developing and testing your theme easier by automatically refreshing your website whenever you save changes to your CSS, JS or PHP files. This automatic reload helps developers instantly see the impact of their changes without needing to manually refresh the browser. When a CSS file is updated, the new CSS file is applied without reloading the browser. When a JavaScript or PHP file is changed, the browser is reloaded.

* __Compatibility:__ This plugin is tested and confirmed to work well on a Cornershop D24 server and Kinsta.

* __Targeted Monitoring:__ This plugin is also written to work specifically with `crate` and `blocksy-child` themes. It also works with known Cornershop created plugins `cshp-accordion` and `cshp-people`. Add compatibility for other plugins and themes by using the filters `cshp_lr_theme_paths` and `cshp_lr_plugin_paths`.

* __Efficient Replacement for Browsersync:__ It serves as an efficient replacement for Gulp's Browsersync live reload feature for frontend developments.

Please note that this plugin only helps during **development** and is not intended for use on a live production site.

* __Uses Server-Sent Events (SSEs):__  Instead of using AJAX-Polling that constantly makes an AJAX request to the server using a combination of `setInterval` and `setTimeout`, Server-Sent Events keep a persistent connection with the server. This has the advantage of not making multiple AJAX requests to the server and flooding the Network requests Developer Tools in your browser. Only one persistent connection will display. Read more about the technical differences in these links:
	* [https://medium.com/geekculture/ajax-polling-vs-long-polling-vs-websockets-vs-server-sent-events-e0d65033c9ba](https://medium.com/geekculture/ajax-polling-vs-long-polling-vs-websockets-vs-server-sent-events-e0d65033c9ba)
	* [https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events](https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events)
	* [https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events](https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events)

* __Does live rely on a build tool such as Webpack, Vite, Rollup, etc...:__ This plugin is build tool agnostic and does not require you to use any build tools at all. Just simply change a file in the CSS or JS watch path or change a PHP file in a supported theme or plugin. It just works.

__NOTE__: This plugin requires the webserver to support TCP HTTTP/1.1 for long running server events. This has plugin has only been tested on NGINX servers.

```bash
# add this to the active nginx.conf file

# NOTE: This is automatically loading in the http context of the Nginx conf file since that's how the nginx.conf works. See the file /etc/nginx/nginx.conf.
# setup a TCP HTTP/1.1 connections that are persisent by default
# Do this create a Long running server sent Events for polling/long polling
# see https://serverfault.com/a/801629
proxy_http_version 1.1;
proxy_set_header Connection "";
proxy_buffering off;
```

## Getting Started
* After installing the plugin, it starts working immediately. There's no need for further configurations as the plugin automatically detects and monitors changes in the relevant (PHP, JS, CSS) files for the built-in supported themes (currently: crate and blocksy-child).
* You must be logged-in as a WordPress Cornershop user on the website (i.e. an email address that ends in @cornershopcreative.com). If you are not logged into the website, the page will notb reload when the assets are built.

## Adding support for other themes or plugins.
If there is a theme that you want to add support for, there is a filter to add custom support. Add this to the theme `functions.php` file
```php
// add functionality for custom theme
function cshp_live_reload_theme_support( $theme_paths ) {
    // add the theme folder name as an associative array
    // add the CSS watch path(s) to as the key 'watch_css_path'. If there are multiple paths, add as an array with the relative path folders as the values.
    // If there is just one folder, add the path as a string
    // add the CSS watch path(s) to as the key 'watch_js_path'.
    // If there are multiple paths, add as an array with the relative path folders as the values. If there is just one folder, add the path as a string.
	return array_merge( $theme_paths, [ 
		'friends-of-the-earth' => [ 
			'watch_css_path' => [ 'assets', 'assets/css'], 
			'watch_js_path' => 'assets/js' 
		]
	] );
}
add_filter( 'cshp_lr_theme_paths', 'cshp_live_reload_theme_support' );
```

```php
// add functionality for custom plugin
function cshp_live_reload_plugin_support( $plugin_paths ) {
    // add the plugin folder name as an associative array
    // add the CSS watch path(s) to as the key 'watch_css_path'.
    // If there are multiple paths, add as an array with the relative path folders as the values. If there is just one folder, add the path as a string
    // add the CSS watch path(s) to as the key 'watch_js_path'.
    // If there are multiple paths, add as an array with the relative path folders as the values. If there is just one folder, add the path as a string.
	return array_merge( $plugin_paths, [ 
		'insert-plugin-folder-name' => [ 
			'watch_css_path' => [ 'assets', 'assets/css'], 
			'watch_js_path' => 'assets/js' 
		]
	] );
}
add_filter( 'cshp_lr_plugin_paths', 'cshp_live_reload_plugin_support' );
```

## Things to know
* This plugin is **only** meant for **active** development or support work. If you are not using the plugin during development or you are not currently doing support on the website, this plugin should **NOT** be active.
* This plugin does not currently work with must-use plugins and does not currently work with plugins that are not in a subdirectory of the plugins folder. The plugin needs to be in its own folder and cannot just be a plugin file sitting at the root of the plugins folder. Since most plugins are not built this way, this should not be an issue.
* This plugin will only reload the CSS files that are loaded on the current page. If you are using some third-party plugin that combines/concatenates all CSS files into a single CSS file (e.g. WP Rocket or Autoptimizer), the CSS file will not reload. You need to disable that plugin for this to work.
* This will only reload CSS and JS on the frontend of the website. It will not reload those files in the admin.
* This plugin will only reload the JS files that are loaded on the current page. If you are using some third-party plugin that combines/concatenates all JS files into a single JS file (e.g. WP Rocket or Autoptimizer), the JS file will not reload. You need to disable that plugin for this to work.
* This plugin will only reload CSS and JS files from plugins that are currently active on the website.

## FAQs
* How is this different from the [Live Auto Refresh plugin](https://wordpress.org/plugins/live-auto-refresh/)?
	* That plugin has a few issues:
		* It uses AJAX polling to make requests every few seconds instead of SSE, which floods the browser tools Network log with requests.
		* It stores the md5_hash of the theme file in the database and changes the hash in the database when a file is updated. This plugin does not store the changed files in the database and does not make any database calls (yet - this could change if we introduce some settings in the admin).
		* It only works with the current theme files and does not work with plugins as well.
		* It only works with changed PHP files and does not reload when JS and CSS files are changed.
* Why does this plugin use sha1_hash instead of md5_hash or filemtime?
  * During testing, sha1_hash was faster than md5_hash and much faster than comparing the modified file time.
