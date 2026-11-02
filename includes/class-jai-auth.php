<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JAI_Auth {
    const MAX_CLOCK_SKEW = 300;

    public static function verify_request( WP_REST_Request $request ) {
        $site_id   = (string) $request->get_header( 'x-jai-site' );
        $timestamp = (string) $request->get_header( 'x-jai-timestamp' );
        $nonce     = (string) $request->get_header( 'x-jai-nonce' );
        $signature = (string) $request->get_header( 'x-jai-signature' );

        $expected_site = (string) get_option( 'jai_site_id', '' );
        $secret        = (string) get_option( 'jai_site_secret', '' );

        if ( ! $expected_site || ! $secret ) {
            return new WP_Error( 'jai_not_configured', 'Agent authentication is not configured.', array( 'status' => 503 ) );
        }
        if ( ! hash_equals( $expected_site, $site_id ) ) {
            return new WP_Error( 'jai_bad_site', 'Invalid site identifier.', array( 'status' => 401 ) );
        }
        if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_CLOCK_SKEW ) {
            return new WP_Error( 'jai_expired', 'Request timestamp is outside the allowed window.', array( 'status' => 401 ) );
        }
        if ( ! preg_match( '/^[a-f0-9]{16,128}$/i', $nonce ) ) {
            return new WP_Error( 'jai_bad_nonce', 'Invalid request nonce.', array( 'status' => 401 ) );
        }

        $nonce_key = 'jai_nonce_' . hash( 'sha256', $nonce );
        if ( get_transient( $nonce_key ) ) {
            return new WP_Error( 'jai_replay', 'This request nonce has already been used.', array( 'status' => 409 ) );
        }

        $canonical = implode( "\n", array(
            $timestamp,
            $nonce,
            strtoupper( $request->get_method() ),
            $request->get_route(),
            hash( 'sha256', (string) $request->get_body() ),
        ) );

        $expected_signature = hash_hmac( 'sha256', $canonical, $secret );
        if ( ! preg_match( '/^[a-f0-9]{64}$/i', $signature ) || ! hash_equals( $expected_signature, strtolower( $signature ) ) ) {
            return new WP_Error( 'jai_bad_signature', 'Invalid request signature.', array( 'status' => 401 ) );
        }

        set_transient( $nonce_key, 1, self::MAX_CLOCK_SKEW * 2 );
        return true;
    }
}
