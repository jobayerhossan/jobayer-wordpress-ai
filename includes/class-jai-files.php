<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JAI_Files {
    const MAX_FILE_BYTES = 2097152;
    const MAX_SEARCH_FILES = 500;
    const MAX_SEARCH_MATCHES = 100;

    private static $allowed_extensions = array(
        'php','css','scss','sass','less','js','mjs','cjs','jsx','ts','tsx',
        'json','html','htm','xml','txt','md','yml','yaml','svg'
    );

    public static function roots() {
        return array(
            'themes' => trailingslashit( WP_CONTENT_DIR ) . 'themes',
            'plugins' => trailingslashit( WP_CONTENT_DIR ) . 'plugins',
        );
    }

    private static function is_inside( $path, $root ) {
        $path = rtrim( $path, DIRECTORY_SEPARATOR );
        $root = rtrim( $root, DIRECTORY_SEPARATOR );
        return 0 === strpos( $path . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR );
    }

    public static function resolve_path( $path, $must_exist = true ) {
        $path = ltrim( str_replace( '\\', '/', trim( (string) $path ) ), '/' );
        if ( '' === $path || false !== strpos( $path, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
            return new WP_Error( 'jai_bad_path', 'Invalid file path.', array( 'status' => 400 ) );
        }
        $parts = explode( '/', $path, 2 );
        $roots = self::roots();
        if ( 2 !== count( $parts ) || ! isset( $roots[ $parts[0] ] ) ) {
            return new WP_Error( 'jai_root_denied', 'Path must begin with themes/ or plugins/.', array( 'status' => 403 ) );
        }
        $root_key = $parts[0];
        $root_real = realpath( $roots[ $root_key ] );
        if ( false === $root_real ) {
            return new WP_Error( 'jai_root_missing', 'Allowed root does not exist.', array( 'status' => 500 ) );
        }
        $candidate = $root_real . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $parts[1] );

        if ( $must_exist ) {
            $real = realpath( $candidate );
            if ( false === $real || ! is_file( $real ) ) {
                return new WP_Error( 'jai_file_missing', 'File was not found.', array( 'status' => 404 ) );
            }
            if ( ! self::is_inside( $real, $root_real ) ) {
                return new WP_Error( 'jai_path_escape', 'Resolved path is outside the allowed root.', array( 'status' => 403 ) );
            }
            $candidate = $real;
        } else {
            $parent = realpath( dirname( $candidate ) );
            if ( false === $parent || ! self::is_inside( $parent, $root_real ) ) {
                return new WP_Error( 'jai_parent_denied', 'Target parent directory is outside the allowed root or does not exist.', array( 'status' => 403 ) );
            }
            $candidate = $parent . DIRECTORY_SEPARATOR . basename( $candidate );
        }

        $ext = strtolower( pathinfo( $candidate, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, self::$allowed_extensions, true ) ) {
            return new WP_Error( 'jai_extension_denied', 'This file type is not editable by the agent.', array( 'status' => 403 ) );
        }

        return array(
            'absolute' => $candidate,
            'relative' => $root_key . '/' . str_replace( DIRECTORY_SEPARATOR, '/', substr( $candidate, strlen( $root_real ) + 1 ) ),
            'root' => $root_key,
            'ext' => $ext,
        );
    }

    public static function read( $path, $start_line = 1, $end_line = null ) {
        $resolved = self::resolve_path( $path, true );
        if ( is_wp_error( $resolved ) ) { return $resolved; }
        $size = filesize( $resolved['absolute'] );
        if ( false === $size || $size > self::MAX_FILE_BYTES ) {
            return new WP_Error( 'jai_file_too_large', 'File is larger than the 2 MB read limit.', array( 'status' => 413 ) );
        }
        $content = (string) file_get_contents( $resolved['absolute'] );
        $lines = preg_split( "/\r\n|\n|\r/", $content );
        $total = count( $lines );
        $start = max( 1, (int) $start_line );
        $end = null === $end_line ? $total : max( $start, min( $total, (int) $end_line ) );
        return array(
            'path' => $resolved['relative'],
            'sha256' => hash( 'sha256', $content ),
            'size' => $size,
            'total_lines' => $total,
            'start_line' => $start,
            'end_line' => $end,
            'content' => implode( "\n", array_slice( $lines, $start - 1, $end - $start + 1 ) ),
        );
    }

    public static function search( $query, $root = 'themes', $path_prefix = '' ) {
        $query = (string) $query;
        if ( '' === trim( $query ) ) {
            return new WP_Error( 'jai_empty_query', 'Search query cannot be empty.', array( 'status' => 400 ) );
        }
        $roots = self::roots();
        if ( ! isset( $roots[ $root ] ) ) {
            return new WP_Error( 'jai_bad_root', 'Search root must be themes or plugins.', array( 'status' => 400 ) );
        }
        $base_root = realpath( $roots[ $root ] );
        $base = $base_root;
        if ( $path_prefix ) {
            $prefix = trim( str_replace( '\\', '/', $path_prefix ), '/' );
            if ( preg_match( '#(^|/)\.\.(/|$)#', $prefix ) ) {
                return new WP_Error( 'jai_bad_prefix', 'Invalid search path prefix.', array( 'status' => 400 ) );
            }
            $candidate = realpath( $base_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $prefix ) );
            if ( false === $candidate || ! is_dir( $candidate ) || ! self::is_inside( $candidate, $base_root ) ) {
                return new WP_Error( 'jai_prefix_missing', 'Search path prefix was not found.', array( 'status' => 404 ) );
            }
            $base = $candidate;
        }

        $results = array();
        $seen = 0;
        $directory = new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS );
        $filter = new RecursiveCallbackFilterIterator( $directory, function ( $current ) {
            return ! ( $current->isDir() && in_array( $current->getFilename(), array( '.git','node_modules','vendor' ), true ) );
        } );
        $iterator = new RecursiveIteratorIterator( $filter );

        foreach ( $iterator as $file ) {
            if ( count( $results ) >= self::MAX_SEARCH_MATCHES || $seen >= self::MAX_SEARCH_FILES ) { break; }
            if ( ! $file->isFile() || $file->getSize() > self::MAX_FILE_BYTES ) { continue; }
            $ext = strtolower( $file->getExtension() );
            if ( ! in_array( $ext, self::$allowed_extensions, true ) ) { continue; }
            $seen++;
            $lines = @file( $file->getPathname(), FILE_IGNORE_NEW_LINES );
            if ( ! is_array( $lines ) ) { continue; }
            foreach ( $lines as $i => $line ) {
                if ( false !== stripos( $line, $query ) ) {
                    $results[] = array(
                        'path' => $root . '/' . str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), strlen( $base_root ) + 1 ) ),
                        'line' => $i + 1,
                        'snippet' => function_exists( 'mb_substr' ) ? mb_substr( trim( $line ), 0, 500 ) : substr( trim( $line ), 0, 500 ),
                    );
                    if ( count( $results ) >= self::MAX_SEARCH_MATCHES ) { break 2; }
                }
            }
        }
        return array( 'query' => $query, 'root' => $root, 'files_scanned' => $seen, 'matches' => $results, 'truncated' => $seen >= self::MAX_SEARCH_FILES || count( $results ) >= self::MAX_SEARCH_MATCHES );
    }

    public static function patch( $path, $expected_sha256, $old_text, $new_text, $occurrence = 1 ) {
        $resolved = self::resolve_path( $path, true );
        if ( is_wp_error( $resolved ) ) { return $resolved; }
        $content = (string) file_get_contents( $resolved['absolute'] );
        $actual_sha = hash( 'sha256', $content );
        if ( ! $expected_sha256 || ! hash_equals( $actual_sha, strtolower( (string) $expected_sha256 ) ) ) {
            return new WP_Error( 'jai_sha_mismatch', 'File changed since it was read.', array( 'status' => 409, 'sha256' => $actual_sha ) );
        }
        $old_text = (string) $old_text;
        if ( '' === $old_text ) {
            return new WP_Error( 'jai_empty_old_text', 'old_text cannot be empty.', array( 'status' => 400 ) );
        }
        $positions = array();
        $offset = 0;
        while ( false !== ( $pos = strpos( $content, $old_text, $offset ) ) ) {
            $positions[] = $pos;
            $offset = $pos + max( 1, strlen( $old_text ) );
        }
        $occurrence = max( 1, (int) $occurrence );
        if ( ! isset( $positions[ $occurrence - 1 ] ) ) {
            return new WP_Error( 'jai_text_not_found', 'Requested exact text occurrence was not found.', array( 'status' => 409, 'occurrences' => count( $positions ) ) );
        }
        $pos = $positions[ $occurrence - 1 ];
        $updated = substr( $content, 0, $pos ) . (string) $new_text . substr( $content, $pos + strlen( $old_text ) );
        if ( strlen( $updated ) > self::MAX_FILE_BYTES ) {
            return new WP_Error( 'jai_file_too_large_after', 'Patched file would exceed 2 MB.', array( 'status' => 413 ) );
        }
        $backup = JAI_Backups::create( $resolved['relative'], $resolved['absolute'], 'patch' );
        if ( is_wp_error( $backup ) ) { return $backup; }
        $write = self::atomic_write( $resolved['absolute'], $updated, $resolved['ext'] );
        if ( is_wp_error( $write ) ) { return $write; }
        JAI_Backups::mark_after( $backup['change_id'], $resolved['absolute'] );
        return array( 'change_id' => $backup['change_id'], 'path' => $resolved['relative'], 'sha256_before' => $actual_sha, 'sha256_after' => hash( 'sha256', $updated ), 'patched' => true );
    }

    private static function atomic_write( $target, $content, $extension ) {
        if ( ! is_writable( dirname( $target ) ) || ( file_exists( $target ) && ! is_writable( $target ) ) ) {
            return new WP_Error( 'jai_target_unwritable', 'Target path is not writable.', array( 'status' => 500 ) );
        }
        $tmp = dirname( $target ) . '/.' . basename( $target ) . '.jai-' . wp_generate_password( 8, false, false ) . '.tmp';
        if ( false === @file_put_contents( $tmp, $content, LOCK_EX ) ) {
            return new WP_Error( 'jai_temp_write_failed', 'Could not write temporary file.', array( 'status' => 500 ) );
        }
        if ( 'php' === strtolower( $extension ) ) {
            $lint = self::lint_php( $tmp );
            if ( is_wp_error( $lint ) ) { @unlink( $tmp ); return $lint; }
        }
        @chmod( $tmp, file_exists( $target ) ? ( fileperms( $target ) & 0777 ) : 0644 );
        if ( ! @rename( $tmp, $target ) ) {
            @unlink( $tmp );
            return new WP_Error( 'jai_atomic_replace_failed', 'Could not atomically replace the target file.', array( 'status' => 500 ) );
        }
        if ( function_exists( 'opcache_invalidate' ) && 'php' === strtolower( $extension ) ) {
            @opcache_invalidate( $target, true );
        }
        return true;
    }

    public static function atomic_write_for_restore( $target, $content ) {
        return self::atomic_write( $target, $content, strtolower( pathinfo( $target, PATHINFO_EXTENSION ) ) );
    }

    private static function lint_php( $file ) {
        if ( function_exists( 'token_get_all' ) && defined( 'TOKEN_PARSE' ) ) {
            try {
                token_get_all( (string) file_get_contents( $file ), TOKEN_PARSE );
                return true;
            } catch ( Throwable $e ) {
                return new WP_Error( 'jai_php_lint_failed', $e->getMessage(), array( 'status' => 422, 'validation_method' => 'tokenizer' ) );
            }
        }
        return new WP_Error( 'jai_php_lint_unavailable', 'Safe PHP syntax validation is unavailable.', array( 'status' => 503 ) );
    }

    public static function php_validation_method() {
        return function_exists( 'token_get_all' ) && defined( 'TOKEN_PARSE' ) ? 'tokenizer' : false;
    }

    public static function php_lint_available() {
        return false !== self::php_validation_method();
    }
}
