<?php
/**
 * PHPUnit bootstrap. The parsing and joining code under test is WP-light; the
 * few core helpers it touches are stubbed here. The Google API client is not
 * loaded: tests feed canned row arrays to the pure methods.
 *
 * @package DragonContentDecay
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}


if ( ! function_exists( 'dragon_test_repair_utf8' ) ) {
	/**
	 * Mirrors wp_check_invalid_utf8( $text, true ) over a whole structure:
	 * invalid byte sequences are stripped rather than causing a failure.
	 *
	 * @param mixed $value Value to repair.
	 * @return mixed
	 */
	function dragon_test_repair_utf8( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_string( $key ) ? dragon_test_repair_utf8( $key ) : $key ] = dragon_test_repair_utf8( $item );
			}
			return $out;
		}

		if ( ! is_string( $value ) || '' === $value || 1 === preg_match( '//u', $value ) ) {
			return $value;
		}

		return (string) preg_replace( '/[\x80-\xFF]/', '', $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		/*
		 * Core runs every string through wp_check_invalid_utf8() first, which
		 * REPAIRS invalid UTF-8 rather than refusing to encode it. The result can
		 * therefore name different bytes than the input, and the function
		 * succeeds where plain json_encode() would return false. A stub that just
		 * calls json_encode() hides every bug where a repaired value is then used
		 * as if it were the original.
		 */
		return json_encode( dragon_test_repair_utf8( $data ), $options, $depth );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://www.example.test' . $path;
	}
}

// Minimal filter registry so tests can hook the plugin's own filters.
$GLOBALS['dragoncontentdecay_test_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $priority, $accepted_args );
		$GLOBALS['dragoncontentdecay_test_filters'][ $tag ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( $tag ) {
		unset( $GLOBALS['dragoncontentdecay_test_filters'][ $tag ] );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		foreach ( $GLOBALS['dragoncontentdecay_test_filters'][ $tag ] ?? array() as $callback ) {
			$value = call_user_func( $callback, $value, ...$args );
		}
		return $value;
	}
}

// Transient store. Values persist until deleted, like core without expiry.
$GLOBALS['dragoncontentdecay_test_transients'] = array();

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) {
		return array_key_exists( $name, $GLOBALS['dragoncontentdecay_test_transients'] ) ? $GLOBALS['dragoncontentdecay_test_transients'][ $name ] : false;
	}
}

// Option store. Setting the readonly flag makes update_option a no-op that
// returns false, mimicking a write that never reaches the database.
$GLOBALS['dragoncontentdecay_test_options']          = array();
$GLOBALS['dragoncontentdecay_test_options_readonly'] = false;

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default_value = false ) {
		return array_key_exists( $name, $GLOBALS['dragoncontentdecay_test_options'] ) ? $GLOBALS['dragoncontentdecay_test_options'][ $name ] : $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		unset( $autoload );
		if ( $GLOBALS['dragoncontentdecay_test_options_readonly'] ) {
			return false;
		}
		/*
		 * Core returns false when the stored value is UNCHANGED as well as when
		 * the write fails, which is the entire reason callers verify an option by
		 * reading it back. A stub that always returns true for a stored value
		 * lets a naive "if ( ! update_option() ) fail" pass and makes the
		 * read-back impossible to test.
		 */
		if ( array_key_exists( $name, (array) ( $GLOBALS['dragoncontentdecay_test_options'] ?? array() ) ) && $GLOBALS['dragoncontentdecay_test_options'][ $name ] === $value ) {
			return false;
		}

		$GLOBALS['dragoncontentdecay_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {
		unset( $args );
		return true;
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache() {
		return false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $name, $value, $expiration = 0 ) {
		unset( $expiration );
		$GLOBALS['dragoncontentdecay_test_transients'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['dragoncontentdecay_test_transients'] ) ) {
			return false;
		}
		unset( $GLOBALS['dragoncontentdecay_test_transients'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		unset( $gmt );
		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
	}
}

if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $page_path, $output = OBJECT, $post_type = 'page' ) {
		unset( $page_path, $output, $post_type );
		return null;
	}
}

if ( ! function_exists( 'url_to_postid' ) ) {
	function url_to_postid( $url ) {
		unset( $url );
		return 0;
	}
}

defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );

// ---- i18n / escaping: pass-through translation, real escaping ------------
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		unset( $domain );
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, absint( $decimals ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html( __( $text, $domain ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		// Core: an empty string returns early; anything else goes through ltrim(),
		// so a null URL raises PHP 8.1's "passing null" deprecation just as it
		// does in WordPress.
		if ( '' === $url ) {
			return $url;
		}
		$url = str_replace( ' ', '%20', ltrim( $url ) );
		return str_replace( array( '&', "'" ), array( '&#038;', '&#039;' ), $url );
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) {
		return htmlspecialchars_decode( (string) $text, $quote_style );
	}
}

// ---- Site / user / mail ---------------------------------------------------
// Core stores blogname through esc_html() on save, so get_bloginfo('name')
// hands back an entity-encoded string.
$GLOBALS['dragoncontentdecay_test_blogname']     = 'Rich&#039;s Shop &amp; Co';
$GLOBALS['dragoncontentdecay_test_can_edit']     = false;
$GLOBALS['dragoncontentdecay_test_mail']         = array();

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return 'name' === $show ? $GLOBALS['dragoncontentdecay_test_blogname'] : '';
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://www.example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $post = 0, $context = 'display' ) {
		// Core returns null when the current user may not edit the post, which is
		// always the case in a cron request (no user).
		if ( ! $GLOBALS['dragoncontentdecay_test_can_edit'] ) {
			return null;
		}
		$amp = 'display' === $context ? '&amp;' : '&';
		return admin_url( 'post.php?post=' . (int) $post . $amp . 'action=edit' );
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		$GLOBALS['dragoncontentdecay_test_mail'][] = compact( 'to', 'subject', 'message', 'headers', 'attachments' );
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['dragoncontentdecay_test_options'] ) ) {
			return false;
		}
		unset( $GLOBALS['dragoncontentdecay_test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	// Core's wp_timezone_string(): the named zone when set, else the manual
	// UTC offset as '+HH:MM'.
	function wp_timezone() {
		$name = (string) get_option( 'timezone_string', '' );
		if ( '' !== $name ) {
			return new DateTimeZone( $name );
		}

		$offset  = (float) get_option( 'gmt_offset', 0 );
		$hours   = (int) $offset;
		$minutes = abs( ( $offset - $hours ) * 60 );
		$sign    = $offset < 0 ? '-' : '+';

		return new DateTimeZone( sprintf( '%s%02d:%02d', $sign, abs( $hours ), $minutes ) );
	}
}

if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( $hard = true ) {
		unset( $hard );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		$tz = $timezone instanceof DateTimeZone ? $timezone : wp_timezone();
		return ( new DateTimeImmutable( '@' . ( null === $timestamp ? time() : (int) $timestamp ) ) )->setTimezone( $tz )->format( $format );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme . str_repeat( 'x', 48 );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		unset( $capability, $args );
		return true;
	}
}

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID;

		public function __construct( $id ) {
			$this->ID = (int) $id;
		}
	}
}

$GLOBALS['dragoncontentdecay_test_settings_errors'] = array();

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		unset( $action );
		return 'valid' === $nonce ? 1 : false;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		// Core also strips tags, folds whitespace and removes %XX octets.
		$filtered = (string) $str;
		if ( str_contains( $filtered, '<' ) ) {
			$filtered = strip_tags( $filtered );
		}
		$filtered = trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $filtered ) );
		while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
			$filtered = str_replace( $match[0], '', $filtered );
		}
		return $filtered;
	}
}

if ( ! function_exists( 'add_settings_error' ) ) {
	function add_settings_error( $setting, $code, $message, $type = 'error' ) {
		$GLOBALS['dragoncontentdecay_test_settings_errors'][] = compact( 'setting', 'code', 'message', 'type' );
	}
}

// ---- WP_Error ---------------------------------------------------------------
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;

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
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// ---- WP-Cron: a store keyed like core's cron array --------------------------
$GLOBALS['dragoncontentdecay_test_cron'] = array();

if ( ! function_exists( 'wp_get_schedules' ) ) {
	function wp_get_schedules() {
		$core = array(
			'hourly'     => array( 'interval' => HOUR_IN_SECONDS, 'display' => 'Once Hourly' ),
			'twicedaily' => array( 'interval' => 12 * HOUR_IN_SECONDS, 'display' => 'Twice Daily' ),
			'daily'      => array( 'interval' => DAY_IN_SECONDS, 'display' => 'Once Daily' ),
			'weekly'     => array( 'interval' => 7 * DAY_IN_SECONDS, 'display' => 'Once Weekly' ),
		);
		return array_merge( (array) apply_filters( 'cron_schedules', array() ), $core );
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
		if ( ! is_numeric( $timestamp ) || $timestamp <= 0 ) {
			return $wp_error ? new WP_Error( 'invalid_timestamp', 'Event timestamp must be a valid Unix timestamp.' ) : false;
		}
		$schedules = wp_get_schedules();
		if ( ! isset( $schedules[ $recurrence ] ) ) {
			return $wp_error ? new WP_Error( 'invalid_schedule', 'Event schedule does not exist.' ) : false;
		}
		$GLOBALS['dragoncontentdecay_test_cron'][ (int) $timestamp ][ $hook ][ md5( serialize( $args ) ) ] = array(
			'schedule' => $recurrence,
			'args'     => $args,
			'interval' => $schedules[ $recurrence ]['interval'],
		);
		ksort( $GLOBALS['dragoncontentdecay_test_cron'] );
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		$key = md5( serialize( $args ) );
		foreach ( $GLOBALS['dragoncontentdecay_test_cron'] as $timestamp => $hooks ) {
			if ( isset( $hooks[ $hook ][ $key ] ) ) {
				return $timestamp;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_get_schedule' ) ) {
	function wp_get_schedule( $hook, $args = array() ) {
		$key = md5( serialize( $args ) );
		foreach ( $GLOBALS['dragoncontentdecay_test_cron'] as $hooks ) {
			if ( isset( $hooks[ $hook ][ $key ] ) ) {
				return $hooks[ $hook ][ $key ]['schedule'];
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array() ) {
		$key     = md5( serialize( $args ) );
		$cleared = 0;
		foreach ( $GLOBALS['dragoncontentdecay_test_cron'] as $timestamp => $hooks ) {
			if ( isset( $hooks[ $hook ][ $key ] ) ) {
				unset( $GLOBALS['dragoncontentdecay_test_cron'][ $timestamp ][ $hook ][ $key ] );
				if ( empty( $GLOBALS['dragoncontentdecay_test_cron'][ $timestamp ][ $hook ] ) ) {
					unset( $GLOBALS['dragoncontentdecay_test_cron'][ $timestamp ][ $hook ] );
				}
				if ( empty( $GLOBALS['dragoncontentdecay_test_cron'][ $timestamp ] ) ) {
					unset( $GLOBALS['dragoncontentdecay_test_cron'][ $timestamp ] );
				}
				++$cleared;
			}
		}
		return $cleared;
	}
}

/**
 * Forget every stored transient (as if they had all expired).
 */
function dragoncontentdecay_test_reset_transients(): void {
	$GLOBALS['dragoncontentdecay_test_transients'] = array();
}

/**
 * Reset every stub store between tests.
 */
function dragoncontentdecay_test_reset(): void {
	$GLOBALS['dragoncontentdecay_test_options']          = array();
	$GLOBALS['dragoncontentdecay_test_options_readonly'] = false;
	$GLOBALS['dragoncontentdecay_test_transients']       = array();
	$GLOBALS['dragoncontentdecay_test_filters']          = array();
	$GLOBALS['dragoncontentdecay_test_cron']             = array();
	$GLOBALS['dragoncontentdecay_test_mail']             = array();
	$GLOBALS['dragoncontentdecay_test_can_edit']         = false;
	$GLOBALS['dragoncontentdecay_test_blogname']         = 'Rich&#039;s Shop &amp; Co';
}


require_once __DIR__ . '/../includes/class-crypto.php';
require_once __DIR__ . '/../includes/class-oauth.php';
require_once __DIR__ . '/../includes/class-api-ga4.php';
require_once __DIR__ . '/../includes/class-api-gsc.php';
require_once __DIR__ . '/../includes/class-analyzer.php';
require_once __DIR__ . '/../includes/class-scheduler.php';
require_once __DIR__ . '/../includes/class-notifications.php';
require_once __DIR__ . '/../includes/class-admin.php';
require_once __DIR__ . '/AnalyzerTestSupport.php';
