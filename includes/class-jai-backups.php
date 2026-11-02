<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JAI_Backups {
    public static function ensure_backup_dir() {
        if ( ! is_dir( JAI_BACKUP_DIR ) ) {
            wp_mkdir_p( JAI_BACKUP_DIR );
        }
        if ( is_dir( JAI_BACKUP_DIR ) ) {
            if ( ! file_exists( JAI_BACKUP_DIR . '/index.php' ) ) {
                @file_put_contents( JAI_BACKUP_DIR . '/index.php', "<?php\n// Silence is golden.\n" );
            }
            if ( ! file_exists( JAI_BACKUP_DIR . '/.htaccess' ) ) {
                @file_put_contents( JAI_BACKUP_DIR . '/.htaccess', "Order allow,deny\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
            }
        }
    }

    private static function key() {
        $stored = (string) get_option( 'jai_backup_key', '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/i', $stored ) ) {
            $stored = bin2hex( random_bytes( 32 ) );
            update_option( 'jai_backup_key', $stored, false );
        }
        return hex2bin( $stored );
    }

    private static function encrypt( $plain ) {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return new WP_Error( 'jai_backup_crypto_unavailable', 'OpenSSL encryption is unavailable.', array( 'status' => 503 ) );
        }
        $iv = random_bytes( 12 );
        $tag = '';
        $cipher = openssl_encrypt( (string) $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
        if ( false === $cipher ) {
            return new WP_Error( 'jai_backup_encrypt_failed', 'Could not encrypt the backup.', array( 'status' => 500 ) );
        }
        return 'JAI1' . $iv . $tag . $cipher;
    }

    private static function decrypt( $file ) {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return new WP_Error( 'jai_backup_crypto_unavailable', 'OpenSSL decryption is unavailable.', array( 'status' => 503 ) );
        }
        $blob = @file_get_contents( $file );
        if ( false === $blob || strlen( $blob ) < 32 || 'JAI1' !== substr( $blob, 0, 4 ) ) {
            return new WP_Error( 'jai_backup_corrupt', 'Encrypted backup is invalid.', array( 'status' => 500 ) );
        }
        $plain = openssl_decrypt(
            substr( $blob, 32 ),
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            substr( $blob, 4, 12 ),
            substr( $blob, 16, 16 )
        );
        return false === $plain
            ? new WP_Error( 'jai_backup_decrypt_failed', 'Could not decrypt the backup.', array( 'status' => 500 ) )
            : $plain;
    }

    private static function new_change_dir() {
        self::ensure_backup_dir();
        if ( ! is_dir( JAI_BACKUP_DIR ) || ! is_writable( JAI_BACKUP_DIR ) ) {
            return new WP_Error( 'jai_backup_unwritable', 'Backup directory is not writable.', array( 'status' => 500 ) );
        }
        $change_id = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 8, false, false ) );
        $dir = JAI_BACKUP_DIR . '/' . $change_id;
        if ( ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'jai_backup_create_failed', 'Could not create backup directory.', array( 'status' => 500 ) );
        }
        return array( 'change_id' => $change_id, 'dir' => $dir );
    }

    public static function create( $relative_path, $absolute_path, $operation ) {
        $slot = self::new_change_dir();
        if ( is_wp_error( $slot ) ) { return $slot; }

        $existed = file_exists( $absolute_path );
        if ( $existed ) {
            $plain = @file_get_contents( $absolute_path );
            if ( false === $plain ) {
                return new WP_Error( 'jai_backup_read_failed', 'Could not read the original file.', array( 'status' => 500 ) );
            }
            $encrypted = self::encrypt( $plain );
            if ( is_wp_error( $encrypted ) ) { return $encrypted; }
            if ( false === @file_put_contents( $slot['dir'] . '/original.enc', $encrypted, LOCK_EX ) ) {
                return new WP_Error( 'jai_backup_write_failed', 'Could not write encrypted file backup.', array( 'status' => 500 ) );
            }
        }

        $meta = array(
            'kind' => 'file',
            'change_id' => $slot['change_id'],
            'created_at' => gmdate( 'c' ),
            'operation' => sanitize_key( $operation ),
            'relative_path' => $relative_path,
            'existed' => $existed,
            'sha256_before' => $existed ? hash_file( 'sha256', $absolute_path ) : null,
            'rolled_back' => false,
        );
        @file_put_contents( $slot['dir'] . '/meta.json', wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        return $meta;
    }

    public static function mark_after( $change_id, $absolute_path ) {
        $file = JAI_BACKUP_DIR . '/' . basename( $change_id ) . '/meta.json';
        if ( ! file_exists( $file ) ) { return; }
        $meta = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $meta ) ) { return; }
        $meta['sha256_after'] = file_exists( $absolute_path ) ? hash_file( 'sha256', $absolute_path ) : null;
        @file_put_contents( $file, wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    public static function rollback( $change_id ) {
        $change_id = basename( (string) $change_id );
        $dir = JAI_BACKUP_DIR . '/' . $change_id;
        $meta_file = $dir . '/meta.json';
        if ( ! file_exists( $meta_file ) ) {
            return new WP_Error( 'jai_change_missing', 'Change backup was not found.', array( 'status' => 404 ) );
        }
        $meta = json_decode( (string) file_get_contents( $meta_file ), true );
        if ( ! is_array( $meta ) || 'file' !== ( $meta['kind'] ?? '' ) ) {
            return new WP_Error( 'jai_change_wrong_kind', 'This is not a file backup.', array( 'status' => 400 ) );
        }
        if ( ! empty( $meta['rolled_back'] ) ) {
            return new WP_Error( 'jai_change_already_rolled_back', 'This change has already been rolled back.', array( 'status' => 409 ) );
        }

        $resolved = JAI_Files::resolve_path( $meta['relative_path'] ?? '', false );
        if ( is_wp_error( $resolved ) ) { return $resolved; }
        $target = $resolved['absolute'];

        if ( ! empty( $meta['existed'] ) ) {
            $plain = self::decrypt( $dir . '/original.enc' );
            if ( is_wp_error( $plain ) ) { return $plain; }
            $restored = JAI_Files::atomic_write_for_restore( $target, $plain );
            if ( is_wp_error( $restored ) ) { return $restored; }
        } elseif ( file_exists( $target ) && ! @unlink( $target ) ) {
            return new WP_Error( 'jai_rollback_delete_failed', 'Could not remove created file.', array( 'status' => 500 ) );
        }

        $meta['rolled_back'] = true;
        $meta['rolled_back_at'] = gmdate( 'c' );
        @file_put_contents( $meta_file, wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        return array( 'change_id' => $change_id, 'path' => $meta['relative_path'], 'restored' => true );
    }

    public static function create_payload( $resource_type, $resource_id, $payload, $operation ) {
        $slot = self::new_change_dir();
        if ( is_wp_error( $slot ) ) { return $slot; }
        $json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $json ) {
            return new WP_Error( 'jai_backup_encode_failed', 'Could not encode snapshot.', array( 'status' => 500 ) );
        }
        $encrypted = self::encrypt( $json );
        if ( is_wp_error( $encrypted ) ) { return $encrypted; }
        if ( false === @file_put_contents( $slot['dir'] . '/original.enc', $encrypted, LOCK_EX ) ) {
            return new WP_Error( 'jai_backup_write_failed', 'Could not write encrypted snapshot.', array( 'status' => 500 ) );
        }
        $meta = array(
            'kind' => 'content',
            'change_id' => $slot['change_id'],
            'created_at' => gmdate( 'c' ),
            'operation' => sanitize_key( $operation ),
            'resource_type' => sanitize_key( $resource_type ),
            'resource_id' => absint( $resource_id ),
            'payload_sha256_before' => hash( 'sha256', $json ),
            'rolled_back' => false,
        );
        @file_put_contents( $slot['dir'] . '/meta.json', wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        return $meta;
    }

    public static function get_payload( $change_id, $resource_type = '' ) {
        $change_id = basename( (string) $change_id );
        $dir = JAI_BACKUP_DIR . '/' . $change_id;
        $meta_file = $dir . '/meta.json';
        if ( ! file_exists( $meta_file ) ) {
            return new WP_Error( 'jai_change_missing', 'Change backup was not found.', array( 'status' => 404 ) );
        }
        $meta = json_decode( (string) file_get_contents( $meta_file ), true );
        if ( ! is_array( $meta ) || 'content' !== ( $meta['kind'] ?? '' ) ) {
            return new WP_Error( 'jai_change_wrong_kind', 'This is not a content snapshot.', array( 'status' => 400 ) );
        }
        if ( $resource_type && sanitize_key( $resource_type ) !== ( $meta['resource_type'] ?? '' ) ) {
            return new WP_Error( 'jai_change_wrong_resource', 'Snapshot belongs to another resource.', array( 'status' => 400 ) );
        }
        $plain = self::decrypt( $dir . '/original.enc' );
        if ( is_wp_error( $plain ) ) { return $plain; }
        $payload = json_decode( $plain, true );
        if ( ! is_array( $payload ) ) {
            return new WP_Error( 'jai_backup_corrupt', 'Snapshot payload is invalid.', array( 'status' => 500 ) );
        }
        return array( 'meta' => $meta, 'payload' => $payload );
    }

    public static function mark_payload_after( $change_id, $payload ) {
        $file = JAI_BACKUP_DIR . '/' . basename( $change_id ) . '/meta.json';
        if ( ! file_exists( $file ) ) { return; }
        $meta = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $meta ) ) { return; }
        $json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false !== $json ) { $meta['payload_sha256_after'] = hash( 'sha256', $json ); }
        @file_put_contents( $file, wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    public static function mark_rolled_back( $change_id ) {
        $file = JAI_BACKUP_DIR . '/' . basename( $change_id ) . '/meta.json';
        if ( ! file_exists( $file ) ) { return; }
        $meta = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $meta ) ) { return; }
        $meta['rolled_back'] = true;
        $meta['rolled_back_at'] = gmdate( 'c' );
        @file_put_contents( $file, wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }
}
