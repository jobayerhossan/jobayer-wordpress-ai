<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JAI_Content {
    public static function searchable_post_types() {
        $types = get_post_types( array( 'show_ui' => true ), 'objects' );
        $denied = array(
            'attachment','revision','nav_menu_item','custom_css','customize_changeset',
            'product','product_variation','shop_order','shop_order_refund','shop_coupon',
            'acf-field-group','acf-field','elementor_library','e-landing-page',
            'wp_template','wp_template_part','wp_global_styles','wp_navigation'
        );
        $allowed = array();
        foreach ( $types as $name => $object ) {
            if ( ! in_array( $name, $denied, true ) ) { $allowed[] = $name; }
        }
        return array_values( array_unique( $allowed ) );
    }

    public static function search( $query = '', $post_type = 'any', $status = 'any', $limit = 20 ) {
        $allowed = self::searchable_post_types();
        if ( 'any' === $post_type || '' === $post_type ) {
            $types = $allowed;
        } else {
            $types = array_values( array_intersect( array_map( 'sanitize_key', (array) $post_type ), $allowed ) );
            if ( empty( $types ) ) {
                return new WP_Error( 'jai_content_type_denied', 'Requested post type is not available.', array( 'status' => 400 ) );
            }
        }
        $q = new WP_Query( array(
            'post_type' => $types,
            'post_status' => $status ?: 'any',
            'posts_per_page' => max( 1, min( 50, (int) $limit ) ),
            'orderby' => 'modified',
            'order' => 'DESC',
            's' => (string) $query,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ) );
        $items = array();
        foreach ( $q->posts as $post ) {
            $items[] = array(
                'id' => (int) $post->ID,
                'post_type' => $post->post_type,
                'status' => $post->post_status,
                'title' => get_the_title( $post ),
                'slug' => $post->post_name,
                'modified_gmt' => $post->post_modified_gmt,
                'url' => get_permalink( $post ),
            );
        }
        return array( 'query' => (string) $query, 'post_types' => $types, 'items' => $items );
    }

    private static function rank_math_keys() {
        return array(
            'title' => 'rank_math_title',
            'description' => 'rank_math_description',
            'focus_keyword' => 'rank_math_focus_keyword',
            'canonical_url' => 'rank_math_canonical_url',
            'robots' => 'rank_math_robots',
        );
    }

    private static function rank_math_values( $post_id ) {
        $out = array();
        foreach ( self::rank_math_keys() as $name => $key ) {
            $out[ $name ] = get_post_meta( $post_id, $key, true );
        }
        return $out;
    }

    private static function rank_math_snapshot( $post_id ) {
        $out = array();
        foreach ( self::rank_math_keys() as $name => $key ) {
            $out[ $name ] = array(
                'exists' => metadata_exists( 'post', $post_id, $key ),
                'value' => get_post_meta( $post_id, $key, true ),
            );
        }
        return $out;
    }

    private static function snapshot( WP_Post $post ) {
        return array(
            'post' => array(
                'ID' => (int) $post->ID,
                'post_type' => $post->post_type,
                'post_status' => $post->post_status,
                'post_title' => $post->post_title,
                'post_name' => $post->post_name,
                'post_excerpt' => $post->post_excerpt,
                'post_content' => $post->post_content,
            ),
            'rank_math' => self::rank_math_snapshot( $post->ID ),
        );
    }

    private static function revision_hash( WP_Post $post ) {
        $json = wp_json_encode( self::snapshot( $post ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return hash( 'sha256', false === $json ? '' : $json );
    }

    private static function ensure_editable_post( $post_id ) {
        $post = get_post( absint( $post_id ) );
        if ( ! $post ) {
            return new WP_Error( 'jai_content_missing', 'Content item was not found.', array( 'status' => 404 ) );
        }
        if ( ! in_array( $post->post_type, self::searchable_post_types(), true ) ) {
            return new WP_Error( 'jai_content_type_denied', 'This post type is not available for editing.', array( 'status' => 403 ) );
        }
        return $post;
    }

    public static function get( $post_id ) {
        $post = self::ensure_editable_post( $post_id );
        if ( is_wp_error( $post ) ) { return $post; }
        return array(
            'id' => (int) $post->ID,
            'post_type' => $post->post_type,
            'status' => $post->post_status,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'excerpt' => $post->post_excerpt,
            'content' => $post->post_content,
            'author_id' => (int) $post->post_author,
            'created_gmt' => $post->post_date_gmt,
            'modified_gmt' => $post->post_modified_gmt,
            'revision_hash' => self::revision_hash( $post ),
            'url' => get_permalink( $post ),
            'rank_math' => self::rank_math_values( $post->ID ),
        );
    }

    private static function statuses() {
        return array( 'draft','pending','private','publish','future' );
    }

    private static function apply_rank_math( $post_id, $changes ) {
        if ( ! is_array( $changes ) ) { return; }
        foreach ( self::rank_math_keys() as $name => $key ) {
            if ( ! array_key_exists( $name, $changes ) ) { continue; }
            $value = $changes[ $name ];
            if ( null === $value || '' === $value ) {
                delete_post_meta( $post_id, $key );
                continue;
            }
            if ( 'canonical_url' === $name ) {
                $value = esc_url_raw( (string) $value );
            } elseif ( 'robots' === $name ) {
                $value = sanitize_text_field( (string) $value );
            } else {
                $value = sanitize_textarea_field( (string) $value );
            }
            update_post_meta( $post_id, $key, $value );
        }
    }

    public static function update( $post_id, $expected_revision_hash, $fields = array(), $rank_math = array() ) {
        $post = self::ensure_editable_post( $post_id );
        if ( is_wp_error( $post ) ) { return $post; }
        $current = self::revision_hash( $post );
        $expected = strtolower( (string) $expected_revision_hash );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $current, $expected ) ) {
            return new WP_Error( 'jai_content_changed', 'Content changed since it was read.', array( 'status' => 409, 'current_revision_hash' => $current ) );
        }
        $fields = is_array( $fields ) ? $fields : array();
        $rank_math = is_array( $rank_math ) ? $rank_math : array();
        $unknown = array_diff( array_keys( $fields ), array( 'title','slug','excerpt','content','status' ) );
        if ( $unknown ) {
            return new WP_Error( 'jai_content_field_denied', 'Unsupported content field: ' . reset( $unknown ), array( 'status' => 400 ) );
        }
        if ( isset( $fields['status'] ) && ! in_array( $fields['status'], self::statuses(), true ) ) {
            return new WP_Error( 'jai_content_status_denied', 'Requested status is not allowed.', array( 'status' => 400 ) );
        }
        if ( empty( $fields ) && empty( $rank_math ) ) {
            return new WP_Error( 'jai_content_no_changes', 'No changes were supplied.', array( 'status' => 400 ) );
        }

        $backup = JAI_Backups::create_payload( 'content', $post->ID, self::snapshot( $post ), 'content_update' );
        if ( is_wp_error( $backup ) ) { return $backup; }

        $postarr = array( 'ID' => $post->ID );
        if ( array_key_exists( 'title', $fields ) ) { $postarr['post_title'] = wp_slash( (string) $fields['title'] ); }
        if ( array_key_exists( 'slug', $fields ) ) { $postarr['post_name'] = sanitize_title( (string) $fields['slug'] ); }
        if ( array_key_exists( 'excerpt', $fields ) ) { $postarr['post_excerpt'] = wp_slash( (string) $fields['excerpt'] ); }
        if ( array_key_exists( 'content', $fields ) ) { $postarr['post_content'] = wp_slash( (string) $fields['content'] ); }
        if ( array_key_exists( 'status', $fields ) ) { $postarr['post_status'] = (string) $fields['status']; }

        if ( count( $postarr ) > 1 ) {
            $updated = wp_update_post( $postarr, true );
            if ( is_wp_error( $updated ) ) { return $updated; }
        }
        self::apply_rank_math( $post->ID, $rank_math );
        clean_post_cache( $post->ID );
        $fresh = get_post( $post->ID );
        if ( $fresh ) { JAI_Backups::mark_payload_after( $backup['change_id'], self::snapshot( $fresh ) ); }
        return array( 'change_id' => $backup['change_id'], 'updated' => true, 'item' => self::get( $post->ID ) );
    }

    private static function restore_rank_math( $post_id, $snapshot ) {
        foreach ( self::rank_math_keys() as $name => $key ) {
            $entry = $snapshot[ $name ] ?? array( 'exists' => false, 'value' => null );
            if ( ! empty( $entry['exists'] ) ) {
                update_post_meta( $post_id, $key, $entry['value'] );
            } else {
                delete_post_meta( $post_id, $key );
            }
        }
    }

    public static function rollback( $change_id, $expected_revision_hash ) {
        $stored = JAI_Backups::get_payload( $change_id, 'content' );
        if ( is_wp_error( $stored ) ) { return $stored; }
        if ( ! empty( $stored['meta']['rolled_back'] ) ) {
            return new WP_Error( 'jai_change_already_rolled_back', 'This change has already been rolled back.', array( 'status' => 409 ) );
        }
        $post = self::ensure_editable_post( $stored['meta']['resource_id'] ?? 0 );
        if ( is_wp_error( $post ) ) { return $post; }
        $current = self::revision_hash( $post );
        $expected = strtolower( (string) $expected_revision_hash );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $current, $expected ) ) {
            return new WP_Error( 'jai_content_changed', 'Content changed after this update.', array( 'status' => 409, 'current_revision_hash' => $current ) );
        }
        $snap = $stored['payload'];
        $original = $snap['post'] ?? array();
        $restore = wp_update_post( array(
            'ID' => $post->ID,
            'post_status' => (string) ( $original['post_status'] ?? 'draft' ),
            'post_title' => wp_slash( (string) ( $original['post_title'] ?? '' ) ),
            'post_name' => (string) ( $original['post_name'] ?? '' ),
            'post_excerpt' => wp_slash( (string) ( $original['post_excerpt'] ?? '' ) ),
            'post_content' => wp_slash( (string) ( $original['post_content'] ?? '' ) ),
        ), true );
        if ( is_wp_error( $restore ) ) { return $restore; }
        self::restore_rank_math( $post->ID, $snap['rank_math'] ?? array() );
        clean_post_cache( $post->ID );
        JAI_Backups::mark_rolled_back( $change_id );
        return array( 'change_id' => (string) $change_id, 'restored' => true, 'item' => self::get( $post->ID ) );
    }

    private static function default_author_id() {
        $admins = get_users( array( 'role__in' => array( 'administrator' ), 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) );
        return ! empty( $admins ) ? (int) $admins[0] : 0;
    }

    public static function create( $post_type, $fields = array(), $rank_math = array(), $author_id = 0 ) {
        $post_type = sanitize_key( (string) $post_type );
        if ( ! in_array( $post_type, self::searchable_post_types(), true ) ) {
            return new WP_Error( 'jai_content_type_denied', 'This post type is not available for creation.', array( 'status' => 403 ) );
        }
        $fields = is_array( $fields ) ? $fields : array();
        $rank_math = is_array( $rank_math ) ? $rank_math : array();
        $unknown = array_diff( array_keys( $fields ), array( 'title','slug','excerpt','content','status' ) );
        if ( $unknown ) {
            return new WP_Error( 'jai_content_field_denied', 'Unsupported content field: ' . reset( $unknown ), array( 'status' => 400 ) );
        }
        $status = isset( $fields['status'] ) ? (string) $fields['status'] : 'draft';
        if ( ! in_array( $status, self::statuses(), true ) ) {
            return new WP_Error( 'jai_content_status_denied', 'Requested status is not allowed.', array( 'status' => 400 ) );
        }
        $author_id = absint( $author_id );
        if ( $author_id < 1 ) { $author_id = self::default_author_id(); }

        $postarr = array(
            'post_type' => $post_type,
            'post_status' => 'draft',
            'post_author' => $author_id,
            'post_title' => wp_slash( (string) ( $fields['title'] ?? '' ) ),
            'post_content' => wp_slash( (string) ( $fields['content'] ?? '' ) ),
            'post_excerpt' => wp_slash( (string) ( $fields['excerpt'] ?? '' ) ),
        );
        if ( array_key_exists( 'slug', $fields ) ) { $postarr['post_name'] = sanitize_title( (string) $fields['slug'] ); }

        $post_id = wp_insert_post( $postarr, true );
        if ( is_wp_error( $post_id ) ) { return $post_id; }
        $backup = JAI_Backups::create_payload( 'content_create', $post_id, array( 'created_post_id' => (int) $post_id ), 'content_create' );
        if ( is_wp_error( $backup ) ) { wp_trash_post( $post_id ); return $backup; }

        if ( 'draft' !== $status ) {
            $updated = wp_update_post( array( 'ID' => $post_id, 'post_status' => $status ), true );
            if ( is_wp_error( $updated ) ) { return $updated; }
        }
        self::apply_rank_math( $post_id, $rank_math );
        clean_post_cache( $post_id );
        $fresh = get_post( $post_id );
        if ( $fresh ) { JAI_Backups::mark_payload_after( $backup['change_id'], self::snapshot( $fresh ) ); }
        return array( 'change_id' => $backup['change_id'], 'created' => true, 'item' => self::get( $post_id ) );
    }

    public static function rollback_created( $change_id, $expected_revision_hash ) {
        $stored = JAI_Backups::get_payload( $change_id, 'content_create' );
        if ( is_wp_error( $stored ) ) { return $stored; }
        if ( ! empty( $stored['meta']['rolled_back'] ) ) {
            return new WP_Error( 'jai_change_already_rolled_back', 'This content creation has already been rolled back.', array( 'status' => 409 ) );
        }
        $post = self::ensure_editable_post( $stored['meta']['resource_id'] ?? 0 );
        if ( is_wp_error( $post ) ) { return $post; }
        $current = self::revision_hash( $post );
        $expected = strtolower( (string) $expected_revision_hash );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $current, $expected ) ) {
            return new WP_Error( 'jai_content_changed', 'Created content changed after creation.', array( 'status' => 409, 'current_revision_hash' => $current ) );
        }
        if ( ! wp_trash_post( $post->ID ) ) {
            return new WP_Error( 'jai_content_trash_failed', 'Could not move content to Trash.', array( 'status' => 500 ) );
        }
        JAI_Backups::mark_rolled_back( $change_id );
        return array( 'change_id' => (string) $change_id, 'post_id' => (int) $post->ID, 'trashed' => true );
    }
}
