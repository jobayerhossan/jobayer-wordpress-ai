<?php
/**
 * Rank Math redirection management for the signed Jobayer AI Agent REST API.
 * Only accessible through JAI_Auth's HMAC permission callback.
 */
defined( 'ABSPATH' ) || exit;

final class JAI_RankMath_Redirects {
    private static function available() {
        if ( ! class_exists( '\RankMath\Redirections\DB' ) || ! class_exists( '\RankMath\Redirections\Redirection' ) ) {
            return new WP_Error( 'jai_rankmath_unavailable', 'Rank Math Redirections module is not enabled.', array( 'status' => 409 ) );
        }
        return true;
    }

    private static function path( $value, $destination = false ) {
        if ( ! is_string( $value ) || strlen( $value ) > 2048 || '' === $value ) {
            return new WP_Error( 'jai_redirect_path', 'A nonempty URL path is required.', array( 'status' => 400 ) );
        }
        if ( $destination && preg_match( '~^https?://~i', $value ) ) {
            $parts = wp_parse_url( $value );
            $home = wp_parse_url( home_url() );
            if ( ! is_array( $parts ) || ! isset( $parts['host'], $home['host'] ) ||
                strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ||
                isset( $parts['user'] ) || isset( $parts['pass'] ) ||
                isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
                return new WP_Error( 'jai_redirect_host', 'Only the current WordPress host is allowed.', array( 'status' => 400 ) );
            }
            $value = isset( $parts['path'] ) ? $parts['path'] : '/';
        }
        if ( '/' !== substr( $value, 0, 1 ) || strpos( $value, '//' ) !== false ||
            strpos( $value, '..' ) !== false || preg_match( '/[?#\\x00-\\x20\\x7f]/', $value ) ) {
            return new WP_Error( 'jai_redirect_path', 'Use a safe same-site path without query parameters.', array( 'status' => 400 ) );
        }
        $path = trim( $value, '/' );
        if ( ! $destination && ( ! $path || preg_match( '~^(wp-admin|wp-json|wp-login\.php)(/|$)~i', $path ) ) ) {
            return new WP_Error( 'jai_redirect_protected', 'The source is a protected WordPress path.', array( 'status' => 400 ) );
        }
        return $path ? '/' . $path . '/' : '/';
    }

    private static function record( $r ) {
        $r['sources'] = maybe_unserialize( $r['sources'] ?? array() );
        if ( ! is_array( $r['sources'] ) ) { $r['sources'] = array(); }
        $r['revision'] = hash( 'sha256', wp_json_encode( array(
            (int) $r['id'], $r['sources'], (string) $r['url_to'], (string) $r['header_code'], (string) $r['status'],
        ) ) );
        return $r;
    }

    private static function inventory() {
        $check = self::available();
        if ( is_wp_error( $check ) ) { return $check; }
        $rows = array();
        for ( $page = 1; $page <= 100; $page++ ) {
            $chunk = \RankMath\Redirections\DB::get_redirections( array( 'limit' => 100, 'paged' => $page ) );
            if ( ! isset( $chunk['redirections'], $chunk['count'] ) || ! is_array( $chunk['redirections'] ) ) {
                return new WP_Error( 'jai_redirect_inventory', 'Rank Math inventory cannot be read.', array( 'status' => 500 ) );
            }
            foreach ( $chunk['redirections'] as $r ) { $rows[] = self::record( $r ); }
            if ( count( $rows ) >= (int) $chunk['count'] ) { return $rows; }
        }
        return new WP_Error( 'jai_redirect_inventory_limit', 'Too many existing redirects.', array( 'status' => 409 ) );
    }

    private static function snapshot( $rows ) {
        return hash( 'sha256', wp_json_encode( array_map( static function ( $r ) {
            return array( (int) $r['id'], $r['revision'] );
        }, $rows ) ) );
    }

    private static function matches( $rows, $path ) {
        $found = array();
        foreach ( $rows as $r ) {
            foreach ( $r['sources'] as $source ) {
                if ( ! is_array( $source ) ) { continue; }
                if ( 'exact' !== ( $source['comparison'] ?? 'exact' ) ) { continue; }
                if ( strtolower( '/' . trim( (string) ( $source['pattern'] ?? '' ), '/' ) . '/' ) === strtolower( $path ) ) {
                    $found[] = $r;
                    break;
                }
            }
        }
        return $found;
    }

    public static function listing( $p ) {
        $rows = self::inventory();
        if ( is_wp_error( $rows ) ) { return $rows; }
        $page = max( 1, (int) ( $p['page'] ?? 1 ) );
        $limit = max( 1, min( 100, (int) ( $p['per_page'] ?? 50 ) ) );
        return array( 'total' => count( $rows ), 'page' => $page, 'inventory_revision' => self::snapshot( $rows ), 'items' => array_slice( $rows, ( $page - 1 ) * $limit, $limit ) );
    }

    public static function check( $p ) {
        $source = self::path( $p['source'] ?? null );
        if ( is_wp_error( $source ) ) { return $source; }
        $rows = self::inventory();
        if ( is_wp_error( $rows ) ) { return $rows; }
        $found = self::matches( $rows, $source );
        return array( 'source' => $source, 'exists' => ! empty( $found ), 'items' => $found, 'inventory_revision' => self::snapshot( $rows ) );
    }

    private static function insert( $source, $destination ) {
        $item = \RankMath\Redirections\Redirection::from( array(
            'sources' => array( array( 'pattern' => $source, 'comparison' => 'exact', 'ignore' => '' ) ),
            'url_to' => home_url( $destination ), 'header_code' => '301', 'status' => 'active',
        ) );
        if ( ! $item->has_sources() || $item->is_infinite_loop() ) {
            return new WP_Error( 'jai_redirect_loop', 'Invalid redirect or infinite loop.', array( 'status' => 400 ) );
        }
        $id = $item->save();
        if ( ! $id ) { return new WP_Error( 'jai_redirect_save', 'Rank Math failed to save redirect.', array( 'status' => 500 ) ); }
        return (int) $id;
    }

    public static function create( $p ) {
        $src = self::path( $p['source'] ?? null );
        if ( is_wp_error( $src ) ) { return $src; }
        $dst = self::path( $p['destination'] ?? null, true );
        if ( is_wp_error( $dst ) ) { return $dst; }
        if ( $src === $dst ) { return new WP_Error( 'jai_redirect_loop', 'Source and target are identical.', array( 'status' => 400 ) ); }
        $rows = self::inventory();
        if ( is_wp_error( $rows ) ) { return $rows; }
        if ( self::matches( $rows, $src ) ) { return new WP_Error( 'jai_redirect_exists', 'Exact source already exists in Rank Math.', array( 'status' => 409 ) ); }
        $id = self::insert( $src, $dst );
        if ( is_wp_error( $id ) ) { return $id; }
        return array( 'created' => true, 'id' => $id, 'source' => $src, 'destination' => $dst );
    }

    private static function current( $p ) {
        $id = absint( $p['id'] ?? 0 );
        $revision = (string) ( $p['expected_revision'] ?? '' );
        $r = $id ? \RankMath\Redirections\DB::get_redirection_by_id( $id ) : false;
        if ( ! $r || 'trashed' === $r['status'] ) {
            return new WP_Error( 'jai_redirect_not_found', 'Redirect not found.', array( 'status' => 404 ) );
        }
        $r = self::record( $r );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $revision ) || ! hash_equals( $r['revision'], $revision ) ) {
            return new WP_Error( 'jai_redirect_revision', 'Redirect was modified. Read again.', array( 'status' => 409 ) );
        }
        return $r;
    }

    public static function update( $p ) {
        $check = self::available();
        if ( is_wp_error( $check ) ) { return $check; }
        $r = self::current( $p );
        if ( is_wp_error( $r ) ) { return $r; }
        $dst = self::path( $p['destination'] ?? null, true );
        if ( is_wp_error( $dst ) ) { return $dst; }
        foreach ( $r['sources'] as $src ) {
            if ( ! is_array( $src ) ) { continue; }
            if ( $dst === '/' . trim( (string) ( $src['pattern'] ?? '' ), '/' ) . '/' ) {
                return new WP_Error( 'jai_redirect_loop', 'Target matches a redirect source.', array( 'status' => 400 ) );
            }
        }
        $item = \RankMath\Redirections\Redirection::from( array(
            'id' => (int) $r['id'], 'sources' => $r['sources'], 'url_to' => home_url( $dst ),
            'header_code' => $r['header_code'], 'status' => $r['status'],
        ) );
        if ( ! $item->save() ) { return new WP_Error( 'jai_redirect_save', 'Failed to update redirect.', array( 'status' => 500 ) ); }
        return array( 'updated' => true, 'id' => (int) $r['id'], 'destination' => $dst );
    }

    public static function delete( $p ) {
        $check = self::available();
        if ( is_wp_error( $check ) ) { return $check; }
        $r = self::current( $p );
        if ( is_wp_error( $r ) ) { return $r; }
        $deleted = \RankMath\Redirections\DB::delete( array( (int) $r['id'] ) );
        if ( ! $deleted ) { return new WP_Error( 'jai_redirect_delete', 'Deletion failed.', array( 'status' => 500 ) ); }
        return array( 'deleted' => true, 'id' => (int) $r['id'] );
    }

    public static function bulk( $p ) {
        $rows = self::inventory();
        if ( is_wp_error( $rows ) ) { return $rows; }
        $rules = $p['rules'] ?? array();
        if ( ! is_array( $rules ) || count( $rules ) < 1 || count( $rules ) > 50 ) {
            return new WP_Error( 'jai_redirect_batch', 'Supply between 1 and 50 redirects.', array( 'status' => 400 ) );
        }
        $snapshot = self::snapshot( $rows );
        $apply = ! empty( $p['apply'] );
        if ( $apply && ! hash_equals( $snapshot, (string) ( $p['expected_inventory_revision'] ?? '' ) ) ) {
            return new WP_Error( 'jai_redirect_revision', 'Inventory has changed. Preview again.', array( 'status' => 409 ) );
        }
        $ready = array();
        $issues = array();
        $seen = array();
        foreach ( $rules as $index => $rule ) {
            if ( ! is_array( $rule ) ) { $issues[] = array( 'row' => $index + 1, 'reason' => 'invalid_rule' ); continue; }
            $src = self::path( $rule['source'] ?? null );
            $dst = self::path( $rule['destination'] ?? null, true );
            if ( is_wp_error( $src ) || is_wp_error( $dst ) || $src === $dst ) {
                $issues[] = array( 'row' => $index + 1, 'reason' => 'invalid_path_or_loop' );
                continue;
            }
            $key = strtolower( $src );
            if ( isset( $seen[ $key ] ) ) { $issues[] = array( 'row' => $index + 1, 'reason' => 'duplicate_in_batch' ); continue; }
            $seen[ $key ] = true;
            $existing = self::matches( $rows, $src );
            if ( $existing ) {
                $issues[] = array( 'row' => $index + 1, 'reason' => 'existing_redirect', 'ids' => array_map( static function( $r ) { return (int) $r['id']; }, $existing ) );
                continue;
            }
            $ready[] = array( 'source' => $src, 'destination' => $dst );
        }
        if ( ! $apply ) { return array( 'dry_run' => true, 'ready' => $ready, 'issues' => $issues, 'inventory_revision' => $snapshot ); }
        if ( $issues ) {
            return new WP_Error( 'jai_redirect_batch_conflicts', 'Resolve conflicts before importing; no changes made.', array( 'status' => 409, 'issues' => $issues ) );
        }
        $created = array();
        foreach ( $ready as $rule ) {
            $id = self::insert( $rule['source'], $rule['destination'] );
            if ( is_wp_error( $id ) ) {
                return new WP_Error( 'jai_redirect_batch_partial', 'Import stopped after a failure; inspect created redirects.', array( 'status' => 500, 'created' => $created, 'failed_source' => $rule['source'] ) );
            }
            $created[] = array( 'id' => $id, 'source' => $rule['source'], 'destination' => $rule['destination'] );
        }
        return array( 'dry_run' => false, 'created' => $created, 'created_count' => count( $created ) );
    }
}
