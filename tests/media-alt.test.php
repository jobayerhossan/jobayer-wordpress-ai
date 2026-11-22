<?php
// In-memory test of existing media alt updates, preservation, concurrency and rollback.
define( 'ABSPATH', __DIR__ );
class WP_Error {
    public $code;
    public function __construct( $code ) { $this->code = $code; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function absint( $n ) { return abs( (int) $n ); }
function wp_json_encode( $n, $opts = 0 ) { return json_encode( $n, $opts ); }
function get_post( $id ) { return isset( $GLOBALS['posts'][$id] ) ? $GLOBALS['posts'][$id] : null; }
function get_post_meta( $id, $key, $one = true ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['meta'][$id] ?? array() ); }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][$id][$key] = stripslashes( $value ); return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][$id][$key] ); }
function sanitize_text_field( $t ) { return trim( strip_tags( $t ) ); }
function wp_slash( $t ) { return addslashes( $t ); }
function wp_get_attachment_metadata( $id ) { return array( 'width' => 800, 'height' => 500 ); }
function get_attached_file( $id ) { return '/uploads/drywall-repair.jpg'; }
function wp_get_attachment_url( $id ) { return 'https://example.com/drywall-repair.jpg'; }
function get_the_title( $id ) { return $GLOBALS['posts'][$id]->post_title; }
function wp_get_attachment_caption( $id ) { return ''; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
class JAI_Backups {
    public static $items = array();
    public static function create_payload( $type, $id, $before, $operation ) {
        $cid = '20261122-001122-media001';
        self::$items[$cid] = array( 'meta' => array( 'resource_id' => $id, 'rolled_back' => false ), 'payload' => $before );
        return array( 'change_id' => $cid );
    }
    public static function mark_payload_after( $cid, $after ) {}
    public static function get_payload( $cid, $type ) { return self::$items[$cid] ?? new WP_Error('no_backup'); }
    public static function mark_rolled_back( $cid ) { self::$items[$cid]['meta']['rolled_back'] = true; }
}
require_once dirname( __DIR__ ) . '/includes/class-jai-media.php';
function assert_true( $v, $message ) { if (!$v) { fwrite(STDERR, "FAIL $message\n"); exit(1); } }
$GLOBALS['posts'] = array(
    17 => (object) array( 'ID' => 17, 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg', 'post_title' => 'Repair', 'post_parent' => 5, 'post_content' => '' ),
    18 => (object) array( 'ID' => 18, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf', 'post_title' => 'Not image', 'post_parent' => 0, 'post_content' => '' ),
);
$GLOBALS['meta'] = array(17 => array());
$initial = JAI_Media::get_image(17);
assert_true( isset($initial['revision_hash']) && 64 === strlen($initial['revision_hash']), 'read revision hash' );
assert_true( is_wp_error(JAI_Media::get_image(18)), 'reject non-image attachment' );
$first = JAI_Media::update_alt(17, $initial['revision_hash'], "Drywall repair in O'Reilly's Tulsa home");
assert_true( !empty($first['updated']), 'write new text' );
assert_true( "Drywall repair in O'Reilly's Tulsa home" === JAI_Media::get_image(17)['alt_text'], 'quotes preserved' );
assert_true( is_wp_error(JAI_Media::update_alt(17, $initial['revision_hash'], 'outdated')), 'reject stale hash' );
$new = JAI_Media::get_image(17);
assert_true( is_wp_error(JAI_Media::update_alt(17, $new['revision_hash'], 'overwrite')), 'preserve existing text' );
$restore = JAI_Media::rollback_alt($first['change_id'], $new['revision_hash']);
assert_true(!empty($restore['restored']), 'restore backup');
assert_true(!metadata_exists('post',17,'_wp_attachment_image_alt'), 'restore absent alt key');
assert_true(is_wp_error(JAI_Media::rollback_alt($first['change_id'], JAI_Media::get_image(17)['revision_hash'])), 'reject double rollback');
echo "Media alt management smoke tests passed.\n";
