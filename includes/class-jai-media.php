<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JAI_Media {
    const MAX_BYTES = 12582912; // 12 MB.

    private static function allowed_image_mimes() {
        return array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        );
    }

    private static function error( $code, $message, $status = 400 ) {
        return new WP_Error( $code, $message, array( 'status' => $status ) );
    }

    public static function upload( $payload ) {
        $source_url = esc_url_raw( $payload['source_url'] ?? '' );
        $provided_name = sanitize_file_name( $payload['filename'] ?? '' );
        $title = sanitize_text_field( $payload['title'] ?? '' );
        $alt_text = sanitize_text_field( $payload['alt_text'] ?? '' );
        $caption = sanitize_textarea_field( $payload['caption'] ?? '' );
        $description = wp_kses_post( $payload['description'] ?? '' );
        $post_id = absint( $payload['post_id'] ?? 0 );
        $set_featured = ! empty( $payload['set_featured'] );

        if ( ! $source_url || ! wp_http_validate_url( $source_url ) || 0 !== strpos( strtolower( $source_url ), 'https://' ) ) {
            return self::error( 'jai_media_bad_url', 'A valid HTTPS source URL is required.' );
        }

        if ( $post_id && ! get_post( $post_id ) ) {
            return self::error( 'jai_media_bad_post', 'The target post does not exist.', 404 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $source_url, 30 );
        if ( is_wp_error( $tmp ) ) {
            return self::error( 'jai_media_download_failed', 'Could not download the image: ' . $tmp->get_error_message(), 502 );
        }

        $cleanup = static function () use ( $tmp ) {
            if ( is_string( $tmp ) && file_exists( $tmp ) ) {
                @unlink( $tmp );
            }
        };

        $bytes = @filesize( $tmp );
        if ( false === $bytes || $bytes < 1 ) {
            $cleanup();
            return self::error( 'jai_media_empty', 'The downloaded image is empty.' );
        }
        if ( $bytes > self::MAX_BYTES ) {
            $cleanup();
            return self::error( 'jai_media_too_large', 'The image exceeds the 12 MB upload limit.', 413 );
        }

        $image_info = @getimagesize( $tmp );
        $mime = is_array( $image_info ) && ! empty( $image_info['mime'] ) ? strtolower( (string) $image_info['mime'] ) : '';
        $allowed = self::allowed_image_mimes();
        if ( ! isset( $allowed[ $mime ] ) ) {
            $cleanup();
            return self::error( 'jai_media_invalid_image', 'Only JPEG, PNG, WebP, and GIF images are supported.' );
        }

        $stem = pathinfo( $provided_name, PATHINFO_FILENAME );
        $stem = sanitize_file_name( $stem );
        if ( '' === $stem ) {
            $stem = 'chatgpt-image-' . gmdate( 'Ymd-His' );
        }
        $filename = sanitize_file_name( $stem . '.' . $allowed[ $mime ] );

        $file_array = array(
            'name'     => $filename,
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload( $file_array, $post_id, $description );
        if ( is_wp_error( $attachment_id ) ) {
            $cleanup();
            return self::error( 'jai_media_sideload_failed', 'WordPress could not create the media attachment: ' . $attachment_id->get_error_message(), 500 );
        }

        $update = array( 'ID' => $attachment_id );
        if ( '' !== $title ) {
            $update['post_title'] = $title;
        }
        if ( '' !== $caption ) {
            $update['post_excerpt'] = $caption;
        }
        if ( '' !== $description ) {
            $update['post_content'] = $description;
        }
        if ( count( $update ) > 1 ) {
            $updated = wp_update_post( wp_slash( $update ), true );
            if ( is_wp_error( $updated ) ) {
                wp_delete_attachment( $attachment_id, true );
                return self::error( 'jai_media_metadata_failed', 'The image uploaded, but its metadata could not be saved: ' . $updated->get_error_message(), 500 );
            }
        }

        if ( '' !== $alt_text ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
        }

        $featured_post_id = 0;
        if ( $set_featured && $post_id ) {
            if ( ! current_theme_supports( 'post-thumbnails' ) ) {
                wp_delete_attachment( $attachment_id, true );
                return self::error( 'jai_media_featured_unsupported', 'The active theme does not support featured images.', 400 );
            }
            if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
                wp_delete_attachment( $attachment_id, true );
                return self::error( 'jai_media_featured_failed', 'The image uploaded, but WordPress could not set it as the featured image.', 500 );
            }
            $featured_post_id = $post_id;
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );

        return rest_ensure_response( array(
            'attachment_id' => $attachment_id,
            'url' => wp_get_attachment_url( $attachment_id ),
            'filename' => basename( get_attached_file( $attachment_id ) ),
            'mime_type' => get_post_mime_type( $attachment_id ),
            'width' => is_array( $metadata ) ? (int) ( $metadata['width'] ?? 0 ) : 0,
            'height' => is_array( $metadata ) ? (int) ( $metadata['height'] ?? 0 ) : 0,
            'alt_text' => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
            'title' => get_the_title( $attachment_id ),
            'caption' => wp_get_attachment_caption( $attachment_id ),
            'post_parent' => (int) wp_get_post_parent_id( $attachment_id ),
            'featured_post_id' => $featured_post_id,
        ) );
    }

    private static function image_attachment( $id ) {
        $post = get_post( absint( $id ) );
        if ( ! $post || 'attachment' !== $post->post_type || 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
            return self::error( 'jai_media_not_image', 'The requested attachment is not an image.', 404 );
        }
        return $post;
    }

    private static function alt_snapshot( $post_id ) {
        return array(
            'exists' => metadata_exists( 'post', $post_id, '_wp_attachment_image_alt' ),
            'alt_text' => (string) get_post_meta( $post_id, '_wp_attachment_image_alt', true ),
        );
    }

    private static function alt_revision_hash( $post_id ) {
        $json = wp_json_encode( array( 'id' => (int) $post_id, 'alt' => self::alt_snapshot( $post_id ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return hash( 'sha256', false === $json ? '' : $json );
    }

    public static function get_image( $id ) {
        $post = self::image_attachment( $id );
        if ( is_wp_error( $post ) ) { return $post; }
        $metadata = wp_get_attachment_metadata( $post->ID );
        $file = get_attached_file( $post->ID );
        return array(
            'id' => (int) $post->ID,
            'url' => wp_get_attachment_url( $post->ID ),
            'filename' => $file ? basename( $file ) : '',
            'title' => get_the_title( $post->ID ),
            'caption' => wp_get_attachment_caption( $post->ID ),
            'description' => wp_strip_all_tags( (string) $post->post_content ),
            'post_parent' => (int) $post->post_parent,
            'mime_type' => (string) $post->post_mime_type,
            'width' => is_array( $metadata ) ? (int) ( $metadata['width'] ?? 0 ) : 0,
            'height' => is_array( $metadata ) ? (int) ( $metadata['height'] ?? 0 ) : 0,
            'alt_text' => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
            'revision_hash' => self::alt_revision_hash( $post->ID ),
        );
    }

    public static function list_images( $page = 1, $per_page = 50, $missing_only = false ) {
        $page = max( 1, absint( $page ) );
        $per_page = max( 1, min( 100, absint( $per_page ) ) );
        $query = new WP_Query( array(
            'post_type' => 'attachment',
            'post_status' => array( 'inherit', 'private' ),
            'post_mime_type' => 'image',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => false,
        ) );
        $items = array();
        foreach ( $query->posts as $post ) {
            $item = self::get_image( $post->ID );
            if ( is_wp_error( $item ) ) { continue; }
            if ( $missing_only && '' !== trim( $item['alt_text'] ) ) { continue; }
            $items[] = $item;
        }
        return array(
            'page' => $page,
            'per_page' => $per_page,
            'total' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'items' => $items,
            // The missing-only filter applies to this page; iterate all pages to audit the library.
            'missing_only_is_page_filtered' => (bool) $missing_only,
        );
    }

    public static function update_alt( $id, $expected_revision_hash, $alt_text, $overwrite_existing = false ) {
        $post = self::image_attachment( $id );
        if ( is_wp_error( $post ) ) { return $post; }
        $expected_revision_hash = strtolower( (string) $expected_revision_hash );
        $current_hash = self::alt_revision_hash( $post->ID );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_revision_hash ) || ! hash_equals( $current_hash, $expected_revision_hash ) ) {
            return self::error( 'jai_media_alt_changed', 'Image alt text changed since it was read. Refresh first.', 409 );
        }
        if ( ! is_string( $alt_text ) ) {
            return self::error( 'jai_media_alt_invalid', 'Alt text must be a string.' );
        }
        $alt_text = sanitize_text_field( $alt_text );
        if ( '' === trim( $alt_text ) || strlen( $alt_text ) > 220 ) {
            return self::error( 'jai_media_alt_invalid', 'Alt text must be nonempty and at most 220 bytes.' );
        }
        $previous = self::alt_snapshot( $post->ID );
        if ( $previous['alt_text'] === $alt_text ) {
            return array( 'updated' => false, 'unchanged' => true, 'item' => self::get_image( $post->ID ) );
        }
        if ( ! $overwrite_existing && '' !== trim( $previous['alt_text'] ) ) {
            return self::error( 'jai_media_alt_existing', 'Existing alt text is preserved unless overwrite_existing is explicitly enabled.', 409 );
        }
        $backup = JAI_Backups::create_payload( 'media_alt', $post->ID, $previous, 'media_alt_update' );
        if ( is_wp_error( $backup ) ) { return $backup; }
        update_post_meta( $post->ID, '_wp_attachment_image_alt', wp_slash( $alt_text ) );
        $after = self::alt_snapshot( $post->ID );
        JAI_Backups::mark_payload_after( $backup['change_id'], $after );
        return array( 'updated' => true, 'change_id' => $backup['change_id'], 'item' => self::get_image( $post->ID ) );
    }

    public static function rollback_alt( $change_id, $expected_revision_hash ) {
        $stored = JAI_Backups::get_payload( $change_id, 'media_alt' );
        if ( is_wp_error( $stored ) ) { return $stored; }
        if ( ! empty( $stored['meta']['rolled_back'] ) ) {
            return self::error( 'jai_media_alt_already_rolled_back', 'This change has already been rolled back.', 409 );
        }
        $id = absint( $stored['meta']['resource_id'] ?? 0 );
        $post = self::image_attachment( $id );
        if ( is_wp_error( $post ) ) { return $post; }
        $hash = self::alt_revision_hash( $id );
        $expected = strtolower( (string) $expected_revision_hash );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $hash, $expected ) ) {
            return self::error( 'jai_media_alt_changed', 'Alt text has changed since the update. Rollback refused.', 409 );
        }
        $before = $stored['payload'];
        if ( ! empty( $before['exists'] ) ) {
            update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( (string) ( $before['alt_text'] ?? '' ) ) );
        } else {
            delete_post_meta( $id, '_wp_attachment_image_alt' );
        }
        JAI_Backups::mark_rolled_back( $change_id );
        return array( 'restored' => true, 'change_id' => $change_id, 'item' => self::get_image( $id ) );
    }

}
