<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-owner opt-in for narrowly scoped WordPress.org plugin/theme management.
 * The REST permission callback also requires a fresh HMAC signature.
 *
 * No arbitrary ZIP URLs, local files, deactivation, deletes, updates or
 * custom-plugin/theme source installs are available here.
 */
class JAI_Extensions {
    const OPTION = 'jai_extension_management_enabled';
    const MAX_ITEMS = 250;

    public static function enabled() {
        return 'yes' === get_option( self::OPTION, 'no' );
    }

    public static function valid_slug( $slug ) {
        if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,99}$/D', $slug ) ) {
            return new WP_Error( 'jai_bad_extension_slug', 'Use the exact WordPress.org slug: lowercase letters, numbers and hyphens only.', array( 'status' => 400 ) );
        }
        return $slug;
    }

    private static function valid_kind( $kind ) {
        if ( 'plugin' !== $kind && 'theme' !== $kind ) {
            return new WP_Error( 'jai_bad_extension_kind', 'Extension type must be plugin or theme.', array( 'status' => 400 ) );
        }
        return $kind;
    }

    private static function load_installer_files( $kind ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if ( 'plugin' === $kind ) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        } else {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
            require_once ABSPATH . 'wp-admin/includes/theme-install.php';
        }
    }

    private static function check_write_access( $kind, $confirmed, $operation ) {
        if ( true !== $confirmed ) {
            return new WP_Error( 'jai_extension_confirmation_required', 'Explicit confirmation of this site and extension is required.', array( 'status' => 403 ) );
        }
        if ( ! self::enabled() ) {
            return new WP_Error( 'jai_extension_management_disabled', 'Extension management is disabled on this site. A WordPress administrator must enable it under Tools > Jobayer AI Agent.', array( 'status' => 403 ) );
        }
        if ( is_multisite() ) {
            return new WP_Error( 'jai_extension_multisite_unsupported', 'Remote extension management is disabled on multisite installations.', array( 'status' => 403 ) );
        }
        if ( ! wp_is_file_mod_allowed( $operation ) ) {
            return new WP_Error( 'jai_extension_file_mods_disabled', 'WordPress does not allow this extension operation (DISALLOW_FILE_MODS or file-mod policy).', array( 'status' => 403 ) );
        }
        return true;
    }

    private static function plugin_file( $slug ) {
        self::load_installer_files( 'plugin' );
        $found = array();
        foreach ( get_plugins() as $file => $data ) {
            if ( dirname( $file ) === $slug || $file === $slug . '.php' ) {
                $found[] = $file;
            }
        }
        if ( count( $found ) > 1 ) {
            return new WP_Error( 'jai_ambiguous_plugin', 'More than one plugin entry matches this directory. Activate it manually in WordPress.', array( 'status' => 409 ) );
        }
        return count( $found ) ? $found[0] : '';
    }

    private static function official_package( $link, $kind, $slug ) {
        $parts = is_string( $link ) ? wp_parse_url( $link ) : false;
        if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' ||
            strtolower( $parts['host'] ?? '' ) !== 'downloads.wordpress.org' ||
            isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ||
            isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
            return new WP_Error( 'jai_nonofficial_extension_source', 'Only packages from the official WordPress.org download host are supported.', array( 'status' => 400 ) );
        }
        $path = (string) ( $parts['path'] ?? '' );
        if ( ! preg_match( '~^/' . preg_quote( $kind, '~' ) . '/' . preg_quote( $slug, '~' ) . '(?:\.[a-zA-Z0-9._-]+)?\.zip$~D', $path ) ) {
            return new WP_Error( 'jai_nonofficial_extension_source', 'WordPress.org returned an unexpected package path. Installation refused.', array( 'status' => 400 ) );
        }
        return $link;
    }

    private static function api_details( $kind, $slug ) {
        self::load_installer_files( $kind );
        if ( 'plugin' === $kind ) {
            $api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
        } else {
            $api = themes_api( 'theme_information', array( 'slug' => $slug ) );
        }
        if ( is_wp_error( $api ) || ! is_object( $api ) ) {
            return new WP_Error( 'jai_extension_not_found', 'Could not resolve this slug in the WordPress.org directory.', array( 'status' => 404 ) );
        }
        if ( empty( $api->slug ) || $slug !== $api->slug ) {
            return new WP_Error( 'jai_extension_slug_mismatch', 'WordPress.org returned a different slug; installation refused.', array( 'status' => 400 ) );
        }
        $source = self::official_package( $api->download_link ?? '', $kind, $slug );
        if ( is_wp_error( $source ) ) { return $source; }

        return array(
            'name' => sanitize_text_field( $api->name ?? $slug ),
            'version' => sanitize_text_field( $api->version ?? '' ),
            'requires' => sanitize_text_field( $api->requires ?? '' ),
            'requires_php' => sanitize_text_field( $api->requires_php ?? '' ),
            'source' => $source,
        );
    }

    public static function status() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed_plugins = array();
        foreach ( get_plugins() as $file => $plugin ) {
            $installed_plugins[] = array(
                'slug' => '.' === dirname( $file ) ? basename( $file, '.php' ) : dirname( $file ),
                'file' => $file,
                'name' => sanitize_text_field( $plugin['Name'] ?? '' ),
                'version' => sanitize_text_field( $plugin['Version'] ?? '' ),
                'active' => is_plugin_active( $file ),
            );
        }
        $installed_themes = array();
        $active_theme = get_stylesheet();
        foreach ( wp_get_themes() as $slug => $theme ) {
            $installed_themes[] = array(
                'slug' => $slug,
                'name' => $theme->get( 'Name' ),
                'version' => $theme->get( 'Version' ),
                'active' => $active_theme === $slug,
                'parent' => $theme->get( 'Template' ),
            );
        }
        return rest_ensure_response( array(
            'site_url' => site_url(),
            'enabled_by_site_admin' => self::enabled(),
            'multisite_supported' => ! is_multisite(),
            'plugins' => array_slice( $installed_plugins, 0, self::MAX_ITEMS ),
            'themes' => array_slice( $installed_themes, 0, self::MAX_ITEMS ),
            'capabilities' => array(
                'wordpress_org_install' => true,
                'activate_installed' => true,
                'delete' => false,
                'deactivate' => false,
                'update' => false,
                'custom_zip' => false,
                'custom_url' => false,
            ),
        ) );
    }

    public static function inspect( $kind, $slug ) {
        $type = self::valid_kind( $kind );
        if ( is_wp_error( $type ) ) { return $type; }
        $slug = self::valid_slug( $slug );
        if ( is_wp_error( $slug ) ) { return $slug; }
        $info = self::api_details( $kind, $slug );
        if ( is_wp_error( $info ) ) { return $info; }
        unset( $info['source'] );

        if ( 'plugin' === $kind ) {
            $file = self::plugin_file( $slug );
            if ( is_wp_error( $file ) ) { return $file; }
            $installed = '' !== $file;
            $active = $installed && is_plugin_active( $file );
        } else {
            $theme = wp_get_theme( $slug );
            $installed = $theme->exists();
            $active = $installed && get_stylesheet() === $slug;
        }

        return rest_ensure_response( array(
            'site_url' => site_url(), 'type' => $kind, 'slug' => $slug,
            'official_directory' => 'wordpress.org', 'details' => $info,
            'installed' => $installed, 'active' => $active,
            'management_enabled' => self::enabled(),
            'would_change_site' => false,
        ) );
    }

    private static function activate_installed( $kind, $slug ) {
        if ( 'plugin' === $kind ) {
            $file = self::plugin_file( $slug );
            if ( is_wp_error( $file ) ) { return $file; }
            if ( '' === $file ) {
                return new WP_Error( 'jai_extension_not_installed', 'Plugin must be installed before activation.', array( 'status' => 404 ) );
            }
            if ( is_plugin_active( $file ) ) {
                return array( 'changed' => false, 'active' => true, 'file' => $file );
            }
            $error = activate_plugin( $file );
            if ( is_wp_error( $error ) ) { return $error; }
            if ( ! is_plugin_active( $file ) ) {
                return new WP_Error( 'jai_extension_activate_failed', 'WordPress did not activate the plugin.', array( 'status' => 500 ) );
            }
            return array( 'changed' => true, 'active' => true, 'file' => $file );
        }

        $theme = wp_get_theme( $slug );
        if ( ! $theme->exists() || $theme->errors() ) {
            return new WP_Error( 'jai_extension_not_installed', 'Theme is missing, incomplete or invalid.', array( 'status' => 404 ) );
        }
        if ( get_stylesheet() === $slug ) {
            return array( 'changed' => false, 'active' => true );
        }
        switch_theme( $slug );
        if ( get_stylesheet() !== $slug ) {
            return new WP_Error( 'jai_extension_activate_failed', 'WordPress did not switch to the requested theme.', array( 'status' => 500 ) );
        }
        return array( 'changed' => true, 'active' => true );
    }

    public static function activate( $kind, $slug, $confirmed ) {
        $type = self::valid_kind( $kind );
        if ( is_wp_error( $type ) ) { return $type; }
        $slug = self::valid_slug( $slug );
        if ( is_wp_error( $slug ) ) { return $slug; }
        $gate = self::check_write_access( $kind, $confirmed, 'plugin' === $kind ? 'plugin_activate' : 'theme_activate' );
        if ( is_wp_error( $gate ) ) { return $gate; }

        if ( 'plugin' === $kind && 'jobayer-ai-agent' === $slug ) {
            return new WP_Error( 'jai_agent_self_management', 'Manage Jobayer AI Agent from its own WordPress Updates screen.', array( 'status' => 403 ) );
        }
        $activation = self::activate_installed( $kind, $slug );
        if ( is_wp_error( $activation ) ) { return $activation; }
        return rest_ensure_response( array_merge( array(
            'site_url' => site_url(), 'type' => $kind, 'slug' => $slug,
        ), $activation ) );
    }

    public static function install( $kind, $slug, $activate, $confirmed ) {
        $type = self::valid_kind( $kind );
        if ( is_wp_error( $type ) ) { return $type; }
        $slug = self::valid_slug( $slug );
        if ( is_wp_error( $slug ) ) { return $slug; }
        $gate = self::check_write_access( $kind, $confirmed, 'plugin' === $kind ? 'plugin_install' : 'theme_install' );
        if ( is_wp_error( $gate ) ) { return $gate; }

        if ( 'plugin' === $kind && 'jobayer-ai-agent' === $slug ) {
            return new WP_Error( 'jai_agent_self_management', 'Use the Agent GitHub updater rather than reinstalling itself.', array( 'status' => 403 ) );
        }
        if ( 'plugin' === $kind ) {
            $file = self::plugin_file( $slug );
            if ( is_wp_error( $file ) ) { return $file; }
            if ( '' !== $file ) {
                return new WP_Error( 'jai_extension_already_installed', 'This plugin is already installed. Use activate_extension if needed.', array( 'status' => 409 ) );
            }
        } else {
            if ( wp_get_theme( $slug )->exists() ) {
                return new WP_Error( 'jai_extension_already_installed', 'This theme is already installed. Use activate_extension if needed.', array( 'status' => 409 ) );
            }
        }

        $info = self::api_details( $kind, $slug );
        if ( is_wp_error( $info ) ) { return $info; }
        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = 'plugin' === $kind ? new Plugin_Upgrader( $skin ) : new Theme_Upgrader( $skin );

        // WordPress's native upgrader handles archive verification and filesystem access.
        // No retries: a timeout may occur AFTER an extension was installed.
        $ok = $upgrader->install( $info['source'] );
        if ( is_wp_error( $ok ) ) { return $ok; }
        if ( is_wp_error( $skin->result ) ) { return $skin->result; }
        if ( $skin->get_errors()->has_errors() || ! $ok ) {
            return new WP_Error( 'jai_extension_install_failed', 'WordPress installer failed. Check filesystem permissions and plugin/theme installation logs before retrying.', array( 'status' => 502 ) );
        }

        if ( 'plugin' === $kind ) {
            wp_clean_plugins_cache( true );
            $file = self::plugin_file( $slug );
            $installed = ! is_wp_error( $file ) && '' !== $file;
        } else {
            wp_clean_themes_cache( true );
            $installed = wp_get_theme( $slug )->exists();
        }
        if ( ! $installed ) {
            return new WP_Error( 'jai_extension_install_unverified', 'The installer returned success but the extension was not detected. Check the WordPress dashboard before retrying.', array( 'status' => 502 ) );
        }
        $activation = array( 'active' => false, 'changed' => false );
        if ( true === $activate ) {
            $activation = self::activate_installed( $kind, $slug );
            if ( is_wp_error( $activation ) ) { return $activation; }
        }
        return rest_ensure_response( array(
            'site_url' => site_url(), 'type' => $kind, 'slug' => $slug,
            'name' => $info['name'], 'version' => $info['version'],
            'installed' => true, 'activation' => $activation,
            'note' => 'Installation is complete; verify active status and site health via site_info.',
        ) );
    }
}
