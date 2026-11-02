<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JAI_Admin {
    const DEFAULT_MCP_URL = 'https://mcp.jobayerhossan.com';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_jai_rotate_secret', array( __CLASS__, 'rotate_secret' ) );
        add_action( 'admin_post_jai_connect_mcp', array( __CLASS__, 'connect_mcp' ) );
    }

    public static function menu() {
        add_management_page( 'Jobayer AI Agent', 'Jobayer AI Agent', 'manage_options', 'jobayer-ai-agent', array( __CLASS__, 'page' ) );
    }

    private static function ensure_credentials() {
        if ( ! get_option( 'jai_site_id' ) ) {
            update_option( 'jai_site_id', 'site_' . wp_generate_password( 18, false, false ), false );
        }
        if ( ! get_option( 'jai_site_secret' ) ) {
            update_option( 'jai_site_secret', bin2hex( random_bytes( 32 ) ), false );
        }
    }

    private static function default_alias() {
        $host = (string) wp_parse_url( site_url(), PHP_URL_HOST );
        $host = preg_replace( '/^www\./i', '', $host );
        return sanitize_key( str_replace( '.', '-', $host ) );
    }

    private static function notice( $type, $message ) {
        set_transient( 'jai_connect_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
    }

    public static function connect_mcp() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized.' ); }
        check_admin_referer( 'jai_connect_mcp' );
        self::ensure_credentials();

        $mcp_url = untrailingslashit( isset( $_POST['mcp_url'] ) ? esc_url_raw( wp_unslash( $_POST['mcp_url'] ) ) : self::DEFAULT_MCP_URL );
        $key = isset( $_POST['registration_key'] ) ? trim( (string) wp_unslash( $_POST['registration_key'] ) ) : '';
        $alias = isset( $_POST['site_alias'] ) ? sanitize_key( wp_unslash( $_POST['site_alias'] ) ) : self::default_alias();

        if ( ! $mcp_url || ! wp_http_validate_url( $mcp_url ) || '' === $key ) {
            self::notice( 'error', 'Enter a valid MCP server URL and registration key.' );
            wp_safe_redirect( admin_url( 'tools.php?page=jobayer-ai-agent' ) ); exit;
        }

        $payload = array(
            'base_url' => untrailingslashit( site_url() ),
            'site_id' => (string) get_option( 'jai_site_id', '' ),
            'secret' => (string) get_option( 'jai_site_secret', '' ),
            'alias' => $alias ?: self::default_alias(),
            'site_name' => get_bloginfo( 'name' ),
        );

        $response = wp_remote_post( $mcp_url . '/register-site', array(
            'timeout' => 20,
            'sslverify' => true,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-JAI-Registration-Key' => $key,
            ),
            'body' => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            self::notice( 'error', 'Connection failed: ' . $response->get_error_message() );
            wp_safe_redirect( admin_url( 'tools.php?page=jobayer-ai-agent' ) ); exit;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $status < 200 || $status >= 300 || ! is_array( $data ) || empty( $data['connected'] ) ) {
            $message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'The MCP server rejected the registration request.';
            self::notice( 'error', 'Connection failed: ' . sanitize_text_field( $message ) );
            wp_safe_redirect( admin_url( 'tools.php?page=jobayer-ai-agent' ) ); exit;
        }

        update_option( 'jai_mcp_url', $mcp_url, false );
        update_option( 'jai_mcp_alias', sanitize_key( $data['alias'] ?? $alias ), false );
        update_option( 'jai_mcp_connected_at', gmdate( 'c' ), false );
        self::notice( 'success', 'Connected successfully. This site is now available to Jobayer WordPress AI.' );
        wp_safe_redirect( admin_url( 'tools.php?page=jobayer-ai-agent' ) ); exit;
    }

    public static function rotate_secret() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized.' ); }
        check_admin_referer( 'jai_rotate_secret' );
        update_option( 'jai_site_secret', bin2hex( random_bytes( 32 ) ), false );
        delete_option( 'jai_mcp_connected_at' );
        self::notice( 'warning', 'Site secret rotated. Reconnect this site afterward.' );
        wp_safe_redirect( admin_url( 'tools.php?page=jobayer-ai-agent' ) ); exit;
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        self::ensure_credentials();

        $site_id = (string) get_option( 'jai_site_id', '' );
        $secret = (string) get_option( 'jai_site_secret', '' );
        $mcp_url = (string) get_option( 'jai_mcp_url', self::DEFAULT_MCP_URL );
        $alias = (string) get_option( 'jai_mcp_alias', self::default_alias() );
        $connected_at = (string) get_option( 'jai_mcp_connected_at', '' );
        $notice = get_transient( 'jai_connect_notice_' . get_current_user_id() );
        delete_transient( 'jai_connect_notice_' . get_current_user_id() );
        ?>
        <div class="wrap">
            <h1>Jobayer AI Agent</h1>
            <p>Connect this WordPress site to your private Jobayer WordPress AI MCP server without manually copying Site IDs or secrets.</p>

            <?php if ( is_array( $notice ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( 'success' === $notice['type'] ? 'success' : ( 'warning' === $notice['type'] ? 'warning' : 'error' ) ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
            <?php endif; ?>

            <h2>Connection</h2>
            <table class="form-table" role="presentation">
                <tr><th>Status</th><td><?php echo $connected_at ? '<strong style="color:#008a20">Connected</strong>' : '<strong>Not connected</strong>'; ?></td></tr>
                <?php if ( $connected_at ) : ?>
                    <tr><th>Alias</th><td><code><?php echo esc_html( $alias ); ?></code></td></tr>
                    <tr><th>MCP Server</th><td><code><?php echo esc_html( $mcp_url ); ?></code></td></tr>
                <?php endif; ?>
            </table>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="jai_connect_mcp">
                <?php wp_nonce_field( 'jai_connect_mcp' ); ?>
                <table class="form-table" role="presentation">
                    <tr><th>MCP Server URL</th><td><input name="mcp_url" class="regular-text code" value="<?php echo esc_attr( $mcp_url ); ?>" required></td></tr>
                    <tr><th>Site Alias</th><td><input name="site_alias" class="regular-text code" value="<?php echo esc_attr( $alias ); ?>"><p class="description">Example: wptoolix, onehorizon, client-site.</p></td></tr>
                    <tr><th>Registration Key</th><td><input name="registration_key" type="password" class="regular-text code" autocomplete="off" required><p class="description">Used only for this connection request and not saved on this WordPress site.</p></td></tr>
                </table>
                <?php submit_button( $connected_at ? 'Reconnect to Jobayer WordPress AI' : 'Connect to Jobayer WordPress AI', 'primary' ); ?>
            </form>

            <details style="margin-top:24px">
                <summary><strong>Advanced credentials and diagnostics</strong></summary>
                <table class="form-table" role="presentation">
                    <tr><th>Site URL</th><td><code><?php echo esc_html( site_url() ); ?></code></td></tr>
                    <tr><th>Site ID</th><td><input class="regular-text code" readonly value="<?php echo esc_attr( $site_id ); ?>"></td></tr>
                    <tr><th>Site Secret</th><td><input class="large-text code" readonly value="<?php echo esc_attr( $secret ); ?>"></td></tr>
                    <tr><th>PHP validation</th><td><?php echo esc_html( JAI_Files::php_validation_method() ?: 'Unavailable' ); ?></td></tr>
                </table>
            </details>

            <h2>Safety</h2>
            <p>Code edits are limited to theme/plugin source files, PHP changes are syntax checked, and connector changes use encrypted backups and rollback. Arbitrary SQL and shell execution are not exposed.</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Rotate the site secret? You will need to reconnect this site afterward.');">
                <input type="hidden" name="action" value="jai_rotate_secret">
                <?php wp_nonce_field( 'jai_rotate_secret' ); ?>
                <?php submit_button( 'Rotate Site Secret', 'secondary' ); ?>
            </form>
        </div>
        <?php
    }
}
