<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMRB_Purge_Observer {
	const LOG_OPTION = 'wmrb_purge_log';

	/**
	 * @var array<string,mixed>
	 */
	private $options;

	/**
	 * @param array<string,mixed> $options
	 */
	public function __construct( array $options ) {
		$this->options = $options;
		$this->register_hooks();
	}

	private function register_hooks() {
		$hooks = array(
			'after_rocket_clean_domain',
			'after_rocket_clean_post',
			'after_rocket_clean_terms',
			'after_rocket_clean_files',
			'rocket_after_clean_domain',
		);

		foreach ( $hooks as $hook ) {
			add_action( $hook, function () use ( $hook ) {
				$this->record_event( $hook );
			}, 10, 0 );
		}
	}

	private function record_event( $hook ) {
		if ( empty( $this->options['debug_mode'] ) ) {
			return;
		}

		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'time' => current_time( 'mysql' ),
			'hook' => $hook,
		);

		$limit = 200;
		if ( count( $log ) > $limit ) {
			$log = array_slice( $log, -$limit );
		}

		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	public function get_log() {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	public function clear_log() {
		delete_option( self::LOG_OPTION );
	}
}
