<?php
// Standalone Yoast metadata smoke tests. No WordPress installation required.
define( 'ABSPATH', __DIR__ );
define( 'WPSEO_VERSION', '28.0' );
class WP_Error {
    public $code;
    public function __construct( $code ) { $this->code = $code; }
}
$GLOBALS['meta'] = array();
function get_post_meta( $post_id, $key, $single = true ) {
    return $GLOBALS['meta'][ $post_id ][ $key ] ?? '';
}
function metadata_exists( $type, $post_id, $key ) {
    return array_key_exists( $key, $GLOBALS['meta'][ $post_id ] ?? array() );
}
function update_post_meta( $post_id, $key, $value ) {
    $GLOBALS['meta'][ $post_id ][ $key ] = stripslashes( $value );
}
function delete_post_meta( $post_id, $key ) {
    unset( $GLOBALS['meta'][ $post_id ][ $key ] );
}
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
function wp_slash( $value ) { return addslashes( $value ); }
require_once dirname( __DIR__ ) . '/includes/class-jai-content.php';

function check( $value, $message ) {
    if ( ! $value ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
function invoke_private( $method, ...$args ) {
    $ref = new ReflectionMethod( 'JAI_Content', $method );
    return $ref->invokeArgs( null, $args );
}
check( true === JAI_Content::yoast_available(), 'Yoast detection' );
check( true === invoke_private( 'validate_yoast', array( 'title' => 'Hello' ) ), 'Valid title accepted' );
check( invoke_private( 'validate_yoast', array( 'robots' => 'noindex' ) ) instanceof WP_Error, 'Unknown key refused' );
check( invoke_private( 'validate_yoast', array( 'title' => array( 'bad' ) ) ) instanceof WP_Error, 'Array value refused' );

$GLOBALS['meta'][5] = array(
    '_yoast_wpseo_title' => 'Old title',
    '_yoast_wpseo_metadesc' => 'Old description',
    '_yoast_wpseo_focuskw' => 'old keyword',
    '_yoast_wpseo_canonical' => 'https://example.com/',
);
$before = invoke_private( 'yoast_snapshot', 5 );
invoke_private( 'apply_yoast', 5, array(
    'title' => "A title with O'Reilly",
    'description' => 'Updated description',
    'focus_keyword' => 'drywall repair Tulsa',
) );
$values = invoke_private( 'yoast_values', 5 );
check( "A title with O'Reilly" === $values['title'], 'Quotation and slashing round trip' );
check( 'Updated description' === $values['description'], 'Description update' );
check( 'drywall repair Tulsa' === $values['focus_keyword'], 'Focus keyphrase update' );
check( 'https://example.com/' === get_post_meta( 5, '_yoast_wpseo_canonical' ), 'Unrelated Yoast key preserved' );
invoke_private( 'apply_yoast', 5, array( 'title' => null ) );
check( ! metadata_exists( 'post', 5, '_yoast_wpseo_title' ), 'Explicit null clears only title' );
check( 'drywall repair Tulsa' === get_post_meta( 5, '_yoast_wpseo_focuskw' ), 'Omitted key remains untouched' );

invoke_private( 'restore_yoast', 5, $before );
$restored = invoke_private( 'yoast_values', 5 );
check( 'Old title' === $restored['title'], 'Title rollback' );
check( 'Old description' === $restored['description'], 'Description rollback' );
check( 'old keyword' === $restored['focus_keyword'], 'Keyphrase rollback' );
check( 'https://example.com/' === get_post_meta( 5, '_yoast_wpseo_canonical' ), 'Rollback leaves other Yoast keys alone' );
echo "Yoast metadata smoke tests passed.\n";
