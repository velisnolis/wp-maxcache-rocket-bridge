<?php
/**
 * Minimal WordPress stubs.
 *
 * The plugin only touches a small, well-defined slice of the WordPress API, so
 * instead of booting a full WordPress test install we reimplement exactly that
 * slice. Test state lives in WMRB_Test_State and must be reset between tests.
 */

class WMRB_Test_State {
	/**
	 * @var array<string,mixed>
	 */
	public static $options = array();

	/**
	 * Queued wp_remote_get() results, consumed in order. When the queue runs
	 * out, self::$default_remote_response is returned instead.
	 *
	 * @var array<int,mixed>
	 */
	public static $remote_queue = array();

	/**
	 * @var mixed
	 */
	public static $default_remote_response = array( 'response' => array( 'code' => 200 ) );

	/**
	 * Every URL passed to wp_remote_get(), in call order.
	 *
	 * @var array<int,string>
	 */
	public static $remote_calls = array();

	/**
	 * Registered filters, keyed by hook name.
	 *
	 * @var array<string,array<int,callable>>
	 */
	public static $filters = array();

	/**
	 * Registered actions, keyed by hook name.
	 *
	 * @var array<string,array<int,callable>>
	 */
	public static $actions = array();

	/**
	 * Runs on every wp_remote_get(), so a test can simulate another process
	 * touching the filesystem while a request is in flight.
	 *
	 * @var callable|null
	 */
	public static $on_remote_get = null;

	/**
	 * How many times update_option() was called, keyed by option name.
	 *
	 * @var array<string,int>
	 */
	public static $option_writes = array();

	/** @var array<int,string> */
	public static $redirects = array();

	/** @var array<int,string> */
	public static $checked_nonces = array();

	public static function reset() {
		self::$options                 = array();
		self::$remote_queue            = array();
		self::$remote_calls            = array();
		self::$filters                 = array();
		self::$actions                 = array();
		self::$option_writes           = array();
		self::$redirects               = array();
		self::$checked_nonces          = array();
		self::$now                     = 1767268800;
		self::$on_remote_get           = null;
		self::$default_remote_response = array( 'response' => array( 'code' => 200 ) );
	}

	/**
	 * Controllable clock. Real timestamps have second resolution, which lets a
	 * test pass just because two calls landed in the same second.
	 *
	 * @var int
	 */
	public static $now = 1767268800; // 2026-01-01 12:00:00 UTC

	public static function writes( $option ) {
		return isset( self::$option_writes[ $option ] ) ? self::$option_writes[ $option ] : 0;
	}

	public static function advance_clock( $seconds ) {
		self::$now += (int) $seconds;
	}

	/**
	 * @param int|string  $code HTTP status code, or 'error' for a transport failure.
	 * @param string|null $body Response body. Null means "whatever a healthy
	 *                          site would return", filled in by wp_remote_get().
	 */
	public static function queue_response( $code, $body = null ) {
		if ( 'error' === $code ) {
			self::$remote_queue[] = new WP_Error( 'http_request_failed', 'Connection refused' );
			return;
		}

		self::$remote_queue[] = array(
			'response' => array( 'code' => (int) $code ),
			'body'     => $body,
		);
	}
}

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_option( $key, $default_value = false ) {
	return array_key_exists( $key, WMRB_Test_State::$options ) ? WMRB_Test_State::$options[ $key ] : $default_value;
}

function update_option( $key, $value, $autoload = null ) {
	unset( $autoload );

	if ( ! isset( WMRB_Test_State::$option_writes[ $key ] ) ) {
		WMRB_Test_State::$option_writes[ $key ] = 0;
	}
	++WMRB_Test_State::$option_writes[ $key ];

	WMRB_Test_State::$options[ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( WMRB_Test_State::$options[ $key ] );
	return true;
}

function wp_parse_args( $args, $defaults = array() ) {
	if ( ! is_array( $args ) ) {
		$args = array();
	}

	return array_merge( $defaults, $args );
}

function __( $text, $domain = null ) {
	unset( $domain );
	return $text;
}

function esc_html__( $text, $domain = null ) {
	unset( $domain );
	return $text;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function current_time( $type = 'mysql' ) {
	unset( $type );
	return gmdate( 'Y-m-d H:i:s', WMRB_Test_State::$now );
}

function trailingslashit( $string ) {
	return rtrim( (string) $string, '/\\' ) . '/';
}

function wp_mkdir_p( $target ) {
	return is_dir( $target ) || mkdir( $target, 0777, true );
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $priority, $accepted_args );
	WMRB_Test_State::$actions[ $hook ][] = $callback;
	return true;
}

function current_user_can( $capability ) {
	return 'manage_options' === $capability;
}

function check_admin_referer( $action ) {
	WMRB_Test_State::$checked_nonces[] = (string) $action;
	return true;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function wp_safe_redirect( $location ) {
	WMRB_Test_State::$redirects[] = (string) $location;
	return true;
}

function get_current_user_id() {
	return 1;
}

function wp_list_pluck( $list, $field ) {
	return array_map( static function ( $item ) use ( $field ) {
		return is_array( $item ) && array_key_exists( $field, $item ) ? $item[ $field ] : null;
	}, (array) $list );
}

function esc_url( $url ) {
	return (string) $url;
}

function wp_nonce_field( $action ) {
	echo '<input type="hidden" name="_wpnonce" value="nonce-' . esc_html( $action ) . '" />';
}

function checked( $checked ) {
	if ( $checked ) {
		echo 'checked="checked"';
	}
}

function submit_button( $text, $type = 'primary', $name = 'submit', $wrap = true ) {
	$button = '<input type="submit" name="' . esc_html( $name ) . '" class="button button-' . esc_html( $type ) . '" value="' . esc_html( $text ) . '" />';
	echo $wrap ? '<p class="submit">' . $button . '</p>' : $button;
}

function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $priority, $accepted_args );
	WMRB_Test_State::$filters[ $hook ][] = $callback;
	return true;
}

function apply_filters( $hook, $value ) {
	$args = array_slice( func_get_args(), 2 );

	foreach ( WMRB_Test_State::$filters[ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}

	return $value;
}

function wp_remote_get( $url, $args = array() ) {
	unset( $args );
	WMRB_Test_State::$remote_calls[] = (string) $url;

	if ( is_callable( WMRB_Test_State::$on_remote_get ) ) {
		call_user_func( WMRB_Test_State::$on_remote_get );
	}

	$response = ! empty( WMRB_Test_State::$remote_queue )
		? array_shift( WMRB_Test_State::$remote_queue )
		: WMRB_Test_State::$default_remote_response;

	// A healthy site echoes the probe token back; fill that in unless the test
	// deliberately queued a different body (a CDN error page, say).
	if ( is_array( $response ) && ! isset( $response['body'] ) || ( is_array( $response ) && null === $response['body'] ) ) {
		$body = '<html>a perfectly ordinary page</html>';
		if ( preg_match( '/wmrb_probe=([A-Za-z0-9]+)/', (string) $url, $m ) ) {
			$body = 'WMRB_PROBE_OK:' . $m[1];
		}
		$response['body'] = $body;
	}

	return $response;
}

function wp_remote_retrieve_body( $response ) {
	if ( ! is_array( $response ) || ! isset( $response['body'] ) ) {
		return '';
	}

	return (string) $response['body'];
}

function set_transient( $key, $value, $expiration = 0 ) {
	unset( $expiration );
	WMRB_Test_State::$options[ '_transient_' . $key ] = $value;
	return true;
}

function get_transient( $key ) {
	return WMRB_Test_State::$options[ '_transient_' . $key ] ?? false;
}

function delete_transient( $key ) {
	unset( WMRB_Test_State::$options[ '_transient_' . $key ] );
	return true;
}

function plugin_basename( $file ) {
	return 'wp-maxcache-rocket-bridge/' . basename( (string) $file );
}

function wpautop( $text ) {
	return '<p>' . (string) $text . '</p>';
}

function wp_kses_post( $text ) {
	return (string) $text;
}

function sanitize_text_field( $str ) {
	return trim( preg_replace( '/[\r\n\t\x00-\x1F]/', '', (string) $str ) );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function nocache_headers() {
	// No-op: headers are not observable in these tests.
}

function wp_generate_password( $length = 12, $special_chars = true ) {
	unset( $special_chars );
	return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
}

function wp_remote_retrieve_response_code( $response ) {
	if ( ! is_array( $response ) || ! isset( $response['response']['code'] ) ) {
		return '';
	}

	return $response['response']['code'];
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}
