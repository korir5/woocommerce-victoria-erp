<?php
declare(strict_types=1);

namespace VictoriaERPConnector\Admin;

use VictoriaERPConnector\Cron\SyncScheduler;
use VictoriaERPConnector\WooCommerce\ProductSync;

/**
 * Class ProductSyncPage
 *
 * Adds an admin page for manual product synchronization and displays
 * the last sync status.
 *
 * @package VictoriaERPConnector\Admin
 */
final class ProductSyncPage {
    public static function register(): void {
        if ( ! function_exists( 'is_plugin_active' ) || ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
            return;
        }

        add_submenu_page(
            'woocommerce',
            __( 'ERP Product Sync', 'victoria-erp-connector' ),
            __( 'Product Sync', 'victoria-erp-connector' ),
            'manage_woocommerce',
            'vec-product-sync',
            [ self::class, 'render_page' ]
        );

        add_action( 'admin_post_vec_product_sync', [ self::class, 'handle_manual_sync' ] );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'victoria-erp-connector' ) );
        }

        $last_sync = ProductSync::get_last_sync_time();
        $count = ProductSync::get_last_sync_count();
        $message = get_transient( 'vec_product_sync_message' );

        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
            delete_transient( 'vec_product_sync_message' );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Victoria ERP Product Synchronization', 'victoria-erp-connector' ) . '</h1>';
        echo '<p>' . esc_html__( 'This page allows you to manually synchronize WooCommerce products with ERP catalog data.', 'victoria-erp-connector' ) . '</p>';

        printf(
            '<p><strong>%s</strong> %s</p>',
            esc_html__( 'Last sync:', 'victoria-erp-connector' ),
            esc_html( $last_sync ?: __( 'Never', 'victoria-erp-connector' ) )
        );

        printf(
            '<p><strong>%s</strong> %d</p>',
            esc_html__( 'Products updated:', 'victoria-erp-connector' ),
            absint( $count )
        );

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="vec_product_sync">';
        wp_nonce_field( 'vec_product_sync_action', 'vec_product_sync_nonce' );
        submit_button( __( 'Run Product Sync Now', 'victoria-erp-connector' ), 'primary', 'vec_product_sync_submit' );
        echo '</form>';
        echo '</div>';
    }

    public static function handle_manual_sync(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions', 'victoria-erp-connector' ) );
        }

        if ( ! isset( $_POST['vec_product_sync_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['vec_product_sync_nonce'] ), 'vec_product_sync_action' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'victoria-erp-connector' ) );
        }

        $updated = ProductSync::manual_sync();
        delete_transient( 'vec_product_sync_message' );
        set_transient( 'vec_product_sync_message', sprintf( __( 'Product sync completed successfully. %d products updated.', 'victoria-erp-connector' ), $updated ), HOUR_IN_SECONDS );

        wp_redirect( add_query_arg( 'page', 'vec-product-sync', admin_url( 'admin.php' ) ) );
        exit;
    }
}
