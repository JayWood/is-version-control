<?php

/*
Plugin Name:	Is Version Controlled
Plugin URI:		http://www.plugish.com/
Description: 	If themes/plugins are version controlled, this plugin removes them from update checks if they are in the filter provided.
Author: 		Jerry Wood Jr.
Version:		0.1.0
Author URI:		http://plugish.com
Text Domain:    is-version-controlled
*/

class Is_Version_Controlled {

	/**
	 * Current version number
	 * @var   string
	 * @since 1.5
	 */
	const VERSION = '0.1.0';

	/**
	 * Single insteance of this class
	 *
	 * @var null|Is_Version_Controlled
	 */
	protected static $single_instance = null;

	protected function __construct() {
		// Setup some base variables for the plugin
		$this->basename       = plugin_basename( __FILE__ );
		$this->directory_path = plugin_dir_path( __FILE__ );
		$this->directory_url  = plugins_url( dirname( $this->basename ) );

		load_plugin_textdomain( 'is-version-controlled', false, dirname( $this->basename ) . '/languages' );
	}

	/**
	 * WordPress Hooks
	 * @since 0.1.0
	 */
	public function hooks() {
		add_filter( 'http_request_args', array( $this, 'stop_updates' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( $this, 'version_control_text' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'remove_update_row' ) );
	}

	/**
	 * Removes the 'update' row notification
	 *
	 * Wordpress stores the per-row notifications in a 'update_plugins' transient, so if someone
	 * activates this plugin and there are already plugins that need updated, site owners will still
	 * be able to see that.  This filter takes care of that issue.
	 */
	public function remove_update_row() {
		$plugins = apply_filters( 'ivc_plugins', array() );
		foreach ( $plugins as $plugin_file ) {
			remove_action( "after_plugin_row_$plugin_file", 'wp_plugin_update_row', 10 );
		}
	}

	public function version_control_text( $plugin_meta = array(), $plugin_file = '', $plugin_data = array() ) {

		$plugins = apply_filters( 'ivc_plugins', array() );
		if ( empty( $plugins ) || ! is_array( $plugin_meta ) || ! in_array( $plugin_file, $plugins ) ) {
			return $plugin_meta;
		}

		$version_control_string = __( 'This plugin is under version control', 'is-version-controlled' );
		$is_under_version_control = apply_filters( 'ivc_message_string', sprintf( '<b>%s</b>', $version_control_string ), $plugin_file, $plugin_data );

		if ( isset( $plugin_data['Version'] ) ) {
			// If version string is set, then overwrite it
			$wordpress_version_string = sprintf( __( 'Version %s' ), $plugin_data['Version'] );
			foreach ( $plugin_meta as $key => $value ) {
				if ( $value == $wordpress_version_string ) {
					$plugin_meta[ $key ] = $is_under_version_control;
					break;
				}
			}
		} else {
			// Version string isn't set, so we know the data isn't available, we overwrite it anyhow.
			$plugin_meta = array_unshift( $plugin_meta, $is_under_version_control );
		}

		return $plugin_meta;
	}

	/**
	 * Stops updates for specific files
	 */
	public function stop_updates( $request, $url ) {
		if ( empty( $url ) ) {
			return $request;
		}

		if ( $this->is_plugin( $url ) ) {
			$request = $this->remove_plugins( $request );
		} elseif ( $this->is_theme( $url ) ) {
			$request = $this->remove_themes( $request );
		}

		return $request;
	}

	/**
	 * Removes plugins form the http request array.
	 * @param $request
	 *
	 * @return mixed
	 */
	private function remove_plugins( $request ) {

		$plugins = apply_filters( 'ivc_plugins', array() );
		$plugins_checked = $this->get_plugin_data( $request );
		if ( ! $plugins_checked || empty( $plugins ) || ! is_array( $plugins ) ) {
			return $request;
		}

		foreach ( $plugins_checked['plugins'] as $plugin_path => $plugin_data ) {
			// We only care about theme slug.
			if ( in_array( $plugin_path, $plugins ) ) {
				unset( $plugins_checked['plugins'][ $plugin_path ] );
			}
		}

		$request['body']['plugins'] = json_encode( $plugins_checked );

		return $request;
	}

	/**
	 * Removes themes from the http request array.
	 * @param $request
	 *
	 * @return mixed
	 */
	private function remove_themes( $request ) {

		$themes  = apply_filters( 'ivc_themes', array() );
		$themes_checked = $this->get_theme_data( $request );
		if ( ! $themes_checked || empty( $themes ) || ! is_array( $themes ) ) {
			return $request;
		}

		// We must have the themes array key, if not return.
		if ( ! isset( $themes_checked['themes'] ) ) {
			return $request;
		}

		foreach ( $themes_checked['themes'] as $theme_slug => $theme_data ) {
			// We only care about theme slug.
			if ( in_array( $theme_slug, $themes ) ) {
				unset( $themes_checked['themes'][ $theme_slug ] );
			}
		}

		$request['body']['themes'] = json_encode( $themes_checked );

		return $request;
	}

	/**
	 * Grabs the decoded plugins data
	 * @param $request
	 *
	 * @return bool|object False on failure, plugins object otherwise
	 */
	private function get_plugin_data( $request ) {
		if ( ! isset( $request['body'] ) || ! isset( $request['body']['plugins'] ) ) {
			return false;
		}

		$plugins = json_decode( $request['body']['plugins'], true );
		return empty( $plugins ) ? false : $plugins;
	}

	/**
	 * Grabs the decoded themes data
	 * @param $request
	 *
	 * @return bool|object False on failure, plugins object otherwise
	 */
	private function get_theme_data( $request ) {
		if ( ! isset( $request['body'] ) || ! isset( $request['body']['themes'] ) ) {
			return false;
		}

		$themes = json_decode( $request['body']['themes'], true );
		return empty( $themes ) ? false : $themes;
	}

	/**
	 * Checks if the URL provided is to plugin updates
	 * @param $url
	 *
	 * @return bool
	 */
	private function is_plugin( $url ) {
		return 0 === strpos( $url, 'https://api.wordpress.org/plugins/update-check/' ) ? true : false;
	}

	/**
	 * Checks if the URL is to theme updates.
	 * @param $url
	 *
	 * @return bool
	 */
	private function is_theme( $url ) {
		return 0 == strpos( $url, 'http://api.wordpress.org/themes/update-check' ) ? true : false;
	}

	/**
	 * Creates or returns an instance of this class.
	 * @since  0.1.0
	 * @return Is_Version_Controlled A single instance of this class.
	 */
	public static function get_instance() {
		if ( null === self::$single_instance ) {
			self::$single_instance = new self();
		}

		return self::$single_instance;
	}
}

function is_version_controlled() {
	return Is_Version_Controlled::get_instance();
}

is_version_controlled()->hooks();

/**
 * Overwrites the default message for Version Control
 * @param string $message The Default Message
 * @param string $plugin_file The filepath to the plugin, ie. akismet/akismet.php
 * @param array $plugin_data Plugin data array, such as version, name, etc....
 */
function overwrite_message( $message, $plugin_file, $plugin_data ) {
	return 'Do not touch my plugin!';
}
add_filter( 'ivc_message_string', 'overwrite_message', 10, 3 );
