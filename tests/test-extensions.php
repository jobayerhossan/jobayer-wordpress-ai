<?php
// Offline unit checks: no real WordPress instance or installation occurs.
define( 'ABSPATH', __DIR__ . '/fake-wordpress/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
$GLOBALS['jai_test_enabled'] = 'no';
function get_option( $key, $default = false ) {
    return 'jai_extension_management_enabled' === $key ? $GLOBALS['jai_test_enabled'] : $default;
}
function is_multisite() { return false; }
function wp_is_file_mod_allowed( $context ) { return true; }
require __DIR__ . '/../includes/class-jai-extensions.php';

function check( $condition, $label ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
    echo "PASS: $label\n";
}
check( ! JAI_Extensions::enabled(), 'Opt-in disabled by default' );
check( 'woocommerce' === JAI_Extensions::valid_slug( 'woocommerce' ), 'Lowercase slug accepted' );
foreach ( array( '', 'WooCommerce', '../woocommerce', 'zip/file', 'hello.zip', 'bad_name', 'http://evil', str_repeat( 'x', 101 ) ) as $slug ) {
    check( is_wp_error( JAI_Extensions::valid_slug( $slug ) ), "Unsafe slug rejected: $slug" );
}
$v = JAI_Extensions::install( 'plugin', 'woocommerce', true, true );
check( is_wp_error( $v ) && 'jai_extension_management_disabled' === $v->get_error_code(), 'Install requires site opt-in' );
$v = JAI_Extensions::activate( 'theme', 'astra', true );
check( is_wp_error( $v ) && 'jai_extension_management_disabled' === $v->get_error_code(), 'Theme activation requires site opt-in' );
$GLOBALS['jai_test_enabled'] = 'yes';
$v = JAI_Extensions::install( 'plugin', 'woocommerce', true, false );
check( is_wp_error( $v ) && 'jai_extension_confirmation_required' === $v->get_error_code(), 'Install requires explicit confirmation' );
$v = JAI_Extensions::activate( 'theme', 'astra', false );
check( is_wp_error( $v ) && 'jai_extension_confirmation_required' === $v->get_error_code(), 'Theme switch requires explicit confirmation' );
echo "All guardrail tests passed.\n";
