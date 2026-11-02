<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JAI_Registry {
    const OPTION = 'jai_registered_sites_v1';
    const KEY_OPTION = 'jai_registry_key';

    private static function key() {
        $stored = (string) get_option( self::KEY_OPTION, '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/i', $stored ) ) {
            $stored = bin2hex( random_bytes( 32 ) );
            update_option( self::KEY_OPTION, $stored, false );
        }
        return hex2bin( $stored );
    }

    private static function encrypt( $plain ) {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return new WP_Error( 'jai_registry_crypto_unavailable', 'OpenSSL encryption is unavailable.', array( 'status' => 503 ) );
        }
        $iv = random_bytes( 12 );
        $tag = '';
        $cipher = openssl_encrypt( (string) $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
        if ( false === $cipher ) {
            return new WP_Error( 'jai_registry_encrypt_failed', 'Could not encrypt site registry.', array( 'status' => 500 ) );
        }
        return base64_encode( 'JRG1' . $iv . $tag . $cipher );
    }

    private static function decrypt( $encoded ) {
        $blob = base64_decode( (string) $encoded, true );
        if ( false === $blob || strlen( $blob ) < 32 || 'JRG1' !== substr( $blob, 0, 4 ) ) { return array(); }
        $plain = openssl_decrypt( substr( $blob, 32 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $blob, 4, 12 ), substr( $blob, 16, 16 ) );
        if ( false === $plain ) {
            return new WP_Error( 'jai_registry_decrypt_failed', 'Could not decrypt site registry.', array( 'status' => 500 ) );
        }
        $sites = json_decode( $plain, true );
        return is_array( $sites ) ? $sites : array();
    }

    public static function all() {
        $stored = (string) get_option( self::OPTION, '' );
        return '' === $stored ? array() : self::decrypt( $stored );
    }

    private static function save( $sites ) {
        $encrypted = self::encrypt( wp_json_encode( $sites, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        if ( is_wp_error( $encrypted ) ) { return $encrypted; }
        update_option( self::OPTION, $encrypted, false );
        return true;
    }

    public static function upsert( $payload ) {
        $alias = sanitize_key( $payload['alias'] ?? '' );
        $base_url = untrailingslashit( esc_url_raw( $payload['base_url'] ?? '' ) );
        $site_id = sanitize_text_field( $payload['site_id'] ?? '' );
        $secret = trim( (string) ( $payload['secret'] ?? '' ) );
        $site_name = sanitize_text_field( $payload['site_name'] ?? '' );

        if ( '' === $alias || '' === $base_url || ! wp_http_validate_url( $base_url ) || 0 !== strpos( $base_url, 'https://' ) ) {
            return new WP_Error( 'jai_registry_bad_site', 'A valid HTTPS site URL and alias are required.', array( 'status' => 400 ) );
        }
        if ( ! preg_match( '/^site_[A-Za-z0-9_-]{8,80}$/', $site_id ) || ! preg_match( '/^[a-f0-9]{64}$/i', $secret ) ) {
            return new WP_Error( 'jai_registry_bad_credentials', 'Invalid site credentials.', array( 'status' => 400 ) );
        }

        $sites = self::all();
        if ( is_wp_error( $sites ) ) { return $sites; }
        $sites[ $alias ] = array(
            'baseUrl' => $base_url,
            'siteId' => $site_id,
            'secret' => $secret,
            'siteName' => $site_name,
            'updatedAt' => gmdate( 'c' ),
        );
        $saved = self::save( $sites );
        if ( is_wp_error( $saved ) ) { return $saved; }
        return array( 'saved' => true, 'alias' => $alias );
    }
}
