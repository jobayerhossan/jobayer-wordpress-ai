<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JAI_Registry_REST {
    const NS = 'jobayer-ai/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        $permission = array( 'JAI_Auth', 'verify_request' );
        register_rest_route( self::NS, '/registry/list', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( __CLASS__, 'list_sites' ),
            'permission_callback' => $permission,
        ) );
        register_rest_route( self::NS, '/registry/upsert', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( __CLASS__, 'upsert_site' ),
            'permission_callback' => $permission,
        ) );
    }

    public static function list_sites() {
        $sites = JAI_Registry::all();
        return is_wp_error( $sites ) ? $sites : rest_ensure_response( array( 'sites' => $sites ) );
    }

    public static function upsert_site( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $result = JAI_Registry::upsert( is_array( $params ) ? $params : array() );
        return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
    }
}
