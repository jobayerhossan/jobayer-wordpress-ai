<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JAI_Elementor {
    const MAX_JSON_BYTES = 2097152; // 2 MB
    const MAX_ELEMENTS = 1500;
    const MAX_DEPTH = 30;

    private static function available() {
        return class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::instance()->documents );
    }

    private static function require_elementor() {
        if ( ! self::available() ) {
            return new WP_Error( 'jai_elementor_unavailable', 'Elementor is not active or its document API is unavailable.', array( 'status' => 503 ) );
        }
        return true;
    }

    public static function version() {
        return defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : false;
    }

    private static function admin_user_id() {
        $admins = get_users( array(
            'role__in' => array( 'administrator' ),
            'number' => 1,
            'fields' => 'ID',
            'orderby' => 'ID',
            'order' => 'ASC',
        ) );
        return ! empty( $admins ) ? (int) $admins[0] : 0;
    }

    private static function as_admin( $callback ) {
        $previous = get_current_user_id();
        $admin_id = self::admin_user_id();
        if ( $admin_id < 1 ) {
            return new WP_Error( 'jai_elementor_admin_missing', 'No administrator user is available for the Elementor save API.', array( 'status' => 500 ) );
        }
        wp_set_current_user( $admin_id );
        try {
            return call_user_func( $callback );
        } catch ( Throwable $e ) {
            return new WP_Error( 'jai_elementor_exception', $e->getMessage(), array( 'status' => 500 ) );
        } finally {
            wp_set_current_user( $previous );
        }
    }

    private static function document( $post_id ) {
        $ready = self::require_elementor();
        if ( is_wp_error( $ready ) ) { return $ready; }
        $post = get_post( absint( $post_id ) );
        if ( ! $post ) {
            return new WP_Error( 'jai_elementor_missing', 'Elementor document was not found.', array( 'status' => 404 ) );
        }
        $document = \Elementor\Plugin::instance()->documents->get( $post->ID );
        if ( ! $document ) {
            return new WP_Error( 'jai_elementor_unsupported', 'This content type is not supported by Elementor.', array( 'status' => 400 ) );
        }
        return $document;
    }

    private static function meta_keys() {
        return array(
            '_elementor_data',
            '_elementor_edit_mode',
            '_elementor_template_type',
            '_elementor_version',
            '_elementor_pro_version',
            '_elementor_page_settings',
            '_wp_page_template',
        );
    }

    private static function meta_snapshot( $post_id ) {
        $out = array();
        foreach ( self::meta_keys() as $key ) {
            $out[ $key ] = array(
                'exists' => metadata_exists( 'post', $post_id, $key ),
                'value' => get_post_meta( $post_id, $key, true ),
            );
        }
        return $out;
    }

    private static function snapshot( $post_id ) {
        $post = get_post( absint( $post_id ) );
        if ( ! $post ) { return array(); }
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
            'meta' => self::meta_snapshot( $post->ID ),
        );
    }

    private static function revision_hash( $post_id ) {
        $json = wp_json_encode( self::snapshot( $post_id ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return hash( 'sha256', false === $json ? '' : $json );
    }

    private static function page_settings( $post_id ) {
        $settings = get_post_meta( absint( $post_id ), '_elementor_page_settings', true );
        return is_array( $settings ) ? $settings : array();
    }

    private static function element_count( $elements ) {
        $count = 0;
        foreach ( (array) $elements as $element ) {
            if ( ! is_array( $element ) ) { continue; }
            ++$count;
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $count += self::element_count( $element['elements'] );
            }
        }
        return $count;
    }

    public static function get_document( $post_id ) {
        $document = self::document( $post_id );
        if ( is_wp_error( $document ) ) { return $document; }
        $post = get_post( absint( $post_id ) );
        $elements = $document->get_elements_data();
        if ( ! is_array( $elements ) ) { $elements = array(); }
        return array(
            'id' => (int) $post->ID,
            'post_type' => $post->post_type,
            'status' => $post->post_status,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'url' => get_permalink( $post ),
            'edit_url' => method_exists( $document, 'get_edit_url' ) ? $document->get_edit_url() : '',
            'built_with_elementor' => method_exists( $document, 'is_built_with_elementor' ) ? (bool) $document->is_built_with_elementor() : (bool) get_post_meta( $post->ID, '_elementor_edit_mode', true ),
            'template_type' => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
            'elementor_version' => self::version(),
            'revision_hash' => self::revision_hash( $post->ID ),
            'element_count' => self::element_count( $elements ),
            'page_settings' => self::page_settings( $post->ID ),
            'elements' => $elements,
        );
    }

    private static function is_list( $array ) {
        if ( function_exists( 'array_is_list' ) ) { return array_is_list( $array ); }
        $i = 0;
        foreach ( array_keys( $array ) as $key ) {
            if ( $key !== $i++ ) { return false; }
        }
        return true;
    }

    private static function merge_settings( $base, $patch ) {
        if ( ! is_array( $base ) ) { $base = array(); }
        if ( ! is_array( $patch ) ) { return $base; }
        foreach ( $patch as $key => $value ) {
            if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! self::is_list( $value ) && ! self::is_list( $base[ $key ] ) ) {
                $base[ $key ] = self::merge_settings( $base[ $key ], $value );
            } else {
                $base[ $key ] = $value;
            }
        }
        return $base;
    }

    private static function validate_tree( $elements, $path, $depth, &$count, &$ids, &$errors ) {
        if ( ! is_array( $elements ) || ! self::is_list( $elements ) ) {
            $errors[] = $path . ' must be a JSON array.';
            return;
        }
        if ( $depth > self::MAX_DEPTH ) {
            $errors[] = $path . ' exceeds the maximum nesting depth.';
            return;
        }
        foreach ( $elements as $index => $element ) {
            $item_path = $path . '[' . $index . ']';
            if ( ! is_array( $element ) ) {
                $errors[] = $item_path . ' must be an object.';
                continue;
            }
            ++$count;
            if ( $count > self::MAX_ELEMENTS ) {
                $errors[] = 'Element count exceeds the maximum of ' . self::MAX_ELEMENTS . '.';
                return;
            }
            $id = isset( $element['id'] ) ? (string) $element['id'] : '';
            if ( '' === $id || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ) {
                $errors[] = $item_path . '.id is missing or invalid.';
            } elseif ( isset( $ids[ $id ] ) ) {
                $errors[] = $item_path . '.id duplicates element ID ' . $id . '.';
            } else {
                $ids[ $id ] = true;
            }
            $el_type = isset( $element['elType'] ) ? (string) $element['elType'] : '';
            if ( ! in_array( $el_type, array( 'container', 'section', 'column', 'widget' ), true ) ) {
                $errors[] = $item_path . '.elType is unsupported.';
            }
            if ( 'widget' === $el_type && empty( $element['widgetType'] ) ) {
                $errors[] = $item_path . '.widgetType is required for widgets.';
            }
            if ( isset( $element['settings'] ) && ! is_array( $element['settings'] ) ) {
                $errors[] = $item_path . '.settings must be an object.';
            }
            if ( isset( $element['elements'] ) ) {
                self::validate_tree( $element['elements'], $item_path . '.elements', $depth + 1, $count, $ids, $errors );
            }
            if ( count( $errors ) >= 25 ) { return; }
        }
    }

    public static function validate_elements( $elements ) {
        $count = 0;
        $ids = array();
        $errors = array();
        self::validate_tree( $elements, 'elements', 1, $count, $ids, $errors );
        return array(
            'valid' => empty( $errors ),
            'element_count' => $count,
            'errors' => array_slice( $errors, 0, 25 ),
        );
    }

    private static function normalize_element( $element ) {
        if ( ! is_array( $element ) ) { return $element; }
        if ( empty( $element['id'] ) ) { $element['id'] = bin2hex( random_bytes( 4 ) ); }
        if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) { $element['settings'] = array(); }
        if ( ! isset( $element['elements'] ) || ! is_array( $element['elements'] ) ) { $element['elements'] = array(); }
        foreach ( $element['elements'] as $i => $child ) {
            $element['elements'][ $i ] = self::normalize_element( $child );
        }
        return $element;
    }

    private static function normalize_elements( $elements ) {
        if ( ! is_array( $elements ) ) { return $elements; }
        foreach ( $elements as $i => $element ) {
            $elements[ $i ] = self::normalize_element( $element );
        }
        return $elements;
    }

    private static function assert_revision( $post_id, $expected_revision_hash ) {
        $current = self::revision_hash( $post_id );
        $expected = strtolower( (string) $expected_revision_hash );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $current, $expected ) ) {
            return new WP_Error( 'jai_elementor_changed', 'Elementor document changed since it was read.', array( 'status' => 409, 'current_revision_hash' => $current ) );
        }
        return true;
    }

    private static function save( $post_id, $elements, $page_settings, $operation ) {
        $validation = self::validate_elements( $elements );
        if ( empty( $validation['valid'] ) ) {
            return new WP_Error( 'jai_elementor_invalid', 'Elementor data is invalid.', array( 'status' => 400, 'validation' => $validation ) );
        }
        $document = self::document( $post_id );
        if ( is_wp_error( $document ) ) { return $document; }
        $backup = JAI_Backups::create_payload( 'elementor', $post_id, self::snapshot( $post_id ), $operation );
        if ( is_wp_error( $backup ) ) { return $backup; }

        $saved = self::as_admin( function () use ( $document, $elements, $page_settings ) {
            if ( method_exists( $document, 'set_is_built_with_elementor' ) ) {
                $document->set_is_built_with_elementor( true );
            }
            $data = array( 'elements' => $elements );
            if ( null !== $page_settings ) { $data['settings'] = $page_settings; }
            return $document->save( $data );
        } );
        if ( is_wp_error( $saved ) ) { return $saved; }
        if ( true !== $saved ) {
            return new WP_Error( 'jai_elementor_save_failed', 'Elementor rejected the document save.', array( 'status' => 500, 'change_id' => $backup['change_id'] ) );
        }
        clean_post_cache( $post_id );
        JAI_Backups::mark_payload_after( $backup['change_id'], self::snapshot( $post_id ) );
        return array(
            'change_id' => $backup['change_id'],
            'updated' => true,
            'document' => self::get_document( $post_id ),
        );
    }

    public static function update_document( $post_id, $expected_revision_hash, $elements, $page_settings = null ) {
        $document = self::document( $post_id );
        if ( is_wp_error( $document ) ) { return $document; }
        $revision = self::assert_revision( $post_id, $expected_revision_hash );
        if ( is_wp_error( $revision ) ) { return $revision; }
        $elements = self::normalize_elements( $elements );
        return self::save( absint( $post_id ), $elements, is_array( $page_settings ) ? $page_settings : null, 'elementor_update_document' );
    }

    private static function default_author_id() {
        return self::admin_user_id();
    }

    public static function create_document( $args = array() ) {
        $ready = self::require_elementor();
        if ( is_wp_error( $ready ) ) { return $ready; }
        $args = is_array( $args ) ? $args : array();
        $post_type = sanitize_key( (string) ( $args['post_type'] ?? 'page' ) );
        $allowed_types = get_post_types( array( 'show_ui' => true ), 'names' );
        $allowed_types[] = 'elementor_library';
        $denied = array( 'attachment', 'revision', 'nav_menu_item', 'product_variation', 'shop_order', 'shop_order_refund' );
        if ( ! in_array( $post_type, $allowed_types, true ) || in_array( $post_type, $denied, true ) ) {
            return new WP_Error( 'jai_elementor_post_type_denied', 'Requested post type is not available for Elementor creation.', array( 'status' => 400 ) );
        }
        $status = (string) ( $args['status'] ?? 'draft' );
        if ( ! in_array( $status, array( 'draft', 'pending', 'private', 'publish' ), true ) ) {
            return new WP_Error( 'jai_elementor_status_denied', 'Requested status is not allowed.', array( 'status' => 400 ) );
        }
        $elements = self::normalize_elements( is_array( $args['elements'] ?? null ) ? $args['elements'] : array() );
        $validation = self::validate_elements( $elements );
        if ( empty( $validation['valid'] ) ) {
            return new WP_Error( 'jai_elementor_invalid', 'Elementor data is invalid.', array( 'status' => 400, 'validation' => $validation ) );
        }
        $postarr = array(
            'post_type' => $post_type,
            'post_status' => 'draft',
            'post_author' => absint( $args['author_id'] ?? 0 ) ?: self::default_author_id(),
            'post_title' => wp_slash( (string) ( $args['title'] ?? '' ) ),
            'post_content' => '',
        );
        if ( ! empty( $args['slug'] ) ) { $postarr['post_name'] = sanitize_title( (string) $args['slug'] ); }
        $post_id = wp_insert_post( $postarr, true );
        if ( is_wp_error( $post_id ) ) { return $post_id; }

        $backup = JAI_Backups::create_payload( 'elementor_create', $post_id, array( 'created_post_id' => (int) $post_id ), 'elementor_create_document' );
        if ( is_wp_error( $backup ) ) { wp_trash_post( $post_id ); return $backup; }

        if ( 'elementor_library' === $post_type ) {
            $template_type = sanitize_key( (string) ( $args['template_type'] ?? 'page' ) );
            update_post_meta( $post_id, '_elementor_template_type', $template_type ?: 'page' );
        }
        $document = self::document( $post_id );
        if ( is_wp_error( $document ) ) { wp_trash_post( $post_id ); return $document; }
        $settings = is_array( $args['page_settings'] ?? null ) ? $args['page_settings'] : array();
        $saved = self::as_admin( function () use ( $document, $elements, $settings ) {
            if ( method_exists( $document, 'set_is_built_with_elementor' ) ) { $document->set_is_built_with_elementor( true ); }
            return $document->save( array( 'elements' => $elements, 'settings' => $settings ) );
        } );
        if ( is_wp_error( $saved ) || true !== $saved ) {
            wp_trash_post( $post_id );
            return is_wp_error( $saved ) ? $saved : new WP_Error( 'jai_elementor_save_failed', 'Elementor rejected the new document.', array( 'status' => 500 ) );
        }
        if ( 'draft' !== $status ) {
            $updated = wp_update_post( array( 'ID' => $post_id, 'post_status' => $status ), true );
            if ( is_wp_error( $updated ) ) { return $updated; }
        }
        clean_post_cache( $post_id );
        JAI_Backups::mark_payload_after( $backup['change_id'], self::snapshot( $post_id ) );
        return array(
            'change_id' => $backup['change_id'],
            'created' => true,
            'document' => self::get_document( $post_id ),
        );
    }

    private static function find_element( $elements, $element_id ) {
        foreach ( (array) $elements as $element ) {
            if ( ! is_array( $element ) ) { continue; }
            if ( (string) ( $element['id'] ?? '' ) === (string) $element_id ) { return $element; }
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $found = self::find_element( $element['elements'], $element_id );
                if ( null !== $found ) { return $found; }
            }
        }
        return null;
    }

    public static function get_element( $post_id, $element_id ) {
        $doc = self::get_document( $post_id );
        if ( is_wp_error( $doc ) ) { return $doc; }
        $element = self::find_element( $doc['elements'], (string) $element_id );
        if ( null === $element ) {
            return new WP_Error( 'jai_elementor_element_missing', 'Element was not found.', array( 'status' => 404 ) );
        }
        return array(
            'post_id' => (int) $post_id,
            'revision_hash' => $doc['revision_hash'],
            'element' => $element,
        );
    }

    private static function update_element_tree( $elements, $element_id, $settings, $settings_mode, $widget_type, &$found ) {
        foreach ( $elements as $i => $element ) {
            if ( ! is_array( $element ) ) { continue; }
            if ( (string) ( $element['id'] ?? '' ) === (string) $element_id ) {
                if ( null !== $settings ) {
                    $element['settings'] = 'replace' === $settings_mode
                        ? ( is_array( $settings ) ? $settings : array() )
                        : self::merge_settings( is_array( $element['settings'] ?? null ) ? $element['settings'] : array(), is_array( $settings ) ? $settings : array() );
                }
                if ( null !== $widget_type ) {
                    if ( 'widget' !== ( $element['elType'] ?? '' ) ) {
                        return new WP_Error( 'jai_elementor_widget_type_denied', 'widget_type can only be changed on a widget.', array( 'status' => 400 ) );
                    }
                    $element['widgetType'] = sanitize_key( (string) $widget_type );
                }
                $elements[ $i ] = $element;
                $found = true;
                return $elements;
            }
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $child = self::update_element_tree( $element['elements'], $element_id, $settings, $settings_mode, $widget_type, $found );
                if ( is_wp_error( $child ) ) { return $child; }
                if ( $found ) {
                    $element['elements'] = $child;
                    $elements[ $i ] = $element;
                    return $elements;
                }
            }
        }
        return $elements;
    }

    public static function update_element( $post_id, $expected_revision_hash, $element_id, $settings = null, $settings_mode = 'merge', $widget_type = null ) {
        $doc = self::get_document( $post_id );
        if ( is_wp_error( $doc ) ) { return $doc; }
        $revision = self::assert_revision( $post_id, $expected_revision_hash );
        if ( is_wp_error( $revision ) ) { return $revision; }
        if ( ! in_array( $settings_mode, array( 'merge', 'replace' ), true ) ) { $settings_mode = 'merge'; }
        if ( null === $settings && null === $widget_type ) {
            return new WP_Error( 'jai_elementor_no_changes', 'No element changes were supplied.', array( 'status' => 400 ) );
        }
        $found = false;
        $elements = self::update_element_tree( $doc['elements'], (string) $element_id, $settings, $settings_mode, $widget_type, $found );
        if ( is_wp_error( $elements ) ) { return $elements; }
        if ( ! $found ) { return new WP_Error( 'jai_elementor_element_missing', 'Element was not found.', array( 'status' => 404 ) ); }
        return self::save( absint( $post_id ), $elements, null, 'elementor_update_element' );
    }

    private static function insert_into_tree( $elements, $parent_id, $new_element, $position, &$found ) {
        if ( null === $parent_id || '' === $parent_id ) {
            $position = max( 0, min( count( $elements ), (int) $position ) );
            array_splice( $elements, $position, 0, array( $new_element ) );
            $found = true;
            return $elements;
        }
        foreach ( $elements as $i => $element ) {
            if ( ! is_array( $element ) ) { continue; }
            if ( (string) ( $element['id'] ?? '' ) === (string) $parent_id ) {
                $children = is_array( $element['elements'] ?? null ) ? $element['elements'] : array();
                $position = max( 0, min( count( $children ), (int) $position ) );
                array_splice( $children, $position, 0, array( $new_element ) );
                $element['elements'] = $children;
                $elements[ $i ] = $element;
                $found = true;
                return $elements;
            }
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $child = self::insert_into_tree( $element['elements'], $parent_id, $new_element, $position, $found );
                if ( $found ) {
                    $element['elements'] = $child;
                    $elements[ $i ] = $element;
                    return $elements;
                }
            }
        }
        return $elements;
    }

    public static function add_element( $post_id, $expected_revision_hash, $parent_id, $element, $position = PHP_INT_MAX ) {
        $doc = self::get_document( $post_id );
        if ( is_wp_error( $doc ) ) { return $doc; }
        $revision = self::assert_revision( $post_id, $expected_revision_hash );
        if ( is_wp_error( $revision ) ) { return $revision; }
        $element = self::normalize_element( $element );
        $new_validation = self::validate_elements( array( $element ) );
        if ( empty( $new_validation['valid'] ) ) {
            return new WP_Error( 'jai_elementor_invalid_element', 'New element is invalid.', array( 'status' => 400, 'validation' => $new_validation ) );
        }
        $existing_ids = array();
        $count = 0; $errors = array();
        self::validate_tree( $doc['elements'], 'elements', 1, $count, $existing_ids, $errors );
        $incoming_ids = array();
        $count2 = 0; $errors2 = array();
        self::validate_tree( array( $element ), 'element', 1, $count2, $incoming_ids, $errors2 );
        foreach ( array_keys( $incoming_ids ) as $id ) {
            if ( isset( $existing_ids[ $id ] ) ) {
                return new WP_Error( 'jai_elementor_duplicate_id', 'New element contains an ID already used by the document: ' . $id, array( 'status' => 409 ) );
            }
        }
        if ( PHP_INT_MAX === $position ) { $position = 999999; }
        $found = false;
        $elements = self::insert_into_tree( $doc['elements'], null === $parent_id ? null : (string) $parent_id, $element, (int) $position, $found );
        if ( ! $found ) { return new WP_Error( 'jai_elementor_parent_missing', 'Parent element was not found.', array( 'status' => 404 ) ); }
        return self::save( absint( $post_id ), $elements, null, 'elementor_add_element' );
    }

    private static function remove_from_tree( $elements, $element_id, &$removed, &$found ) {
        foreach ( $elements as $i => $element ) {
            if ( ! is_array( $element ) ) { continue; }
            if ( (string) ( $element['id'] ?? '' ) === (string) $element_id ) {
                $removed = $element;
                array_splice( $elements, $i, 1 );
                $found = true;
                return $elements;
            }
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $children = self::remove_from_tree( $element['elements'], $element_id, $removed, $found );
                if ( $found ) {
                    $element['elements'] = $children;
                    $elements[ $i ] = $element;
                    return $elements;
                }
            }
        }
        return $elements;
    }

    public static function delete_element( $post_id, $expected_revision_hash, $element_id ) {
        $doc = self::get_document( $post_id );
        if ( is_wp_error( $doc ) ) { return $doc; }
        $revision = self::assert_revision( $post_id, $expected_revision_hash );
        if ( is_wp_error( $revision ) ) { return $revision; }
        $removed = null; $found = false;
        $elements = self::remove_from_tree( $doc['elements'], (string) $element_id, $removed, $found );
        if ( ! $found ) { return new WP_Error( 'jai_elementor_element_missing', 'Element was not found.', array( 'status' => 404 ) ); }
        return self::save( absint( $post_id ), $elements, null, 'elementor_delete_element' );
    }

    public static function move_element( $post_id, $expected_revision_hash, $element_id, $new_parent_id = null, $position = PHP_INT_MAX ) {
        $doc = self::get_document( $post_id );
        if ( is_wp_error( $doc ) ) { return $doc; }
        $revision = self::assert_revision( $post_id, $expected_revision_hash );
        if ( is_wp_error( $revision ) ) { return $revision; }
        $removed = null; $found = false;
        $elements = self::remove_from_tree( $doc['elements'], (string) $element_id, $removed, $found );
        if ( ! $found ) { return new WP_Error( 'jai_elementor_element_missing', 'Element was not found.', array( 'status' => 404 ) ); }
        if ( null !== $new_parent_id && '' !== $new_parent_id && null !== self::find_element( array( $removed ), (string) $new_parent_id ) ) {
            return new WP_Error( 'jai_elementor_move_cycle', 'An element cannot be moved inside itself or one of its descendants.', array( 'status' => 400 ) );
        }
        if ( PHP_INT_MAX === $position ) { $position = 999999; }
        $inserted = false;
        $elements = self::insert_into_tree( $elements, null === $new_parent_id ? null : (string) $new_parent_id, $removed, (int) $position, $inserted );
        if ( ! $inserted ) { return new WP_Error( 'jai_elementor_parent_missing', 'New parent element was not found.', array( 'status' => 404 ) ); }
        return self::save( absint( $post_id ), $elements, null, 'elementor_move_element' );
    }

    private static function decode_template( $json ) {
        $json = (string) $json;
        if ( '' === trim( $json ) ) {
            return new WP_Error( 'jai_elementor_json_missing', 'Elementor JSON is empty.', array( 'status' => 400 ) );
        }
        if ( strlen( $json ) > self::MAX_JSON_BYTES ) {
            return new WP_Error( 'jai_elementor_json_too_large', 'Elementor JSON exceeds the 2 MB limit.', array( 'status' => 413 ) );
        }
        $data = json_decode( $json, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
            return new WP_Error( 'jai_elementor_json_invalid', 'Elementor JSON could not be decoded: ' . json_last_error_msg(), array( 'status' => 400 ) );
        }
        $elements = null;
        if ( isset( $data['content'] ) && is_array( $data['content'] ) ) { $elements = $data['content']; }
        elseif ( isset( $data['elements'] ) && is_array( $data['elements'] ) ) { $elements = $data['elements']; }
        elseif ( self::is_list( $data ) ) { $elements = $data; }
        if ( null === $elements ) {
            return new WP_Error( 'jai_elementor_json_shape', 'JSON must contain an Elementor content/elements array.', array( 'status' => 400 ) );
        }
        $elements = self::normalize_elements( $elements );
        $validation = self::validate_elements( $elements );
        if ( empty( $validation['valid'] ) ) {
            return new WP_Error( 'jai_elementor_json_invalid', 'Elementor JSON contains invalid element data.', array( 'status' => 400, 'validation' => $validation ) );
        }
        return array(
            'elements' => $elements,
            'page_settings' => is_array( $data['page_settings'] ?? null ) ? $data['page_settings'] : array(),
            'title' => isset( $data['title'] ) ? (string) $data['title'] : '',
            'type' => isset( $data['type'] ) ? sanitize_key( (string) $data['type'] ) : '',
            'version' => isset( $data['version'] ) ? (string) $data['version'] : '',
            'validation' => $validation,
        );
    }

    private static function fetch_json_url( $url ) {
        $url = esc_url_raw( (string) $url );
        if ( ! $url || 0 !== strpos( strtolower( $url ), 'https://' ) ) {
            return new WP_Error( 'jai_elementor_json_url_invalid', 'Template source URL must use HTTPS.', array( 'status' => 400 ) );
        }
        $response = wp_safe_remote_get( $url, array( 'timeout' => 20, 'redirection' => 3, 'limit_response_size' => self::MAX_JSON_BYTES + 1 ) );
        if ( is_wp_error( $response ) ) { return $response; }
        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            return new WP_Error( 'jai_elementor_json_download_failed', 'Template download returned HTTP ' . $status . '.', array( 'status' => 400 ) );
        }
        $body = (string) wp_remote_retrieve_body( $response );
        if ( strlen( $body ) > self::MAX_JSON_BYTES ) {
            return new WP_Error( 'jai_elementor_json_too_large', 'Elementor JSON exceeds the 2 MB limit.', array( 'status' => 413 ) );
        }
        return $body;
    }

    public static function validate_json( $json = '', $source_url = '' ) {
        if ( $source_url ) {
            $json = self::fetch_json_url( $source_url );
            if ( is_wp_error( $json ) ) { return $json; }
        }
        $decoded = self::decode_template( $json );
        if ( is_wp_error( $decoded ) ) { return $decoded; }
        return array(
            'valid' => true,
            'title' => $decoded['title'],
            'type' => $decoded['type'],
            'version' => $decoded['version'],
            'element_count' => $decoded['validation']['element_count'],
        );
    }

    public static function import_json( $args = array() ) {
        $args = is_array( $args ) ? $args : array();
        $json = (string) ( $args['json'] ?? '' );
        if ( ! empty( $args['source_url'] ) ) {
            $json = self::fetch_json_url( $args['source_url'] );
            if ( is_wp_error( $json ) ) { return $json; }
        }
        $decoded = self::decode_template( $json );
        if ( is_wp_error( $decoded ) ) { return $decoded; }
        $post_id = absint( $args['post_id'] ?? 0 );
        if ( $post_id > 0 ) {
            $expected = (string) ( $args['expected_revision_hash'] ?? '' );
            $revision = self::assert_revision( $post_id, $expected );
            if ( is_wp_error( $revision ) ) { return $revision; }
            return self::save( $post_id, $decoded['elements'], $decoded['page_settings'], 'elementor_import_json' );
        }
        return self::create_document( array(
            'post_type' => $args['post_type'] ?? 'page',
            'status' => $args['status'] ?? 'draft',
            'title' => $args['title'] ?? $decoded['title'],
            'slug' => $args['slug'] ?? '',
            'template_type' => $args['template_type'] ?? ( $decoded['type'] ?: 'page' ),
            'elements' => $decoded['elements'],
            'page_settings' => $decoded['page_settings'],
        ) );
    }

    public static function export_json( $post_id ) {
        $doc = self::get_document( $post_id );
        if ( is_wp_error( $doc ) ) { return $doc; }
        $template = array(
            'content' => $doc['elements'],
            'page_settings' => $doc['page_settings'],
            'version' => '0.4',
            'title' => $doc['title'],
            'type' => $doc['template_type'] ?: 'page',
        );
        return array(
            'post_id' => (int) $post_id,
            'revision_hash' => $doc['revision_hash'],
            'template' => $template,
            'json' => wp_json_encode( $template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
        );
    }

    public static function regenerate_css( $post_id ) {
        $document = self::document( $post_id );
        if ( is_wp_error( $document ) ) { return $document; }
        if ( ! class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
            return new WP_Error( 'jai_elementor_css_unavailable', 'Elementor Post CSS API is unavailable.', array( 'status' => 503 ) );
        }
        try {
            $css = \Elementor\Core\Files\CSS\Post::create( absint( $post_id ) );
            $css->update();
            return array( 'post_id' => absint( $post_id ), 'regenerated' => true );
        } catch ( Throwable $e ) {
            return new WP_Error( 'jai_elementor_css_failed', $e->getMessage(), array( 'status' => 500 ) );
        }
    }

    private static function restore_meta( $post_id, $snapshot ) {
        foreach ( self::meta_keys() as $key ) {
            $entry = is_array( $snapshot[ $key ] ?? null ) ? $snapshot[ $key ] : array( 'exists' => false, 'value' => null );
            if ( ! empty( $entry['exists'] ) ) {
                $value = $entry['value'];
                if ( '_elementor_data' === $key && is_string( $value ) ) { $value = wp_slash( $value ); }
                update_post_meta( $post_id, $key, $value );
            } else {
                delete_post_meta( $post_id, $key );
            }
        }
    }

    public static function rollback( $change_id, $expected_revision_hash ) {
        $stored = JAI_Backups::get_payload( $change_id );
        if ( is_wp_error( $stored ) ) { return $stored; }
        if ( ! empty( $stored['meta']['rolled_back'] ) ) {
            return new WP_Error( 'jai_change_already_rolled_back', 'This Elementor change has already been rolled back.', array( 'status' => 409 ) );
        }
        $resource_type = (string) ( $stored['meta']['resource_type'] ?? '' );
        $post_id = absint( $stored['meta']['resource_id'] ?? 0 );
        if ( 'elementor_create' === $resource_type ) {
            $post = get_post( $post_id );
            if ( ! $post ) { return new WP_Error( 'jai_elementor_missing', 'Created Elementor document was not found.', array( 'status' => 404 ) ); }
            $revision = self::assert_revision( $post_id, $expected_revision_hash );
            if ( is_wp_error( $revision ) ) { return $revision; }
            if ( ! wp_trash_post( $post_id ) ) {
                return new WP_Error( 'jai_elementor_trash_failed', 'Could not move the created Elementor document to Trash.', array( 'status' => 500 ) );
            }
            JAI_Backups::mark_rolled_back( $change_id );
            return array( 'change_id' => (string) $change_id, 'post_id' => $post_id, 'trashed' => true );
        }
        if ( 'elementor' !== $resource_type ) {
            return new WP_Error( 'jai_change_wrong_resource', 'Snapshot is not an Elementor change.', array( 'status' => 400 ) );
        }
        $revision = self::assert_revision( $post_id, $expected_revision_hash );
        if ( is_wp_error( $revision ) ) { return $revision; }
        $snap = $stored['payload'];
        $original = is_array( $snap['post'] ?? null ) ? $snap['post'] : array();
        $restored = wp_update_post( array(
            'ID' => $post_id,
            'post_status' => (string) ( $original['post_status'] ?? 'draft' ),
            'post_title' => wp_slash( (string) ( $original['post_title'] ?? '' ) ),
            'post_name' => (string) ( $original['post_name'] ?? '' ),
            'post_excerpt' => wp_slash( (string) ( $original['post_excerpt'] ?? '' ) ),
            'post_content' => wp_slash( (string) ( $original['post_content'] ?? '' ) ),
        ), true );
        if ( is_wp_error( $restored ) ) { return $restored; }
        self::restore_meta( $post_id, is_array( $snap['meta'] ?? null ) ? $snap['meta'] : array() );
        clean_post_cache( $post_id );
        if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
            try { \Elementor\Core\Files\CSS\Post::create( $post_id )->update(); } catch ( Throwable $e ) { /* rollback data is still valid */ }
        }
        JAI_Backups::mark_rolled_back( $change_id );
        return array( 'change_id' => (string) $change_id, 'restored' => true, 'document' => self::get_document( $post_id ) );
    }
}
