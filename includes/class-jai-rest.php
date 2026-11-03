<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JAI_REST {
    const NS = 'jobayer-ai/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    private static function route( $path, $callback, $methods = WP_REST_Server::CREATABLE ) {
        register_rest_route( self::NS, $path, array(
            'methods' => $methods,
            'callback' => array( __CLASS__, $callback ),
            'permission_callback' => array( 'JAI_Auth', 'verify_request' ),
        ) );
    }

    public static function register_routes() {
        self::route( '/site', 'site_info', WP_REST_Server::READABLE );
        self::route( '/files/read', 'read_file' );
        self::route( '/files/search', 'search_code' );
        self::route( '/files/patch', 'patch_file' );
        self::route( '/rollback', 'rollback' );
        self::route( '/runtime/url/check', 'check_url' );
        self::route( '/content/search', 'search_content' );
        self::route( '/content/get', 'get_content' );
        self::route( '/content/update', 'update_content' );
        self::route( '/content/rollback', 'rollback_content' );
        self::route( '/content/create', 'create_content' );
        self::route( '/content/create/rollback', 'rollback_created_content' );
        self::route( '/media/upload', 'upload_media' );

        self::route( '/elementor/document/get', 'elementor_get_document' );
        self::route( '/elementor/document/create', 'elementor_create_document' );
        self::route( '/elementor/document/update', 'elementor_update_document' );
        self::route( '/elementor/element/get', 'elementor_get_element' );
        self::route( '/elementor/element/update', 'elementor_update_element' );
        self::route( '/elementor/element/add', 'elementor_add_element' );
        self::route( '/elementor/element/delete', 'elementor_delete_element' );
        self::route( '/elementor/element/move', 'elementor_move_element' );
        self::route( '/elementor/json/validate', 'elementor_validate_json' );
        self::route( '/elementor/json/import', 'elementor_import_json' );
        self::route( '/elementor/json/export', 'elementor_export_json' );
        self::route( '/elementor/css/regenerate', 'elementor_regenerate_css' );
        self::route( '/elementor/rollback', 'elementor_rollback' );
    }

    private static function json( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        return is_array( $params ) ? $params : array();
    }

    public static function site_info() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $theme = wp_get_theme();
        $elementor = JAI_Elementor::version();
        return rest_ensure_response( array(
            'site_url' => site_url(),
            'home_url' => home_url(),
            'wordpress_version' => get_bloginfo( 'version' ),
            'php_version' => PHP_VERSION,
            'theme' => array(
                'name' => $theme->get( 'Name' ),
                'stylesheet' => $theme->get_stylesheet(),
                'version' => $theme->get( 'Version' ),
            ),
            'active_plugins' => array_values( (array) get_option( 'active_plugins', array() ) ),
            'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : false,
            'acf' => defined( 'ACF_VERSION' ) ? ACF_VERSION : false,
            'elementor' => $elementor,
            'debug' => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'php_validation' => JAI_Files::php_validation_method(),
            'editable_roots' => array( 'themes','plugins' ),
            'capabilities' => array(
                'content_create' => true,
                'content_update' => true,
                'media_upload' => true,
                'rank_math' => defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath\\Helper' ),
                'code_patch' => true,
                'rollback' => true,
                'site_registry' => true,
                'elementor_read' => (bool) $elementor,
                'elementor_write' => (bool) $elementor,
                'elementor_json_import_export' => (bool) $elementor,
                'elementor_targeted_edits' => (bool) $elementor,
                'woocommerce_products' => false,
                'acf_write' => false,
                'arbitrary_sql' => false,
                'arbitrary_shell' => false,
            ),
            'agent_version' => JAI_AGENT_VERSION,
        ) );
    }

    public static function read_file( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Files::read( $p['path'] ?? '', $p['start_line'] ?? 1, $p['end_line'] ?? null );
    }

    public static function search_code( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Files::search( $p['query'] ?? '', $p['root'] ?? 'themes', $p['path_prefix'] ?? '' );
    }

    public static function patch_file( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Files::patch( $p['path'] ?? '', $p['expected_sha256'] ?? '', $p['old_text'] ?? '', $p['new_text'] ?? '', $p['occurrence'] ?? 1 );
    }

    public static function rollback( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Backups::rollback( $p['change_id'] ?? '' );
    }

    public static function check_url( WP_REST_Request $r ) {
        $p = self::json( $r );
        return rest_ensure_response( JAI_Runtime::check_url( $p['path'] ?? '/', $p['method'] ?? 'GET' ) );
    }

    public static function search_content( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Content::search( $p['query'] ?? '', $p['post_type'] ?? 'any', $p['status'] ?? 'any', $p['limit'] ?? 20 );
    }

    public static function get_content( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Content::get( $p['id'] ?? 0 );
    }

    public static function update_content( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Content::update( $p['id'] ?? 0, $p['expected_revision_hash'] ?? '', $p['fields'] ?? array(), $p['rank_math'] ?? array() );
    }

    public static function rollback_content( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Content::rollback( $p['change_id'] ?? '', $p['expected_revision_hash'] ?? '' );
    }

    public static function create_content( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Content::create( $p['post_type'] ?? 'post', $p['fields'] ?? array(), $p['rank_math'] ?? array(), $p['author_id'] ?? 0 );
    }

    public static function rollback_created_content( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Content::rollback_created( $p['change_id'] ?? '', $p['expected_revision_hash'] ?? '' );
    }

    public static function upload_media( WP_REST_Request $r ) {
        return JAI_Media::upload( self::json( $r ) );
    }

    public static function elementor_get_document( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Elementor::get_document( $p['id'] ?? 0 );
    }

    public static function elementor_create_document( WP_REST_Request $r ) {
        return JAI_Elementor::create_document( self::json( $r ) );
    }

    public static function elementor_update_document( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Elementor::update_document( $p['id'] ?? 0, $p['expected_revision_hash'] ?? '', $p['elements'] ?? array(), array_key_exists( 'page_settings', $p ) ? $p['page_settings'] : null );
    }

    public static function elementor_get_element( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Elementor::get_element( $p['id'] ?? 0, $p['element_id'] ?? '' );
    }

    public static function elementor_update_element( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Elementor::update_element(
            $p['id'] ?? 0,
            $p['expected_revision_hash'] ?? '',
            $p['element_id'] ?? '',
            array_key_exists( 'settings', $p ) ? $p['settings'] : null,
            $p['settings_mode'] ?? 'merge',
            array_key_exists( 'widget_type', $p ) ? $p['widget_type'] : null
        );
    }

    public static function elementor_add_element( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Elementor::add_element( $p['id'] ?? 0, $p['expected_revision_hash'] ?? '', array_key_exists( 'parent_id', $p ) ? $p['parent_id'] : null, $p['element'] ?? array(), $p['position'] ?? PHP_INT_MAX );
    }

    public static function elementor_delete_element( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Elementor::delete_element( $p['id'] ?? 0, $p['expected_revision_hash'] ?? '', $p['element_id'] ?? '' );
    }

    public static function elementor_move_element( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Elementor::move_element( $p['id'] ?? 0, $p['expected_revision_hash'] ?? '', $p['element_id'] ?? '', array_key_exists( 'new_parent_id', $p ) ? $p['new_parent_id'] : null, $p['position'] ?? PHP_INT_MAX );
    }

    public static function elementor_validate_json( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Elementor::validate_json( $p['json'] ?? '', $p['source_url'] ?? '' );
    }

    public static function elementor_import_json( WP_REST_Request $r ) {
        return JAI_Elementor::import_json( self::json( $r ) );
    }

    public static function elementor_export_json( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Elementor::export_json( $p['id'] ?? 0 );
    }

    public static function elementor_regenerate_css( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Elementor::regenerate_css( $p['id'] ?? 0 );
    }

    public static function elementor_rollback( WP_REST_Request $r ) {
        $p = self::json( $r );
        return JAI_Elementor::rollback( $p['change_id'] ?? '', $p['expected_revision_hash'] ?? '' );
    }
}
