<?php
declare(strict_types=1);

namespace VictoriaERPConnector\WooCommerce;

use InvalidArgumentException;
use RuntimeException;
use VictoriaERPConnector\API\ERPClient;
use VictoriaERPConnector\Plugin_Bootstrap;
use WP_User;

/**
 * Class MyAccount
 *
 * Adds My Account endpoints for ERP customer data, purchase history and quote
 * tracking. Templates are rendered from the plugin templates directory.
 */
final class MyAccount {
    public const ENDPOINT_PURCHASE_HISTORY = 'vec_purchase_history';
    public const ENDPOINT_QUOTATION_TRACKER = 'vec_quotation_tracker';
    public const ENDPOINT_ERP_PROFILE = 'vec_erp_profile';

    public static function register_hooks(): void {
        add_action( 'init', [ self::class, 'register_endpoints' ] );
        add_filter( 'query_vars', [ self::class, 'add_query_vars' ] );
        add_filter( 'woocommerce_account_menu_items', [ self::class, 'add_menu_items' ] );
        add_filter( 'the_title', [ self::class, 'endpoint_title' ], 10, 2 );
        add_action( 'woocommerce_account_' . self::ENDPOINT_PURCHASE_HISTORY . '_endpoint', [ self::class, 'render_purchase_history' ] );
        add_action( 'woocommerce_account_' . self::ENDPOINT_QUOTATION_TRACKER . '_endpoint', [ self::class, 'render_quotation_tracker' ] );
        add_action( 'woocommerce_account_' . self::ENDPOINT_ERP_PROFILE . '_endpoint', [ self::class, 'render_erp_profile' ] );
    }

    public static function register_endpoints(): void {
        add_rewrite_endpoint( self::ENDPOINT_PURCHASE_HISTORY, EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( self::ENDPOINT_QUOTATION_TRACKER, EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( self::ENDPOINT_ERP_PROFILE, EP_ROOT | EP_PAGES );
    }

    public static function add_query_vars( array $vars ): array {
        $vars[] = self::ENDPOINT_PURCHASE_HISTORY;
        $vars[] = self::ENDPOINT_QUOTATION_TRACKER;
        $vars[] = self::ENDPOINT_ERP_PROFILE;
        return $vars;
    }

    public static function add_menu_items( array $items ): array {
        $new_items = [
            self::ENDPOINT_PURCHASE_HISTORY => __( 'Purchase History', 'victoria-erp-connector' ),
            self::ENDPOINT_QUOTATION_TRACKER => __( 'Quotation Tracker', 'victoria-erp-connector' ),
            self::ENDPOINT_ERP_PROFILE => __( 'ERP Profile', 'victoria-erp-connector' ),
        ];

        $ordered = [];
        foreach ( $items as $key => $label ) {
            $ordered[ $key ] = $label;
            if ( 'orders' === $key ) {
                foreach ( $new_items as $endpoint => $endpoint_label ) {
                    $ordered[ $endpoint ] = $endpoint_label;
                }
            }
        }

        return $ordered;
    }

    public static function endpoint_title( string $title, int $id ): string {
        if ( is_admin() ) {
            return $title;
        }

        if ( ! is_wc_endpoint_url( self::ENDPOINT_PURCHASE_HISTORY ) && ! is_wc_endpoint_url( self::ENDPOINT_QUOTATION_TRACKER ) && ! is_wc_endpoint_url( self::ENDPOINT_ERP_PROFILE ) ) {
            return $title;
        }

        if ( is_wc_endpoint_url( self::ENDPOINT_PURCHASE_HISTORY ) ) {
            return __( 'Purchase History', 'victoria-erp-connector' );
        }

        if ( is_wc_endpoint_url( self::ENDPOINT_QUOTATION_TRACKER ) ) {
            return __( 'Quotation Tracker', 'victoria-erp-connector' );
        }

        if ( is_wc_endpoint_url( self::ENDPOINT_ERP_PROFILE ) ) {
            return __( 'ERP Profile', 'victoria-erp-connector' );
        }

        return $title;
    }

    public static function render_purchase_history(): void {
        if ( ! is_user_logged_in() ) {
            echo '<p>' . esc_html__( 'You must be logged in to view purchase history.', 'victoria-erp-connector' ) . '</p>';
            return;
        }

        try {
            $customer_identifier = self::get_customer_identifier();
            $client = new ERPClient();
            $history = $client->getCustomerHistory( $customer_identifier );
        } catch ( \Throwable $e ) {
            $history = [];
            $error = $e->getMessage();
        }

        self::render_template(
            'my-account/purchase-history.php',
            [
                'history' => $history,
                'error'   => $error ?? '',
            ]
        );
    }

    public static function render_quotation_tracker(): void {
        if ( ! is_user_logged_in() ) {
            echo '<p>' . esc_html__( 'You must be logged in to track quotations.', 'victoria-erp-connector' ) . '</p>';
            return;
        }

        $quote_id = '';
        $status = null;
        $error = '';

        if ( isset( $_POST['vec_quote_id'], $_POST['vec_quote_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['vec_quote_nonce'] ), 'vec_quote_tracker' ) ) {
            $quote_id = sanitize_text_field( wp_unslash( $_POST['vec_quote_id'] ) );
            if ( $quote_id !== '' ) {
                try {
                    $client = new ERPClient();
                    $status = $client->getQuoteStatus( $quote_id );
                } catch ( \Throwable $e ) {
                    $error = $e->getMessage();
                }
            }
        }

        self::render_template(
            'my-account/quotation-tracker.php',
            [
                'quote_id' => $quote_id,
                'status'   => $status,
                'error'    => $error,
            ]
        );
    }

    public static function render_erp_profile(): void {
        if ( ! is_user_logged_in() ) {
            echo '<p>' . esc_html__( 'You must be logged in to view ERP profile.', 'victoria-erp-connector' ) . '</p>';
            return;
        }

        try {
            $customer_identifier = self::get_customer_identifier();
            $client = new ERPClient();
            $profile = $client->getCustomer( $customer_identifier );
        } catch ( \Throwable $e ) {
            $profile = [];
            $error = $e->getMessage();
        }

        self::render_template(
            'my-account/erp-profile.php',
            [
                'profile' => $profile,
                'error'   => $error ?? '',
            ]
        );
    }

    private static function get_customer_identifier(): string {
        $user = wp_get_current_user();
        if ( ! $user instanceof WP_User || 0 === $user->ID ) {
            throw new RuntimeException( 'User is not logged in.' );
        }

        $identifier = get_user_meta( $user->ID, 'vec_erp_customer_id', true );
        if ( is_string( $identifier ) && trim( $identifier ) !== '' ) {
            return trim( $identifier );
        }

        if ( is_string( $user->user_email ) && $user->user_email !== '' ) {
            return $user->user_email;
        }

        throw new RuntimeException( 'Unable to resolve ERP customer identifier.' );
    }

    private static function render_template( string $template, array $data = [] ): void {
        $template_path = self::get_templates_dir() . $template;
        if ( function_exists( 'wc_get_template' ) ) {
            wc_get_template( $template, $data, '', self::get_templates_dir() );
            return;
        }

        if ( is_file( $template_path ) ) {
            extract( $data, EXTR_SKIP );
            include $template_path;
            return;
        }

        echo '<p>' . esc_html__( 'Template not found.', 'victoria-erp-connector' ) . '</p>';
    }

    private static function get_templates_dir(): string {
        return Plugin_Bootstrap::PLUGIN_DIR . '/templates/';
    }
}
