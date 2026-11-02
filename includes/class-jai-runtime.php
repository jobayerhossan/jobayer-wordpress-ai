<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JAI_Runtime {
    public static function check_url( $path = '/', $method = 'GET' ) {
        $path = trim( (string) $path );
        $method = strtoupper( (string) $method );
        if ( '' === $path ) { $path = '/'; }
        if ( ! in_array( $method, array( 'GET','HEAD' ), true ) ) {
            return new WP_Error( 'jai_bad_http_method', 'URL checks support GET or HEAD only.', array( 'status' => 400 ) );
        }
        if ( false !== strpos( $path, "\0" ) || preg_match( '#^[a-z][a-z0-9+.-]*://#i', $path ) || 0 === strpos( $path, '//' ) ) {
            return new WP_Error( 'jai_external_url_denied', 'Only a same-site relative path may be checked.', array( 'status' => 400 ) );
        }
        if ( '/' !== $path[0] ) { $path = '/' . $path; }
        $url = home_url( $path );
        $start = microtime( true );
        $response = wp_remote_request( $url, array(
            'method' => $method,
            'timeout' => 15,
            'redirection' => 3,
            'sslverify' => true,
            'headers' => array( 'User-Agent' => 'Jobayer-AI-Agent/' . JAI_AGENT_VERSION ),
        ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'jai_url_check_failed', $response->get_error_message(), array( 'status' => 502, 'url' => $url ) );
        }
        $body = 'HEAD' === $method ? '' : (string) wp_remote_retrieve_body( $response );
        $excerpt = wp_strip_all_tags( preg_replace( '/\s+/', ' ', $body ) );
        $excerpt = function_exists( 'mb_substr' ) ? mb_substr( $excerpt, 0, 1500 ) : substr( $excerpt, 0, 1500 );
        return array(
            'path' => $path,
            'url' => $url,
            'method' => $method,
            'status' => (int) wp_remote_retrieve_response_code( $response ),
            'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
            'elapsed_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
            'body_excerpt' => $excerpt,
        );
    }
}
