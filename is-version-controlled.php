<?php

/*
Plugin Name:	Is Version Controlled
Plugin URI:		http://www.plugish.com/
Description: 	If themes/plugins are version controlled, this plugin removes them from update checks if they are in the filter provided.
Author: 		Jerry Wood Jr.
Version:		0.1.1
Author URI:		http://plugish.com
Text Domain:    is-version-controlled
*/

class Is_Version_Controlled {

	/**
	 * Current version number
	 * @var   string
	 * @since 0.1.0
	 */
	const VERSION = '0.1.1';

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

		add_action( 'admin_init', array( $this, 'remove_update_row' ), 10 );
		add_action( 'admin_init', array( $this, 'override_update_row' ), 11 );

		add_filter( 'http_request_args', array( $this, 'prevent_wporg_send' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( $this, 'override_plugin_row_meta' ), 10, 3 );
		add_filter( 'plugins_api_result', array( $this, 'remove_plugin_update_button' ), 10, 3 );
		add_filter( 'wp_prepare_themes_for_js', array( $this, 'override_theme_update_notification' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'remove_plugins_from_transient' ) );
		add_filter( 'site_transient_update_themes', array( $this, 'remove_themes_from_transient' ) );
	}

	/**
	 * Remove themes from transient on update-core screen
	 *
	 * @param $transient
	 *
	 * @return mixed
	 */
	public function remove_themes_from_transient( $transient ) {
		$screen = get_current_screen();
		if ( ! isset( $screen->base ) || 'update-core' !== $screen->base || ! isset( $transient->response ) ) {
			return $transient;
		}

		$private_themes = $this->get_themes( 'private' );
		$themes         = $this->get_themes();
		if ( ! empty( $private_themes ) ) {
			$plugins = array_merge( $private_themes, $themes );

		}

		$response = $transient->response;
		foreach ( $response as $file_path => $plugin_data ) {
			if ( in_array( $file_path, $themes ) ) {
				unset( $response[ $file_path ] );
			}
		}

		$transient->response = $response;

		return $transient;
	}

	/**
	 * Overrides the site transient to remove plugins from the update table.
	 *
	 * @since 0.1.1
	 *
	 * @param $transient
	 *
	 * @return mixed
	 */
	public function remove_plugins_from_transient( $transient ) {
		$screen = get_current_screen();
		if ( ! isset( $screen->base ) || 'update-core' !== $screen->base || ! isset( $transient->response ) ) {
			return $transient;
		}

		$private_plugins = $this->get_plugins( 'private' );
		$plugins         = $this->get_plugins();
		if ( ! empty( $private_plugins ) ) {
			$plugins = array_merge( $private_plugins, $plugins );

		}

		$response = $transient->response;
		foreach ( $response as $file_path => $plugin_data ) {
			if ( in_array( $file_path, $plugins ) ) {
				unset( $response[ $file_path ] );
			}
		}

		// Now re-assign transient data
		$transient->response = $response;

		return $transient;
	}

	/**
	 * Filters the "Update Plugin" button from the popover.
	 *
	 * @since 0.1.1
	 *
	 * @param $result
	 * @param $action
	 * @param $args
	 *
	 * @return mixed
	 */
	public function remove_plugin_update_button( $result, $action, $args ) {
		if ( ! isset( $args->slug ) ) {
			return $result;
		}

		$plugins = $this->get_plugins();
		$file    = $this->get_plugin_path_by_slug( $args->slug );
		if ( in_array( $file, $plugins ) ) {
			// If this is our plugin, we make sure to remove the download link.
			unset( $result->download_link );
		}

		return $result;
	}

	/**
	 * Grabs the file path of a plugin by its slug.
	 *
	 * @since 0.1.1
	 *
	 * @param $slug
	 *
	 * @return bool|string
	 */
	private function get_plugin_path_by_slug( $slug ) {
		$api     = get_site_transient( 'update_plugins' );
		$plugins = $api->no_update;
		if ( isset( $api->response ) ) {
			$plugins = array_merge( $api->response, $plugins );
		}

		foreach ( $plugins as $file_path => $data ) {
			if ( ! isset( $data->slug ) ) {
				continue;
			}

			if ( $slug === $data->slug ) {
				return $file_path;
			}
		}

		return false;
	}

	/**
	 * Overrides update text for public VC'd plugins if update is available
	 *
	 * @since 0.1.0
	 */
	public function override_update_row() {
		$plugins = $this->get_plugins();
		if ( ! empty( $plugins ) ) {
			foreach ( $plugins as $plugin_file ) {
				remove_action( "after_plugin_row_{$plugin_file}", 'wp_plugin_update_row', 10 );
				add_action( "after_plugin_row_{$plugin_file}", array(
					$this,
					'override_plugin_update_notification_row'
				), 10, 2 );
			}
		}

		$themes = $this->get_themes();
		if ( ! empty( $themes ) ) {
			foreach ( $themes as $theme ) {
				remove_action( "after_theme_row_{$theme}", 'wp_theme_update_row', 10 );
				add_action( "after_theme_row_{$theme}", array(
					$this,
					'override_theme_update_notification_row'
				), 10, 2 );
			}
		}
	}

	/**
	 * Overrides the localized data before it's sent to the backbone script
	 *
	 * @param $themes
	 *
	 * @return mixed
	 */
	public function override_theme_update_notification( $themes ) {
		if ( empty( $themes ) ) {
			return $themes;
		}

		$our_themes = $this->get_themes();
		foreach ( $themes as $slug => $theme_data ) {
			if ( in_array( $slug, $our_themes ) ) {
				// The theme is ivc
				if ( isset( $theme_data['update'] ) && ! empty( $theme_data['update'] ) ) {
					$themes[ $slug ]['update'] = $this->get_theme_update_msg( $slug );
				}
			}
		}

		return $themes;
	}

	protected function get_theme_update_msg( $theme ) {
		static $themes_update = null;

		$theme = wp_get_theme( $theme );

		if ( ! current_user_can( 'update_themes' ) ) {
			return false;
		}

		if ( ! isset( $themes_update ) ) {
			$themes_update = get_site_transient( 'update_themes' );
		}

		if ( ! ( $theme instanceof WP_Theme ) ) {
			return false;
		}

		$stylesheet = $theme->get_stylesheet();

		$html = '';

		if ( isset( $themes_update->response[ $stylesheet ] ) ) {
			$update      = $themes_update->response[ $stylesheet ];
			$theme_name  = $theme->display( 'Name' );
			$details_url = add_query_arg( array(
				'TB_iframe' => 'true',
				'width'     => 1024,
				'height'    => 800,
			), $update['url'] ); //Theme browser inside WP? replace this, Also, theme preview JS will override this on the available list.

			if ( ! is_multisite() ) {
				$html = sprintf( '<p><strong>' . __( 'There is a new version of %1$s available. <a href="%2$s" class="thickbox" title="%3$s">View version %4$s details</a>.' ) . '</strong></p>', $theme_name, esc_url( $details_url ), esc_attr( $theme['Name'] ), $update['new_version'] );
			}
		}

		return $html;
	}


	/**
	 * Overrides Theme update notification bar
	 *
	 * The majority of this method IS core, it's just been cleaned up
	 *
	 * @param $theme_key
	 * @param $theme
	 */
	public function override_theme_update_notification_row( $theme_key, $theme ) {
		$current = get_site_transient( 'update_themes' );
		if ( ! isset( $current->response[ $theme_key ] ) ) {
			return;
		}
		$r = $current->response[ $theme_key ];

		$details_url = add_query_arg( array(
			'TB_iframe' => 'true',
			'width'     => 1024,
			'height'    => 800,
		), $current->response[ $theme_key ]['url'] );

		$wp_list_table = _get_list_table( 'WP_MS_Themes_List_Table' );

		echo '<tr class="plugin-update-tr"><td colspan="' . $wp_list_table->get_column_count() . '" class="plugin-update colspanchange"><div class="update-message">';
		printf( __( 'There is a new version of %1$s available. <a href="%2$s" class="thickbox" title="%3$s">View version %4$s details</a>.' ), $theme['Name'], esc_url( $details_url ), esc_attr( $theme['Name'] ), $r->new_version );
		do_action( "in_theme_update_message-{$theme_key}", $theme, $r );
		echo '</div></td></tr>';
	}

	/**
	 * Overrides version text for plugin updates
	 *
	 * The majority of this function IS core, but has been cleaned up.
	 *
	 * @see wp_plugin_update_row()
	 *
	 * @since 0.1.0
	 *
	 * @param $file
	 * @param $plugin_data
	 *
	 * @return bool
	 */
	public function override_plugin_update_notification_row( $file, $plugin_data ) {

		$current = get_site_transient( 'update_plugins' );
		if ( ! isset( $current->response[ $file ] ) ) {
			return;
		}

		$r = $current->response[ $file ];

		$plugins_allowedtags = array(
			'a'       => array( 'href' => array(), 'title' => array() ),
			'abbr'    => array( 'title' => array() ),
			'acronym' => array( 'title' => array() ),
			'code'    => array(),
			'em'      => array(),
			'strong'  => array(),
		);

		$plugin_name = wp_kses( $plugin_data['Name'], $plugins_allowedtags );

		$details_url = self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $r->slug . '&section=changelog&TB_iframe=true&width=600&height=800' );

		$wp_list_table = _get_list_table( 'WP_Plugins_List_Table' );

		if ( is_network_admin() || ! is_multisite() ) {
			if ( is_network_admin() ) {
				$active_class = is_plugin_active_for_network( $file ) ? ' active' : '';
			} else {
				$active_class = is_plugin_active( $file ) ? ' active' : '';
			}

			echo '<tr class="plugin-update-tr' . $active_class . '" id="' . esc_attr( $r->slug . '-update' ) . '" data-slug="' . esc_attr( $r->slug ) . '" data-plugin="' . esc_attr( $file ) . '"><td colspan="' . esc_attr( $wp_list_table->get_column_count() ) . '" class="plugin-update colspanchange"><div class="update-message">';

			printf( __( 'There is a new version of %1$s available. <a href="%2$s" class="thickbox" title="%3$s">View version %4$s details</a>.' ), $plugin_name, esc_url( $details_url ), esc_attr( $plugin_name ), $r->new_version );

			do_action( "in_plugin_update_message-{$file}", $plugin_data, $r );

			echo '</div></td></tr>';
		}
	}

	/**
	 * Helper function to get plugin array
	 *
	 * Saves from typing apply_filters() all over the place
	 *
	 * @since 0.1.1
	 * @return mixed|void
	 */
	public function get_plugins( $private = false ) {
		$private = $private ? 'private_' : false;

		$plugins = apply_filters( "ivc_{$private}plugins", array() );

		return array_unique( $plugins );
	}

	/**
	 * Helper function to get theme array
	 *
	 * Sves from typing apply_filters() all over the place
	 *
	 * @since 0.1.1
	 * @return mixed|void
	 */
	public function get_themes( $private = false ) {
		$private = $private ? 'private_' : false;

		$themes = apply_filters( "ivc_{$private}themes", array() );

		return array_unique( $themes ); // No duplicates
	}

	/**
	 * Removes the 'update' row notification
	 *
	 * Removes this from private plugins only, which use the ivc_private_plugins filter
	 *
	 * @since 0.1.0
	 */
	public function remove_update_row() {
		$plugins = $this->get_plugins( 'private' );
		foreach ( $plugins as $plugin_file ) {
			remove_action( "after_plugin_row_$plugin_file", 'wp_plugin_update_row', 10 );
		}
	}

	/**
	 * Changes the version control text for specific plugin files
	 *
	 * @since 0.1.1
	 *
	 * @param array $plugin_meta
	 * @param string $plugin_file
	 * @param array $plugin_data
	 *
	 * @return array|int
	 */
	public function override_plugin_row_meta( $plugin_meta = array(), $plugin_file = '', $plugin_data = array() ) {

		// Grab both private, and non-private plugins
		$private_plugins = $this->get_plugins( 'private' );
		$vc_plugins      = $this->get_plugins();

		// Merge both arrays
		$plugins = array_merge( $private_plugins, $vc_plugins );
		// Make sure nothing fancy is going on, no need for duplicates.
		$plugins = array_unique( $plugins );

		if ( empty( $plugins ) || ! is_array( $plugin_meta ) || ! in_array( $plugin_file, $plugins ) ) {
			return $plugin_meta;
		}

		$version_control_string   = __( 'This plugin is under version control', 'is-version-controlled' );
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
	 * Prevents sending data to wp.org about the plugin
	 *
	 * By removing data from the request URL, this prevents
	 * update checks for themes and plugins.
	 *
	 * @since 0.1.0
	 */
	public function prevent_wporg_send( $request, $url ) {
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
	 *
	 * @since 0.1.0
	 *
	 * @param $request
	 *
	 * @return mixed
	 */
	private function remove_plugins( $request ) {

		$plugins         = $this->get_plugins( 'private' );
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
	 *
	 * @since 0.1.0
	 *
	 * @param $request
	 *
	 * @return mixed
	 */
	private function remove_themes( $request ) {

		$themes         = $this->get_themes( 'private' );
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
	 *
	 * @since 0.1.0
	 *
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
	 *
	 * @since 0.1.0
	 *
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
	 *
	 * @since 0.1.0
	 *
	 * @param $url
	 *
	 * @return bool
	 */
	private function is_plugin( $url ) {
		return 0 === strpos( $url, 'https://api.wordpress.org/plugins/update-check/' ) ? true : false;
	}

	/**
	 * Checks if the URL is to theme updates.
	 *
	 * @since 0.1.0
	 *
	 * @param $url
	 *
	 * @return bool
	 */
	private function is_theme( $url ) {
		return 0 == strpos( $url, 'http://api.wordpress.org/themes/update-check' ) ? true : false;
	}

	/**
	 * Creates or returns an instance of this class.
	 *
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
 *
 * @param string $message The Default Message
 * @param string $plugin_file The filepath to the plugin, ie. akismet/akismet.php
 * @param array $plugin_data Plugin data array, such as version, name, etc....
 *
 * @return string
 */
function overwrite_message( $message, $plugin_file, $plugin_data ) {
	return 'Do not touch my plugin!';
}

//add_filter( 'ivc_message_string', 'overwrite_message', 10, 3 );

function remove_akismet( $filters ) {
	$filters[] = 'akismet/akismet.php';

	return $filters;
}

//add_filter( 'ivc_plugins', 'remove_akismet' );

function remove_twentyfifteen( $filters ) {
	$filters[] = 'twentyfifteen';

	return $filters;
}

//add_filter( 'ivc_themes', 'remove_twentyfifteen' );
