<?php
declare(strict_types=1);

namespace VictoriaERPConnector\Admin;

use VictoriaERPConnector\Admin\ProductSyncPage;
use VictoriaERPConnector\Admin\Settings;

/**
 * Class Admin
 *
 * Responsible for registering admin menu pages and handling admin actions
 * such as the 'Test ERP Connection' request. Delegates settings registration
 * and rendering to the `Settings` class.
 *
 * @package VictoriaERPConnector\Admin
 */
final class Admin {
    /**
     * Capability required to manage plugin settings.
     */
    private const CAPABILITY = 'manage_options';

    /**
     * Register admin pages. Intended to be called on the `admin_menu` hook.
     *
     * @return void
     */
    public function register_pages(): void {
        // Ensure settings are registered before rendering the page.
        Settings::register();

        // Add a submenu under WooCommerce if available, otherwise under Settings.
        if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
            add_submenu_page(
                'woocommerce',
                'Victoria ERP',
                'Victoria ERP',
                self::CAPABILITY,
                'vec-settings',
                [ $this, 'render_settings_page' ]
            );

            if ( class_exists( ProductSyncPage::class ) ) {
                ProductSyncPage::register();
            }
        } else {
            add_options_page(
                'Victoria ERP',
                'Victoria ERP',
                self::CAPABILITY,
                'vec-settings',
                [ $this, 'render_settings_page' ]
            );
        }

        // Handle test connection POST action.
        add_action( 'admin_post_vec_test_connection', [ $this, 'handle_test_connection' ] );
    }

    /**
     * Render the settings page. Outputs the Settings API form.
     *
     * @return void
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'victoria-erp-connector' ) );
        }

        // Show settings errors (if any) registered via Settings API or admin_post.
        settings_errors( 'vec_messages' );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Victoria ERP Connector Settings', 'victoria-erp-connector' ) . '</h1>';

        echo '<form method="post" action="options.php">';
        settings_fields( 'vec_options_group' );
        do_settings_sections( 'vec_settings_page' );
        submit_button( __( 'Save Settings', 'victoria-erp-connector' ) );
        echo '</form>';

        // Test connection button: separate form to trigger admin_post handler.
        $this->render_test_connection_form();

        echo '</div>';
    }

    /**
     * Output the Test ERP Connection form.
     *
     * @return void
     */
    private function render_test_connection_form(): void {
        $action = esc_url( admin_url( 'admin-post.php' ) );
        $nonce = wp_create_nonce( 'vec_test_connection' );

        echo '<h2>' . esc_html__( 'Test ERP Connection', 'victoria-erp-connector' ) . '</h2>';
        echo '<form method="post" action="' . $action . '">';
        echo '<input type="hidden" name="action" value="vec_test_connection">';
        echo '<input type="hidden" name="vec_nonce" value="' . esc_attr( $nonce ) . '">';
        submit_button( __( 'Test ERP Connection', 'victoria-erp-connector' ), 'secondary', 'vec_test_button' );
        echo '</form>';
    }

    /**
     * Handle the admin_post action for `vec_test_connection`.
     *
     * Verifies nonce and capability then performs a remote request using
     * current settings. Redirects back to the settings page with a status
     * message.
     *
     * @return void
     */
    public function handle_test_connection(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Insufficient permissions', 'victoria-erp-connector' ) );
        }

        $nonce = sanitize_text_field( (string) filter_input( INPUT_POST, 'vec_nonce', FILTER_DEFAULT ) );
        if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'vec_test_connection' ) ) {
            wp_die( esc_html__( 'Invalid request nonce', 'victoria-erp-connector' ) );
        }

        $result = Settings::test_connection();

        if ( is_wp_error( $result ) ) {
            add_settings_error( 'vec_messages', 'vec_test_error', $result->get_error_message(), 'error' );
        } else {
            add_settings_error( 'vec_messages', 'vec_test_success', esc_html__( 'Connection to ERP succeeded.', 'victoria-erp-connector' ), 'updated' );
        }

        // Redirect back to settings page.
        $redirect = add_query_arg( 'page', 'vec-settings', admin_url( 'admin.php' ) );
        wp_redirect( $redirect );
        exit;
    }
}

