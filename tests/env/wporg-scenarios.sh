#!/usr/bin/env bash
# Real-HTTP scenarios for every request path of Dragon Content Decay: the
# settings form, the Google connect / disconnect links, the OAuth callback,
# the manual sync AJAX action and the screens that read GET parameters.
#
# Usage: tests/env/wporg-scenarios.sh <wp-env dir> [port]
# Runs against a throwaway wp-env built from copies of the plugin. Touches only
# Dragon Content Decay data (its options, transients and score rows), one
# subscriber and two posts it creates and deletes. Options are put back at the
# end. No Google credentials exist, so the Google steps are asserted up to the
# outbound call.

set -u -o pipefail

ENV_DIR="${1:?usage: wporg-scenarios.sh <wp-env dir> [port]}"
ENV_DIR="$(cd "$ENV_DIR" && pwd)"
PORT="${2:-$(php -r '$c = json_decode( file_get_contents( $argv[1] ), true ); echo $c["port"] ?? 8888;' "$ENV_DIR/.wp-env.json")}"
BASE="http://localhost:$PORT"
WORKSPACE=/Users/rich/Sites/wp-plugins
TMP="$(mktemp -d "${TMPDIR:-/tmp}/dcdwporg.XXXXXX")"
ADMIN_JAR="$TMP/admin.jar"
SUB_JAR="$TMP/sub.jar"
SUB_USER=dcdwporg_sub
SUB_PASS='Dcd-wporg-sub-1!'
RUN="run$(date +%s)$$"
SETTINGS="tools.php?page=dragon-content-decay&tab=settings"
MASK='••••••••'

PASS=0
FAIL=0

pass() { PASS=$((PASS + 1)); printf 'PASS %s\n' "$*"; }
fail() { FAIL=$((FAIL + 1)); printf 'FAIL %s\n' "$*"; }
check() { if [ "$1" = "$2" ]; then pass "$3"; else fail "$3 (expected [$2], got [$1])"; fi; }
contains() { if grep -qF -- "$2" <<<"$1"; then pass "$3"; else fail "$3 (missing [$2])"; fi; }
lacks() { if grep -qF -- "$2" <<<"$1"; then fail "$3 (found [$2])"; else pass "$3"; fi; }

# --- WP-CLI ------------------------------------------------------------------

ENV_NAME="$(basename "$ENV_DIR")"
CLI="$(docker ps --format '{{.Names}}' | grep -E "^wp-env-${ENV_NAME}-[0-9a-f]+-cli-1\$" | head -1)"

wp_cli() {
	if [ -n "$CLI" ]; then
		docker exec -u www-data "$CLI" wp --path=/var/www/html "$@" 2>/dev/null < /dev/null
	else
		( cd "$ENV_DIR" && NODE_OPTIONS="--require $WORKSPACE/force-ipv4.js" \
			"$WORKSPACE/node_modules/.bin/wp-env" run cli wp "$@" 2>/dev/null < /dev/null )
	fi
}

# `wp eval` with the PHP on stdin.
wp_php() { wp_cli eval "$(cat)"; }

# --- HTTP --------------------------------------------------------------------

login() {
	local jar="$1" user="$2" pass="$3"
	rm -f "$jar"
	curl -s -o /dev/null -c "$jar" "$BASE/wp-login.php"
	curl -s -o /dev/null -b "$jar" -c "$jar" \
		--data-urlencode "log=$user" --data-urlencode "pwd=$pass" \
		--data-urlencode 'wp-submit=Log In' --data-urlencode "redirect_to=$BASE/wp-admin/" \
		--data-urlencode 'testcookie=1' "$BASE/wp-login.php"
	grep -q 'wordpress_logged_in_' "$jar"
}

# Request a wp-admin path; sets STATUS and LOCATION, leaves the body in $TMP/body.
STATUS=''
LOCATION=''
req() {
	local jar="$1" path="$2"
	shift 2
	curl -s --max-time 90 -b "$jar" -o "$TMP/body" -D "$TMP/headers" "$@" "$BASE/wp-admin/$path"
	STATUS="$(head -1 "$TMP/headers" | awk '{print $2}')"
	LOCATION="$(grep -i '^location:' "$TMP/headers" | tr -d '\r' | sed 's/^[Ll]ocation: //' | head -1)"
}

body() { cat "$TMP/body"; }

# The logged_in cookie value from a jar, URL-decoded the way PHP fills $_COOKIE.
logged_in_cookie() {
	local raw
	raw="$(grep 'wordpress_logged_in_' "$1" | awk -F'\t' '{print $7}' | head -1)"
	php -r 'echo urldecode( $argv[1] );' "$raw"
}

# A nonce for an action, minted for the session in the given jar.
mint_nonce() {
	local jar="$1" action="$2" cookie
	cookie="$(logged_in_cookie "$jar")"
	wp_php <<PHP
\$cookie = '$cookie';
\$_COOKIE[ LOGGED_IN_COOKIE ] = \$cookie;
\$user = wp_validate_auth_cookie( \$cookie, 'logged_in' );
wp_set_current_user( (int) \$user );
echo wp_create_nonce( '$action' );
PHP
}

# The settings-form nonce, scraped from the rendered form.
settings_nonce() {
	req "$ADMIN_JAR" "$SETTINGS"
	grep -o 'name="dragoncontentdecay_settings_nonce" value="[^"]*"' "$TMP/body" | sed 's/.*value="//; s/"$//' | head -1
}

# The connect / disconnect link nonce, scraped from the rendered settings page.
oauth_link_nonce() {
	req "$ADMIN_JAR" "$SETTINGS"
	grep -o "action=$1[^\"]*_wpnonce=[0-9a-f]*" "$TMP/body" | sed 's/.*_wpnonce=//' | head -1
}

# The AJAX nonce the admin script is handed.
ajax_nonce() {
	req "$ADMIN_JAR" 'tools.php?page=dragon-content-decay'
	grep -o 'var dcdAdmin = {[^;]*' "$TMP/body" | grep -o '"nonce":"[0-9a-f]*"' | sed 's/"nonce":"//; s/"$//' | head -1
}

# POST the settings form: jar, then curl data arguments.
save_form() {
	local jar="$1"
	shift
	req "$jar" "$SETTINGS" "$@"
}

# --- plugin state ------------------------------------------------------------

opt() {
	wp_php <<PHP
\$v = get_option( '$1', '<unset>' );
echo is_scalar( \$v ) ? (string) \$v : wp_json_encode( \$v );
PHP
}

# Every plugin option as one comparable string (secrets by decrypted value).
state() { wp_php <<'PHP'
global $wpdb;
wp_cache_flush();
$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'dragoncontentdecay\\_%' ORDER BY option_name", ARRAY_A );
$out  = array();
foreach ( $rows as $row ) {
	$value = $row['option_value'];
	if ( in_array( $row['option_name'], array( 'dragoncontentdecay_google_client_secret', 'dragoncontentdecay_google_tokens' ), true ) ) {
		$plain = \DragonContentDecay\Crypto::decrypt( (string) $value );
		$value = null === $plain ? $value : $plain;
	}
	$out[ $row['option_name'] ] = $value;
}
echo md5( wp_json_encode( $out ) );
PHP
}

secret_b64() { wp_php <<'PHP'
wp_cache_flush();
echo base64_encode( \DragonContentDecay\OAuth::get_client_secret() );
PHP
}

b64() { printf '%s' "$1" | base64 | tr -d '\n'; }

state_transient() {
	wp_php <<PHP
\$v = get_transient( 'dragoncontentdecay_oauth_state_$1' );
echo false === \$v ? '<none>' : \$v;
PHP
}

plant_tokens() { wp_php <<'PHP'
update_option(
	'dragoncontentdecay_google_tokens',
	\DragonContentDecay\Crypto::encrypt(
		wp_json_encode(
			array(
				'access_token'  => 'fake-access-token',
				'refresh_token' => 'fake-refresh-token',
				'expires_in'    => 3600,
				'created'       => time(),
			)
		)
	)
);
update_option( 'dragoncontentdecay_google_granted_scopes', 'https://www.googleapis.com/auth/analytics.readonly', false );
echo 'planted';
PHP
}

tokens_json() { wp_php <<'PHP'
wp_cache_flush();
$stored = (string) get_option( 'dragoncontentdecay_google_tokens', '' );
echo '' === $stored ? '<none>' : (string) \DragonContentDecay\Crypto::decrypt( $stored );
PHP
}

snapshot() { wp_php <<'PHP'
global $wpdb;
$rows = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'dragoncontentdecay\\_%'", ARRAY_A );
echo base64_encode( wp_json_encode( $rows ) );
PHP
}

restore() {
	wp_php <<PHP
global \$wpdb;
\$rows = json_decode( base64_decode( '$1' ), true );
\$wpdb->query( "DELETE FROM {\$wpdb->options} WHERE option_name LIKE 'dragoncontentdecay\\\\_%'" );
foreach ( (array) \$rows as \$row ) {
	\$wpdb->insert( \$wpdb->options, \$row );
}
\$wpdb->query( "DELETE FROM {\$wpdb->options} WHERE option_name LIKE '\\\\_transient\\\\_dragoncontentdecay\\\\_%' OR option_name LIKE '\\\\_transient\\\\_timeout\\\\_dragoncontentdecay\\\\_%'" );
wp_cache_flush();
\DragonContentDecay\Notifications::sync_digest_schedule();
echo 'restored';
PHP
}

debug_size() { wp_php <<'PHP'
$f = WP_CONTENT_DIR . '/debug.log';
clearstatcache();
echo file_exists( $f ) ? filesize( $f ) : 0;
PHP
}

debug_since() {
	wp_php <<PHP
\$f = WP_CONTENT_DIR . '/debug.log';
if ( file_exists( \$f ) ) {
	echo (string) file_get_contents( \$f, false, null, $1 );
}
PHP
}

POST_LOW=''
POST_HIGH=''
SNAPSHOT=''

cleanup() {
	if [ -n "$POST_LOW" ]; then
		wp_php <<PHP >/dev/null
global \$wpdb;
\$wpdb->query( "DELETE FROM {\$wpdb->prefix}dcd_scores WHERE post_id IN ( $POST_LOW, $POST_HIGH )" );
wp_delete_post( $POST_LOW, true );
wp_delete_post( $POST_HIGH, true );
PHP
	fi
	wp_php <<'PHP' >/dev/null
delete_option( 'dcd_ga4_property_id' );
PHP
	if [ -n "$SNAPSHOT" ]; then
		restore "$SNAPSHOT" >/dev/null
	fi
	rm -rf "$TMP"
}
trap cleanup EXIT

# --- setup -------------------------------------------------------------------

SNAPSHOT="$(snapshot)"
[ -n "$SNAPSHOT" ] || { echo 'FAIL could not read the plugin options (is the env up?)'; exit 1; }

if ! wp_cli user get "$SUB_USER" --field=ID >/dev/null; then
	wp_cli user create "$SUB_USER" "$SUB_USER@example.test" --role=subscriber --user_pass="$SUB_PASS" >/dev/null
else
	wp_cli user update "$SUB_USER" --user_pass="$SUB_PASS" --role=subscriber >/dev/null
fi
SUB_ID="$(wp_cli user get "$SUB_USER" --field=ID)"
ADMIN_ID="$(wp_cli user get admin --field=ID)"

login "$ADMIN_JAR" admin password && pass 'admin logs in' || fail 'admin logs in'
login "$SUB_JAR" "$SUB_USER" "$SUB_PASS" && pass 'subscriber logs in' || fail 'subscriber logs in'

LOG_START="$(debug_size)"

# Start from a known state: no credentials, no connection.
wp_php <<'PHP' >/dev/null
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dragoncontentdecay\\_%' AND option_name <> 'dragoncontentdecay_db_version'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_dragoncontentdecay\\_%' OR option_name LIKE '\\_transient\\_timeout\\_dragoncontentdecay\\_%'" );
wp_cache_flush();
PHP

# --- 1. settings form --------------------------------------------------------

req "$ADMIN_JAR" "$SETTINGS"
check "$STATUS" 200 'settings screen renders for an admin'
NONCE="$(settings_nonce)"
[ -n "$NONCE" ] && pass 'settings form carries its nonce' || fail 'settings form carries its nonce'

PLAIN_SECRET='GOCSPX-Ab3_dEf-456xYz'
save_form "$ADMIN_JAR" \
	--data-urlencode "dragoncontentdecay_settings_nonce=$NONCE" \
	--data-urlencode 'dragoncontentdecay_google_client_id=1234567890-abc.apps.googleusercontent.com' \
	--data-urlencode "dragoncontentdecay_google_client_secret=$PLAIN_SECRET" \
	--data-urlencode 'dragoncontentdecay_ga4_property_id=123456789' \
	--data-urlencode 'dragoncontentdecay_gsc_enabled=1' \
	--data-urlencode 'dragoncontentdecay_decay_threshold=-35' \
	--data-urlencode 'dragoncontentdecay_comparison_period=60' \
	--data-urlencode 'dragoncontentdecay_post_types[]=post' \
	--data-urlencode 'dragoncontentdecay_post_types[]=page' \
	--data-urlencode 'dragoncontentdecay_email_frequency=weekly' \
	--data-urlencode 'dragoncontentdecay_delete_data_on_uninstall=1'
check "$STATUS" 200 'settings save: screen renders again'
contains "$(body)" 'Settings saved.' 'settings save: says saved'
check "$(opt dragoncontentdecay_google_client_id)" '1234567890-abc.apps.googleusercontent.com' 'settings save: client ID stored'
check "$(secret_b64)" "$(b64 "$PLAIN_SECRET")" 'settings save: client secret stored and decrypts to what was typed'
case "$(opt dragoncontentdecay_google_client_secret)" in
	DRGNc1:*) pass 'settings save: client secret is encrypted at rest' ;;
	*) fail 'settings save: client secret is encrypted at rest' ;;
esac
lacks "$(body)" "$PLAIN_SECRET" 'settings save: the secret is never printed back'
check "$(opt dragoncontentdecay_ga4_property_id)" '123456789' 'settings save: GA4 property stored'
check "$(opt dragoncontentdecay_gsc_enabled)" '1' 'settings save: Search Console switch stored'
check "$(opt dragoncontentdecay_decay_threshold)" '-35' 'settings save: threshold stored'
check "$(opt dragoncontentdecay_comparison_period)" '60' 'settings save: comparison period stored'
check "$(opt dragoncontentdecay_post_types)" '["post","page"]' 'settings save: post types stored as a list'
check "$(opt dragoncontentdecay_email_frequency)" 'weekly' 'settings save: digest frequency stored'
check "$(opt dragoncontentdecay_delete_data_on_uninstall)" '1' 'settings save: uninstall opt-in stored'
check "$(wp_php <<'PHP'
echo wp_next_scheduled( 'dragoncontentdecay_weekly_digest' ) ? 'booked' : 'none';
PHP
)" 'booked' 'settings save: weekly digest booked'

# Unchanged secret field (the mask), unticked boxes, one post type, clamped threshold.
NONCE="$(settings_nonce)"
save_form "$ADMIN_JAR" \
	--data-urlencode "dragoncontentdecay_settings_nonce=$NONCE" \
	--data-urlencode 'dragoncontentdecay_google_client_id=1234567890-abc.apps.googleusercontent.com' \
	--data-urlencode "dragoncontentdecay_google_client_secret=$MASK" \
	--data-urlencode 'dragoncontentdecay_ga4_property_id=123456789' \
	--data-urlencode 'dragoncontentdecay_decay_threshold=25' \
	--data-urlencode 'dragoncontentdecay_comparison_period=30' \
	--data-urlencode 'dragoncontentdecay_post_types[]=post' \
	--data-urlencode 'dragoncontentdecay_email_frequency=off'
contains "$(body)" 'Settings saved.' 'second save: says saved'
check "$(secret_b64)" "$(b64 "$PLAIN_SECRET")" 'second save: the masked field keeps the stored secret'
check "$(opt dragoncontentdecay_post_types)" '["post"]' 'second save: one post type stored as a list'
check "$(opt dragoncontentdecay_gsc_enabled)" '0' 'second save: unticked Search Console switch turns off'
check "$(opt dragoncontentdecay_delete_data_on_uninstall)" '0' 'second save: unticked uninstall opt-in turns off'
check "$(opt dragoncontentdecay_decay_threshold)" '-1' 'second save: out-of-range threshold is clamped'
check "$(wp_php <<'PHP'
echo wp_next_scheduled( 'dragoncontentdecay_weekly_digest' ) ? 'booked' : 'none';
PHP
)" 'none' 'second save: digest unbooked when turned off'

# A secret pasted with spaces around it is stored without them.
NONCE="$(settings_nonce)"
save_form "$ADMIN_JAR" \
	--data-urlencode "dragoncontentdecay_settings_nonce=$NONCE" \
	--data-urlencode "dragoncontentdecay_google_client_secret=  $PLAIN_SECRET  "
check "$(secret_b64)" "$(b64 "$PLAIN_SECRET")" 'pasted secret: surrounding spaces are dropped'

# A secret with characters a text sanitizer would alter survives byte for byte.
ODD_SECRET='Ab%20c+d&e"f'"'"'g\h<i j  k-é_%41'
NONCE="$(settings_nonce)"
save_form "$ADMIN_JAR" \
	--data-urlencode "dragoncontentdecay_settings_nonce=$NONCE" \
	--data-urlencode "dragoncontentdecay_google_client_secret=$ODD_SECRET"
check "$(secret_b64)" "$(b64 "$ODD_SECRET")" 'odd secret: stored byte for byte (%XX, +, &, quotes, backslash, <, double space, multibyte)'

# Put the plain secret back for the Google steps.
NONCE="$(settings_nonce)"
save_form "$ADMIN_JAR" \
	--data-urlencode "dragoncontentdecay_settings_nonce=$NONCE" \
	--data-urlencode "dragoncontentdecay_google_client_secret=$PLAIN_SECRET"
check "$(secret_b64)" "$(b64 "$PLAIN_SECRET")" 'plain secret restored'

BEFORE="$(state)"

save_form "$ADMIN_JAR" \
	--data-urlencode 'dragoncontentdecay_google_client_id=attacker-id' \
	--data-urlencode 'dragoncontentdecay_google_client_secret=attacker-secret' \
	--data-urlencode 'dragoncontentdecay_post_types[]=page' \
	--data-urlencode 'dragoncontentdecay_delete_data_on_uninstall=1'
lacks "$(body)" 'Settings saved.' 'settings save without a nonce: not saved'
check "$(state)" "$BEFORE" 'settings save without a nonce: nothing changed'

save_form "$ADMIN_JAR" \
	--data-urlencode 'dragoncontentdecay_settings_nonce=0123456789' \
	--data-urlencode 'dragoncontentdecay_google_client_id=attacker-id' \
	--data-urlencode 'dragoncontentdecay_google_client_secret=attacker-secret' \
	--data-urlencode 'dragoncontentdecay_post_types[]=page' \
	--data-urlencode 'dragoncontentdecay_delete_data_on_uninstall=1'
lacks "$(body)" 'Settings saved.' 'settings save with a bad nonce: not saved'
check "$(state)" "$BEFORE" 'settings save with a bad nonce: nothing changed'

SUB_NONCE="$(mint_nonce "$SUB_JAR" dragoncontentdecay_save_settings)"
save_form "$SUB_JAR" \
	--data-urlencode "dragoncontentdecay_settings_nonce=$SUB_NONCE" \
	--data-urlencode 'dragoncontentdecay_google_client_id=attacker-id' \
	--data-urlencode 'dragoncontentdecay_google_client_secret=attacker-secret' \
	--data-urlencode 'dragoncontentdecay_delete_data_on_uninstall=1'
check "$STATUS" 403 'settings save by a subscriber: refused'
check "$(state)" "$BEFORE" 'settings save by a subscriber: nothing changed'

# --- 2. connect to Google ----------------------------------------------------

LINK_NONCE="$(oauth_link_nonce connect)"
[ -n "$LINK_NONCE" ] && pass 'connect link carries its nonce' || fail 'connect link carries its nonce'

req "$ADMIN_JAR" "$SETTINGS&action=connect"
check "$STATUS" 403 'connect without a nonce: refused'
contains "$(body)" 'Security check failed' 'connect without a nonce: says the security check failed'
check "$(state_transient "$ADMIN_ID")" '<none>' 'connect without a nonce: no sign-in started'

req "$ADMIN_JAR" "$SETTINGS&action=connect&_wpnonce=0123456789"
check "$STATUS" 403 'connect with a bad nonce: refused'
check "$(state_transient "$ADMIN_ID")" '<none>' 'connect with a bad nonce: no sign-in started'

SUB_LINK_NONCE="$(mint_nonce "$SUB_JAR" dragoncontentdecay_oauth_action)"
req "$SUB_JAR" "$SETTINGS&action=connect&_wpnonce=$SUB_LINK_NONCE"
check "$STATUS" 403 'connect by a subscriber: refused'
check "$(state_transient "$SUB_ID")" '<none>' 'connect by a subscriber: no sign-in started'

req "$ADMIN_JAR" "$SETTINGS&action=connect&_wpnonce=$LINK_NONCE"
check "$STATUS" 302 'connect: redirects'
contains "$LOCATION" 'https://accounts.google.com/o/oauth2/' 'connect: goes to the Google consent screen'
contains "$LOCATION" 'client_id=1234567890-abc.apps.googleusercontent.com' 'connect: carries the client ID'
contains "$LOCATION" 'action%3Dcallback' 'connect: carries the callback address'
OAUTH_STATE="$(sed -n 's/.*[?&]state=\([^&]*\).*/\1/p' <<<"$LOCATION")"
[ -n "$OAUTH_STATE" ] && pass 'connect: carries a state token' || fail 'connect: carries a state token'
check "$(state_transient "$ADMIN_ID")" "$OAUTH_STATE" 'connect: the state token is remembered for this admin'
lacks "$LOCATION" "$PLAIN_SECRET" 'connect: the client secret is not in the address'

# --- 3. the callback from Google ---------------------------------------------

BEFORE="$(state)"

req "$SUB_JAR" "$SETTINGS&action=callback&state=$OAUTH_STATE&code=4%2F0AfakeCode"
check "$STATUS" 403 'callback by a subscriber: refused'
check "$(state_transient "$ADMIN_ID")" "$OAUTH_STATE" 'callback by a subscriber: the admin sign-in is untouched'

req "$ADMIN_JAR" "$SETTINGS&action=callback&error=access_denied"
check "$STATUS" 302 'callback with a Google error: redirects'
contains "$LOCATION" 'oauth_error=denied' 'callback with a Google error: reports access was not granted'
check "$(tokens_json)" '<none>' 'callback with a Google error: nothing connected'

req "$ADMIN_JAR" "$SETTINGS&action=callback&state=$OAUTH_STATE&code=4%2F0AfakeCode_-x.y~z"
check "$STATUS" 302 'callback with the right state: redirects'
contains "$LOCATION" 'oauth_error=callback' 'callback with the right state: the made-up code is turned down by Google'
check "$(tokens_json)" '<none>' 'callback with the right state and a made-up code: nothing connected'
check "$(state_transient "$ADMIN_ID")" '<none>' 'callback: the state token is used up'

req "$ADMIN_JAR" "$SETTINGS&action=callback&state=$OAUTH_STATE&code=4%2F0AfakeCode"
contains "$LOCATION" 'oauth_error=state' 'callback replayed with a used state: refused'

req "$ADMIN_JAR" "$SETTINGS&action=connect&_wpnonce=$LINK_NONCE"
OAUTH_STATE="$(sed -n 's/.*[?&]state=\([^&]*\).*/\1/p' <<<"$LOCATION")"
req "$ADMIN_JAR" "$SETTINGS&action=callback&state=wrong$OAUTH_STATE&code=4%2F0AfakeCode"
check "$STATUS" 302 'callback with a wrong state: redirects'
contains "$LOCATION" 'oauth_error=state' 'callback with a wrong state: refused'
check "$(tokens_json)" '<none>' 'callback with a wrong state: nothing connected'

req "$ADMIN_JAR" "$SETTINGS&action=callback&code=4%2F0AfakeCode"
contains "$LOCATION" 'oauth_error=state' 'callback with no state: refused'

req "$ADMIN_JAR" "$SETTINGS&action=connect&_wpnonce=$LINK_NONCE"
OAUTH_STATE="$(sed -n 's/.*[?&]state=\([^&]*\).*/\1/p' <<<"$LOCATION")"
req "$ADMIN_JAR" "$SETTINGS&action=callback&state=$OAUTH_STATE&code=4%2F0Afake%01Code"
contains "$LOCATION" 'oauth_error=callback' 'callback with a control character in the code: not connected'
check "$(tokens_json)" '<none>' 'callback with a control character in the code: nothing stored'

req "$ADMIN_JAR" "$SETTINGS&action=connect&_wpnonce=$LINK_NONCE"
OAUTH_STATE="$(sed -n 's/.*[?&]state=\([^&]*\).*/\1/p' <<<"$LOCATION")"
req "$ADMIN_JAR" "$SETTINGS&action=callback&state=$OAUTH_STATE&code%5B%5D=4%2F0AfakeCode"
contains "$LOCATION" 'oauth_error=callback' 'callback with the code sent as a list: not connected'
check "$(tokens_json)" '<none>' 'callback with the code sent as a list: nothing stored'

check "$(state)" "$BEFORE" 'callbacks that did not connect changed no setting'

req "$ADMIN_JAR" "$SETTINGS&oauth_error=state"
contains "$(body)" 'Google sign-in could not be verified' 'settings screen explains a state failure'
req "$ADMIN_JAR" "$SETTINGS&oauth_error=callback"
contains "$(body)" 'Google sign-in did not complete' 'settings screen explains a failed exchange'
req "$ADMIN_JAR" "$SETTINGS&oauth_error=denied"
contains "$(body)" 'Google sign-in was cancelled' 'settings screen explains a refusal'
req "$ADMIN_JAR" "$SETTINGS&oauth_error=nonsense&oauth_error%5B%5D=x"
check "$STATUS" 200 'settings screen renders with an unknown error flag'
req "$ADMIN_JAR" "$SETTINGS&connected=1"
contains "$(body)" 'Successfully connected to Google Analytics!' 'settings screen confirms a connection'
req "$ADMIN_JAR" "$SETTINGS&disconnected=1"
contains "$(body)" 'Disconnected from Google Analytics.' 'settings screen confirms a disconnection'

# --- 4. disconnect -----------------------------------------------------------

plant_tokens >/dev/null
PLANTED="$(tokens_json)"
contains "$PLANTED" 'fake-refresh-token' 'a saved connection is in place'
DISC_NONCE="$(oauth_link_nonce disconnect)"
[ -n "$DISC_NONCE" ] && pass 'disconnect link carries its nonce' || fail 'disconnect link carries its nonce'
contains "$(body)" 'dcd-status-connected' 'settings screen shows the saved connection'

req "$ADMIN_JAR" "$SETTINGS&action=disconnect"
check "$STATUS" 403 'disconnect without a nonce: refused'
check "$(tokens_json)" "$PLANTED" 'disconnect without a nonce: connection kept'

req "$ADMIN_JAR" "$SETTINGS&action=disconnect&_wpnonce=0123456789"
check "$STATUS" 403 'disconnect with a bad nonce: refused'
check "$(tokens_json)" "$PLANTED" 'disconnect with a bad nonce: connection kept'

req "$SUB_JAR" "$SETTINGS&action=disconnect&_wpnonce=$SUB_LINK_NONCE"
check "$STATUS" 403 'disconnect by a subscriber: refused'
check "$(tokens_json)" "$PLANTED" 'disconnect by a subscriber: connection kept'

# --- 5. screens that read GET parameters (with a saved connection) -----------

POST_LOW="$(wp_cli post create --post_type=post --post_status=publish --post_title="dcdwporg low $RUN" --porcelain)"
POST_HIGH="$(wp_cli post create --post_type=post --post_status=publish --post_title="dcdwporg high $RUN" --porcelain)"
wp_php <<PHP >/dev/null
global \$wpdb;
\$wpdb->replace( \$wpdb->prefix . 'dcd_scores', array( 'post_id' => $POST_LOW, 'decay_score' => -77.7, 'trend' => 'decaying', 'pageviews_current' => 23, 'pageviews_previous' => 103 ) );
\$wpdb->replace( \$wpdb->prefix . 'dcd_scores', array( 'post_id' => $POST_HIGH, 'decay_score' => 88.8, 'trend' => 'growing', 'pageviews_current' => 189, 'pageviews_previous' => 100 ) );
PHP

req "$ADMIN_JAR" 'tools.php?page=dragon-content-decay'
check "$STATUS" 200 'dashboard renders'
contains "$(body)" "dcdwporg low $RUN" 'dashboard lists a scored post'

req "$ADMIN_JAR" "tools.php?page=dragon-content-decay&post_id=$POST_LOW"
check "$STATUS" 200 'dashboard renders for one post'
contains "$(body)" '103' 'dashboard shows that post: previous views'

req "$ADMIN_JAR" 'tools.php?page=dragon-content-decay&post_id=notanumber'
check "$STATUS" 200 'dashboard renders with a non-numeric post'
req "$ADMIN_JAR" 'tools.php?page=dragon-content-decay&post_id%5B%5D=1&tab%5B%5D=settings'
check "$STATUS" 200 'dashboard renders with list-shaped parameters'
req "$ADMIN_JAR" 'tools.php?page=dragon-content-decay&post_id=99999999'
check "$STATUS" 200 'dashboard renders for a post that does not exist'

req "$SUB_JAR" 'tools.php?page=dragon-content-decay'
check "$STATUS" 403 'dashboard refused to a subscriber'

order_of() {
	local low high
	low="$(grep -n "id=\"post-$POST_LOW\"" "$TMP/body" | head -1 | cut -d: -f1)"
	high="$(grep -n "id=\"post-$POST_HIGH\"" "$TMP/body" | head -1 | cut -d: -f1)"
	if [ -z "$low" ] || [ -z "$high" ]; then
		echo 'missing'
	elif [ "$low" -lt "$high" ]; then
		echo 'low-first'
	else
		echo 'high-first'
	fi
}

req "$ADMIN_JAR" 'edit.php?orderby=dragoncontentdecay_decay&order=asc'
check "$STATUS" 200 'posts list sorted by decay ascending renders'
check "$(order_of)" 'low-first' 'posts list: ascending puts the lowest score first'
req "$ADMIN_JAR" 'edit.php?orderby=dragoncontentdecay_decay&order=desc'
check "$(order_of)" 'high-first' 'posts list: descending puts the highest score first'
req "$ADMIN_JAR" 'edit.php?orderby=dragoncontentdecay_decay'
check "$(order_of)" 'high-first' 'posts list: no direction means descending'
req "$ADMIN_JAR" 'edit.php?orderby=dragoncontentdecay_decay&order=ASC'
check "$(order_of)" 'low-first' 'posts list: direction is case-insensitive'
req "$ADMIN_JAR" 'edit.php?orderby=dragoncontentdecay_decay&order=sideways'
check "$(order_of)" 'high-first' 'posts list: an unknown direction means descending'
req "$ADMIN_JAR" 'edit.php'
check "$STATUS" 200 'posts list renders unsorted'
contains "$(body)" '-77.7%' 'posts list shows the decay column'

check "$(grep -c "dragon-content-decay/admin/css/admin.css" "$TMP/body")" 1 'posts list loads the stylesheet that colours the Decay column'
lacks "$(body)" 'dragon-ui.css' 'posts list does not load the plugin screen kit'
lacks "$(body)" 'var dcdAdmin' 'posts list gets no plugin script or nonce'

# --- 5b. Search Console property lookup ---------------------------------------

wp_php <<'PHP' >/dev/null
update_option( 'dragoncontentdecay_gsc_enabled', 1 );
update_option( 'dragoncontentdecay_google_granted_scopes', 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly', false );
delete_transient( 'dragoncontentdecay_gsc_sites' );
PHP
req "$ADMIN_JAR" "$SETTINGS"
check "$STATUS" 200 'settings screen renders with Search Console on'
lacks "$(body)" 'No Search Console properties were found' 'a refused property lookup is not shown as an empty account'
contains "$(body)" 'Search Console properties could not be listed.' 'a refused property lookup says why'
wp_php <<'PHP' >/dev/null
update_option( 'dragoncontentdecay_gsc_enabled', 0 );
update_option( 'dragoncontentdecay_google_granted_scopes', 'https://www.googleapis.com/auth/analytics.readonly', false );
PHP

# --- 5c. one-time legacy migration and schema version -------------------------

wp_php <<'PHP' >/dev/null
delete_option( 'dragoncontentdecay_legacy_migrated' );
update_option( 'dcd_ga4_property_id', '555000' );
PHP
req "$ADMIN_JAR" 'tools.php?page=dragon-content-decay'
check "$(opt dragoncontentdecay_ga4_property_id)" '555000' 'legacy migration: a pre-1.0.1 setting is carried over once'
check "$(opt dcd_ga4_property_id)" '<unset>' 'legacy migration: the old copy is removed'
check "$(opt dragoncontentdecay_legacy_migrated)" '1' 'legacy migration: recorded as done'
wp_php <<'PHP' >/dev/null
update_option( 'dcd_ga4_property_id', '999999' );
PHP
req "$ADMIN_JAR" 'tools.php?page=dragon-content-decay'
check "$(opt dragoncontentdecay_ga4_property_id)" '555000' 'legacy migration: a later dcd_ option is not copied'
check "$(opt dcd_ga4_property_id)" '999999' 'legacy migration: a later dcd_ option is left alone'

wp_php <<'PHP' >/dev/null
delete_option( 'dragoncontentdecay_db_version' );
PHP
req "$ADMIN_JAR" 'tools.php?page=dragon-content-decay'
check "$(opt dragoncontentdecay_db_version)" "$(wp_cli plugin get dragon-content-decay --field=version)" 'schema: version stamped again after the table check'
check "$(wp_php <<'PHP'
global $wpdb;
echo (string) $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}dcd_scores LIKE 'search_position'" );
PHP
)" 'search_position' 'schema: the scores table has its newest column'

# --- 6. manual sync ----------------------------------------------------------

AJAX_NONCE="$(ajax_nonce)"
[ -n "$AJAX_NONCE" ] && pass 'dashboard hands the script its nonce' || fail 'dashboard hands the script its nonce'
wp_php <<'PHP' >/dev/null
delete_option( 'dragoncontentdecay_last_sync_status' );
delete_option( 'dragoncontentdecay_last_sync_error' );
delete_option( 'dragoncontentdecay_last_sync_error_detail' );
PHP
BEFORE="$(state)"

req "$ADMIN_JAR" admin-ajax.php --data-urlencode 'action=dragoncontentdecay_manual_sync'
check "$STATUS" 403 'manual sync without a nonce: refused'
check "$(state)" "$BEFORE" 'manual sync without a nonce: nothing ran'

req "$ADMIN_JAR" admin-ajax.php --data-urlencode 'action=dragoncontentdecay_manual_sync' --data-urlencode 'nonce=0123456789'
check "$STATUS" 403 'manual sync with a bad nonce: refused'
check "$(state)" "$BEFORE" 'manual sync with a bad nonce: nothing ran'

SUB_AJAX_NONCE="$(mint_nonce "$SUB_JAR" dragoncontentdecay_admin_nonce)"
req "$SUB_JAR" admin-ajax.php --data-urlencode 'action=dragoncontentdecay_manual_sync' --data-urlencode "nonce=$SUB_AJAX_NONCE"
contains "$(body)" '"success":false' 'manual sync by a subscriber: refused'
contains "$(body)" 'Permission denied.' 'manual sync by a subscriber: says permission denied'
check "$(state)" "$BEFORE" 'manual sync by a subscriber: nothing ran'

curl -s --max-time 90 -o "$TMP/body" -D "$TMP/headers" --data-urlencode 'action=dragoncontentdecay_manual_sync' --data-urlencode "nonce=$AJAX_NONCE" "$BASE/wp-admin/admin-ajax.php"
check "$(head -1 "$TMP/headers" | awk '{print $2}')" 400 'manual sync when logged out: refused'
check "$(state)" "$BEFORE" 'manual sync when logged out: nothing ran'

req "$ADMIN_JAR" admin-ajax.php --data-urlencode 'action=dragoncontentdecay_manual_sync' --data-urlencode "nonce=$AJAX_NONCE"
check "$STATUS" 200 'manual sync: answers'
contains "$(body)" '"success":false' 'manual sync: the made-up Google sign-in cannot fetch data, and it says so'
contains "$(body)" 'Sync failed, so no scores were changed.' 'manual sync: reports that no scores changed'
check "$(opt dragoncontentdecay_last_sync_status)" 'failed' 'manual sync: the run is recorded'
check "$(wp_php <<PHP
global \$wpdb;
echo (float) \$wpdb->get_var( "SELECT decay_score FROM {\$wpdb->prefix}dcd_scores WHERE post_id = $POST_LOW" );
PHP
)" '-77.7' 'manual sync: stored scores are kept when the fetch fails'

# --- 7. disconnect (valid) ---------------------------------------------------

req "$ADMIN_JAR" "$SETTINGS&action=disconnect&_wpnonce=$DISC_NONCE"
check "$STATUS" 302 'disconnect: redirects'
contains "$LOCATION" 'disconnected=1' 'disconnect: confirms'
check "$(tokens_json)" '<none>' 'disconnect: the saved connection is removed'
check "$(opt dragoncontentdecay_google_granted_scopes)" '<unset>' 'disconnect: the granted scopes are removed'
check "$(secret_b64)" "$(b64 "$PLAIN_SECRET")" 'disconnect: the client secret is kept'

req "$ADMIN_JAR" 'tools.php?page=dragon-content-decay'
check "$STATUS" 200 'dashboard renders when not connected'

# --- 8. debug log ------------------------------------------------------------

NEW_LOG="$(debug_since "$LOG_START" | grep -E '/plugins/dragon-content-decay(/|-pro/)' || true)"
if [ -z "$NEW_LOG" ]; then
	pass 'no PHP notices or warnings from the plugin in debug.log'
else
	fail 'no PHP notices or warnings from the plugin in debug.log'
	printf '%s\n' "$NEW_LOG" | head -20
fi

TOTAL=$((PASS + FAIL))
printf '%s/%s passed\n' "$PASS" "$TOTAL"
[ "$FAIL" -eq 0 ]
