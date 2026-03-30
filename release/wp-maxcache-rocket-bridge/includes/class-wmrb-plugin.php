<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMRB_Plugin {
	const OPTION_KEY = 'wmrb_options';

	/**
	 * @var WMRB_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var array<string,mixed>
	 */
	private $options = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->options = wp_parse_args( get_option( self::OPTION_KEY, array() ), self::default_options() );

		$diagnostics    = new WMRB_Diagnostics_Service( $this->options );
		$snippet        = new WMRB_Snippet_Service( $this->options );
		$sync_manager   = new WMRB_Sync_Manager( $snippet, $this->options );
		$quick_test     = new WMRB_Quick_Test_Service();
		$purge_observer = new WMRB_Purge_Observer( $this->options );

		new WMRB_Admin_Page( $diagnostics, $snippet, $quick_test, $purge_observer, $sync_manager, $this->options );

		register_activation_hook( WMRB_PLUGIN_FILE, array( __CLASS__, 'on_activation' ) );
	}

	public static function on_activation() {
		$stored = get_option( self::OPTION_KEY, array() );
		$merged = wp_parse_args( is_array( $stored ) ? $stored : array(), self::default_options() );
		update_option( self::OPTION_KEY, $merged );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function default_options() {
		return array(
			'bridge_enabled'              => true,
			'debug_mode'                  => false,
			'auto_sync_enabled'           => true,
			'auto_apply_htaccess'         => true,
			'serve_gzip_variant'          => false,
			'serve_webp_variant'          => false,
			'custom_cache_path_template'  => '',
		);
	}
}
