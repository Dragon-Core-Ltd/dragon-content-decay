<?php
/**
 * Core stand-ins for the request-handling tests, each a mirror of the
 * WordPress core function it replaces and defined only when nothing else has
 * defined it. Where core ends the request (wp_die(), a redirect followed by
 * exit, wp_send_json_*()), the stand-in throws instead.
 *
 * Required by the WporgScan tests at load time, so they exist for the whole
 * suite (PHPUnit loads every test file before it runs one).
 *
 * @package DragonContentDecay
 */

// phpcs:disable WordPress.Security, WordPress.WP.GlobalVariablesOverride, WordPress.WP.AlternativeFunctions, Universal.Files.SeparateFunctionsFromOO, Generic.Files.OneObjectStructurePerFile

// The views are loaded from the real plugin directory.
defined( 'DRAGONCONTENTDECAY_PLUGIN_DIR' ) || define( 'DRAGONCONTENTDECAY_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

/**
 * Thrown by the wp_die() stand-in: core prints the message and ends the request.
 */
class DragonContentDecay_Test_Die extends RuntimeException {

	/**
	 * HTTP status core would send.
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * Build the exception.
	 *
	 * @param string $message Message passed to wp_die().
	 * @param int    $status  Response code.
	 */
	public function __construct( string $message, int $status ) {
		parent::__construct( $message );
		$this->status = $status;
	}
}

/**
 * Thrown by the redirect stand-ins, standing for the handler's exit.
 */
class DragonContentDecay_Test_Redirect extends RuntimeException {

	/**
	 * Redirect target.
	 *
	 * @var string
	 */
	public string $location;

	/**
	 * Whether wp_safe_redirect() (true) or wp_redirect() (false) was used.
	 *
	 * @var bool
	 */
	public bool $safe;

	/**
	 * Build the exception.
	 *
	 * @param string $location Redirect target.
	 * @param bool   $safe     Whether the safe variant was called.
	 */
	public function __construct( string $location, bool $safe ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
		$this->safe     = $safe;
	}
}

/**
 * Thrown by the wp_send_json_*() stand-ins: core prints JSON and ends the request.
 */
class DragonContentDecay_Test_Json extends RuntimeException {

	/**
	 * Whether wp_send_json_success() was called.
	 *
	 * @var bool
	 */
	public bool $success;

	/**
	 * The data sent.
	 *
	 * @var mixed
	 */
	public $data;

	/**
	 * Build the exception.
	 *
	 * @param bool  $success Success flag.
	 * @param mixed $data    Data.
	 */
	public function __construct( bool $success, $data ) {
		parent::__construct( 'json' );
		$this->success = $success;
		$this->data    = $data;
	}
}

defined( 'DRAGONCONTENTDECAY_PLUGIN_URL' ) || define( 'DRAGONCONTENTDECAY_PLUGIN_URL', 'https://www.example.test/wp-content/plugins/dragon-content-decay/' );
defined( 'DRAGONCONTENTDECAY_VERSION' ) || define( 'DRAGONCONTENTDECAY_VERSION', '0.0.0-test' );

// Styles and scripts enqueued: handle => src.
$GLOBALS['dragoncontentdecay_test_styles']  = array();
$GLOBALS['dragoncontentdecay_test_scripts'] = array();

// The logged-in user's ID.
$GLOBALS['dragoncontentdecay_test_user_id'] = 1;
// Public post types get_post_types() reports: name => label.
$GLOBALS['dragoncontentdecay_test_post_types'] = array(
	'post'       => 'Posts',
	'page'       => 'Pages',
	'attachment' => 'Media',
);

if ( ! function_exists( 'map_deep' ) ) {
	/**
	 * Core: applies the callback to every non-array, non-object leaf, keeping keys.
	 *
	 * @param mixed    $value    Value.
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	function map_deep( $value, $callback ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $index => $item ) {
				$value[ $index ] = map_deep( $item, $callback );
			}
		} elseif ( is_object( $value ) ) {
			$object_vars = get_object_vars( $value );
			foreach ( $object_vars as $property_name => $property_value ) {
				$value->$property_name = map_deep( $property_value, $callback );
			}
		} else {
			$value = call_user_func( $callback, $value );
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Core: runs the callbacks hooked to the action. Nothing is hooked here.
	 *
	 * @param string $hook_name Action.
	 * @param mixed  ...$args   Arguments.
	 */
	function do_action( $hook_name, ...$args ) {
		unset( $hook_name, $args );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Core: the current user's ID, or 0 when nobody is logged in.
	 *
	 * @return int
	 */
	function get_current_user_id() {
		return (int) $GLOBALS['dragoncontentdecay_test_user_id'];
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * Core: a random string of the given length; letters and digits only when
	 * both character-class flags are off.
	 *
	 * @param int  $length              Length.
	 * @param bool $special_chars       Include !@#$%^&*().
	 * @param bool $extra_special_chars Include further symbols.
	 * @return string
	 */
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		if ( $special_chars ) {
			$chars .= '!@#$%^&*()';
		}
		if ( $extra_special_chars ) {
			$chars .= '-_ []{}<>~`+=,.;:/?|';
		}
		$password = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$password .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $password;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Core prints the message and ends the request; this throws instead. The
	 * response code is the third argument's 'response' key, or the argument
	 * itself when it is an integer.
	 *
	 * @param string|int $message Message.
	 * @param string|int $title   Title.
	 * @param array|int  $args    Arguments (response code).
	 * @throws DragonContentDecay_Test_Die Always.
	 */
	function wp_die( $message = '', $title = '', $args = array() ) {
		if ( is_int( $args ) ) {
			$status = $args;
		} elseif ( is_int( $title ) ) {
			$status = $title;
		} else {
			$status = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 500;
		}
		throw new DragonContentDecay_Test_Die( (string) $message, $status );
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	/**
	 * Core: verifies $_REQUEST[ $query_arg ] (falling back to _ajax_nonce, then
	 * _wpnonce) and ends the request with -1 and a 403 when it does not verify.
	 *
	 * @param string|int   $action    Action.
	 * @param string|false $query_arg Field name.
	 * @param bool         $stop      Whether to end the request on failure.
	 * @return int|false
	 */
	function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
		$nonce = '';
		if ( $query_arg && isset( $_REQUEST[ $query_arg ] ) ) {
			$nonce = $_REQUEST[ $query_arg ];
		} elseif ( isset( $_REQUEST['_ajax_nonce'] ) ) {
			$nonce = $_REQUEST['_ajax_nonce'];
		} elseif ( isset( $_REQUEST['_wpnonce'] ) ) {
			$nonce = $_REQUEST['_wpnonce'];
		}

		$result = wp_verify_nonce( $nonce, $action );
		do_action( 'check_ajax_referer', $action, $result );

		if ( $stop && false === $result ) {
			wp_die( -1, 403 );
		}

		return $result;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	/**
	 * Core sends a Location header; the handler then exits, which this throw stands for.
	 *
	 * @param string $location Target.
	 * @param int    $status   Status.
	 * @throws DragonContentDecay_Test_Redirect Always.
	 */
	function wp_safe_redirect( $location, $status = 302 ) {
		unset( $status );
		throw new DragonContentDecay_Test_Redirect( (string) $location, true );
	}
}

if ( ! function_exists( 'wp_redirect' ) ) {
	/**
	 * Core sends a Location header; the handler then exits, which this throw stands for.
	 *
	 * @param string $location Target.
	 * @param int    $status   Status.
	 * @throws DragonContentDecay_Test_Redirect Always.
	 */
	function wp_redirect( $location, $status = 302 ) {
		unset( $status );
		throw new DragonContentDecay_Test_Redirect( (string) $location, false );
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	/**
	 * Core prints {"success":true,"data":...} and ends the request.
	 *
	 * @param mixed $value Data.
	 * @throws DragonContentDecay_Test_Json Always.
	 */
	function wp_send_json_success( $value = null ) {
		throw new DragonContentDecay_Test_Json( true, $value );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	/**
	 * Core prints {"success":false,"data":...} and ends the request.
	 *
	 * @param mixed $value Data.
	 * @throws DragonContentDecay_Test_Json Always.
	 */
	function wp_send_json_error( $value = null ) {
		throw new DragonContentDecay_Test_Json( false, $value );
	}
}

// ---- Template functions the settings view calls -----------------------------

if ( ! function_exists( 'get_post_types' ) ) {
	/**
	 * Core: the registered post types matching the arguments, as objects.
	 *
	 * @param array  $args   Arguments.
	 * @param string $output 'names' or 'objects'.
	 * @return array
	 */
	function get_post_types( $args = array(), $output = 'names' ) {
		unset( $args );
		$types = array();
		foreach ( $GLOBALS['dragoncontentdecay_test_post_types'] as $name => $label ) {
			$types[ $name ] = 'objects' === $output ? (object) compact( 'name', 'label' ) : $name;
		}
		return $types;
	}
}

if ( ! function_exists( 'settings_errors' ) ) {
	/**
	 * Core: prints the notices registered with add_settings_error() for the setting.
	 *
	 * @param string $setting Setting slug.
	 */
	function settings_errors( $setting = '' ) {
		foreach ( $GLOBALS['dragoncontentdecay_test_settings_errors'] as $error ) {
			if ( '' === $setting || $setting === $error['setting'] ) {
				printf( "<div id='setting-error-%s' class='notice notice-%s'><p><strong>%s</strong></p></div>\n", esc_attr( $error['code'] ), esc_attr( $error['type'] ), $error['message'] );
			}
		}
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	/**
	 * Core: prints the hidden nonce field.
	 *
	 * @param string|int $action Action.
	 * @param string     $name   Field name.
	 */
	function wp_nonce_field( $action = -1, $name = '_wpnonce' ) {
		printf( '<input type="hidden" id="%1$s" name="%1$s" value="%2$s" />', esc_attr( $name ), esc_attr( 'nonce-for-' . $action ) );
	}
}

if ( ! function_exists( 'wp_nonce_url' ) ) {
	/**
	 * Core: the URL with the nonce added as a query argument, HTML-escaped.
	 *
	 * @param string     $actionurl URL.
	 * @param string|int $action    Action.
	 * @param string     $name      Argument name.
	 * @return string
	 */
	function wp_nonce_url( $actionurl, $action = -1, $name = '_wpnonce' ) {
		$actionurl = str_replace( '&amp;', '&', $actionurl );
		return esc_html( $actionurl . ( str_contains( $actionurl, '?' ) ? '&' : '?' ) . $name . '=nonce-for-' . $action );
	}
}

if ( ! function_exists( 'checked' ) ) {
	/**
	 * Core: prints the checked attribute when the two values are equal as strings.
	 *
	 * @param mixed $checked Value.
	 * @param mixed $current Comparison.
	 * @param bool  $display Whether to print.
	 * @return string
	 */
	function checked( $checked, $current = true, $display = true ) {
		$result = (string) $checked === (string) $current ? " checked='checked'" : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'selected' ) ) {
	/**
	 * Core: prints the selected attribute when the two values are equal as strings.
	 *
	 * @param mixed $selected Value.
	 * @param mixed $current  Comparison.
	 * @param bool  $display  Whether to print.
	 * @return string
	 */
	function selected( $selected, $current = true, $display = true ) {
		$result = (string) $selected === (string) $current ? " selected='selected'" : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	/**
	 * Core: prints a submit button.
	 *
	 * @param string $text Label.
	 */
	function submit_button( $text = '' ) {
		printf( '<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="%s" /></p>', esc_attr( $text ) );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	/**
	 * Core: prints the translated text escaped for an attribute.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function esc_attr_e( $text, $domain = 'default' ) {
		echo esc_attr( __( $text, $domain ) );
	}
}

// ---- Assets and screen ------------------------------------------------------

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/**
	 * Core: registers and enqueues a stylesheet.
	 *
	 * @param string $handle Handle.
	 * @param string $src    URL.
	 * @param array  $deps   Dependencies.
	 * @param mixed  $ver    Version.
	 */
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
		unset( $ver );
		$GLOBALS['dragoncontentdecay_test_styles'][ $handle ] = compact( 'src', 'deps' );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	/**
	 * Core: registers and enqueues a script.
	 *
	 * @param string $handle Handle.
	 * @param string $src    URL.
	 * @param array  $deps   Dependencies.
	 * @param mixed  $ver    Version.
	 * @param mixed  $args   Loading strategy or footer flag.
	 */
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
		unset( $ver, $args );
		$GLOBALS['dragoncontentdecay_test_scripts'][ $handle ] = compact( 'src', 'deps' );
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	/**
	 * Core: attaches data to a registered script; false when the handle is unknown.
	 *
	 * @param string $handle      Handle.
	 * @param string $object_name JS variable.
	 * @param array  $l10n        Data.
	 * @return bool
	 */
	function wp_localize_script( $handle, $object_name, $l10n ) {
		if ( ! isset( $GLOBALS['dragoncontentdecay_test_scripts'][ $handle ] ) ) {
			return false;
		}
		$GLOBALS['dragoncontentdecay_test_scripts'][ $handle ]['data'][ $object_name ] = $l10n;
		return true;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Core: a nonce tied to the action and the current user.
	 *
	 * @param string|int $action Action.
	 * @return string
	 */
	function wp_create_nonce( $action = -1 ) {
		return $GLOBALS['dragoncontentdecay_test_nonces'][ (string) $action ] ?? 'valid';
	}
}
