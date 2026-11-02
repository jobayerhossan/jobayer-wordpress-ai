<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JAI_Updater {
    const REPO = 'jobayerhossan/jobayer-wordpress-ai';
    const API_URL = 'https://api.github.com/repos/jobayerhossan/jobayer-wordpress-ai/releases/latest';
    const CACHE_KEY = 'jai_agent_github_release_v1';
    const SLUG = 'jobayer-ai-agent';

    public static function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache_after_upgrade' ), 10, 2 );
    }

    private static function plugin_basename() {
        return plugin_basename( JAI_AGENT_FILE );
    }

    private static function normalize_version( $tag ) {
        $version = ltrim( trim( (string) $tag ), "vV \t\n\r\0\x0B" );
        return preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ? $version : '';
    }

    private static function latest_release( $force = false ) {
        if ( ! $force ) {
            $cached = get_site_transient( self::CACHE_KEY );
            if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
                return $cached;
            }
        }

        $response = wp_safe_remote_get( self::API_URL, array(
            'timeout' => 12,
            'redirection' => 3,
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Jobayer-WordPress-AI/' . JAI_AGENT_VERSION,
                'X-GitHub-Api-Version' => '2022-11-28',
            ),
        ) );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
            return false;
        }

        $version = self::normalize_version( $data['tag_name'] );
        if ( '' === $version ) {
            return false;
        }

        $package = '';
        foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
            if ( isset( $asset['name'], $asset['browser_download_url'] ) && 'jobayer-ai-agent.zip' === $asset['name'] ) {
                $package = esc_url_raw( $asset['browser_download_url'] );
                break;
            }
        }
        if ( '' === $package ) {
            return false;
        }

        $release = array(
            'version' => $version,
            'package' => $package,
            'html_url' => esc_url_raw( $data['html_url'] ?? 'https://github.com/' . self::REPO . '/releases' ),
            'body' => (string) ( $data['body'] ?? '' ),
            'published_at' => sanitize_text_field( $data['published_at'] ?? '' ),
        );

        set_site_transient( self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );
        return $release;
    }

    private static function update_object( $release ) {
        return (object) array(
            'id' => 'https://github.com/' . self::REPO,
            'slug' => self::SLUG,
            'plugin' => self::plugin_basename(),
            'new_version' => $release['version'],
            'url' => $release['html_url'],
            'package' => $release['package'],
            'requires' => '6.4',
            'requires_php' => '8.0',
            'tested' => '7.1',
        );
    }

    public static function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        $release = self::latest_release();
        if ( ! is_array( $release ) ) {
            return $transient;
        }

        $plugin = self::plugin_basename();
        if ( version_compare( $release['version'], JAI_AGENT_VERSION, '>' ) ) {
            $transient->response[ $plugin ] = self::update_object( $release );
            if ( isset( $transient->no_update[ $plugin ] ) ) {
                unset( $transient->no_update[ $plugin ] );
            }
        } else {
            $transient->no_update[ $plugin ] = self::update_object( $release );
        }

        return $transient;
    }

    public static function plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::SLUG ) {
            return $result;
        }

        $release = self::latest_release();
        if ( ! is_array( $release ) ) {
            return $result;
        }

        $changelog = '' !== trim( $release['body'] )
            ? nl2br( esc_html( $release['body'] ) )
            : 'See the GitHub release notes for details.';

        return (object) array(
            'name' => 'Jobayer AI Agent',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => '<a href="https://jobayerhossan.com">Jobayer Hossan</a>',
            'homepage' => 'https://github.com/' . self::REPO,
            'requires' => '6.4',
            'requires_php' => '8.0',
            'tested' => '7.1',
            'download_link' => $release['package'],
            'sections' => array(
                'description' => 'Secure WordPress AI development agent with code/content editing, media upload, backups, rollback, diagnostics, and MCP registration.',
                'changelog' => $changelog,
            ),
        );
    }

    public static function clear_cache_after_upgrade( $upgrader, $options ) {
        if ( isset( $options['type'] ) && 'plugin' === $options['type'] ) {
            delete_site_transient( self::CACHE_KEY );
        }
    }
}
