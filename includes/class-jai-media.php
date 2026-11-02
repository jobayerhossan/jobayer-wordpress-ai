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
}
