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

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) {
		unset( $name );
		return false;
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
		unset( $name, $value, $expiration );
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $name ) {
		unset( $name );
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

require_once __DIR__ . '/../includes/class-crypto.php';
require_once __DIR__ . '/../includes/class-oauth.php';
require_once __DIR__ . '/../includes/class-api-ga4.php';
require_once __DIR__ . '/../includes/class-api-gsc.php';
require_once __DIR__ . '/../includes/class-analyzer.php';
require_once __DIR__ . '/../includes/class-scheduler.php';
require_once __DIR__ . '/AnalyzerTestSupport.php';
