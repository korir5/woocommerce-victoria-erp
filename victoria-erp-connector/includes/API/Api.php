<?php
declare(strict_types=1);

namespace VictoriaERPConnector\API;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use VictoriaERPConnector\API\Endpoints;

/**
 * Class Api
 *
 * Registers and handles the plugin REST API routes under the `vec/v1` namespace.
 * Routes are intentionally minimal and delegate heavy lifting to integration
 * classes when available.
 *
 * @package VictoriaERPConnector\API
 */
final class Api {
    /**
     * REST namespace for the plugin.
     */
    private const NAMESPACE = 'vec/v1';

    /**
     * Register REST routes.
     *
     * Called during `rest_api_init`.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/status',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'status' ],
                'permission_callback' => [ $this, 'permission_check_read' ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/sync/stock',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'sync_stock' ],
                'permission_callback' => [ $this, 'permission_check_write' ],
                'args'                => [],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/sync/pricing',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'sync_pricing' ],
                'permission_callback' => [ $this, 'permission_check_write' ],
            ]
        );

        if ( class_exists( Endpoints::class ) ) {
            Endpoints::register();
        }
    }

    /**
     * Basic status endpoint returning plugin version and settings.
     *
     * @param WP_REST_Request $request Incoming request instance.
     * @return WP_REST_Response|WP_Error
     */
    public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $settings = get_option( 'vec_settings', [] );
        $safe_settings = is_array( $settings ) ? self::filter_status_settings( $settings ) : [];

        return rest_ensure_response(
            [
                'ok'       => true,
                'version'  => defined( '\VictoriaERPConnector\Plugin_Bootstrap::VERSION' ) ? \VictoriaERPConnector\Plugin_Bootstrap::VERSION : 'unknown',
                'settings' => $safe_settings,
            ]
        );
    }

    /**
     * Trigger a stock sync via the integration class.
     *
     * @param WP_REST_Request $request Incoming request instance.
     * @return WP_REST_Response|WP_Error
     */
    public function sync_stock( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        if ( class_exists( '\VictoriaERPConnector\WooCommerce\Stock' ) ) {
            \VictoriaERPConnector\WooCommerce\Stock::sync_stock_full();

            return rest_ensure_response( [ 'ok' => true, 'message' => 'Stock sync started' ] );
        }

        return new WP_Error( 'vec_no_stock', 'Stock integration not available', [ 'status' => 500 ] );
    }

    /**
     * Trigger a pricing sync via the integration class.
     *
     * @param WP_REST_Request $request Incoming request instance.
     * @return WP_REST_Response|WP_Error
     */
    public function sync_pricing( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        if ( class_exists( '\VictoriaERPConnector\WooCommerce\Pricing' ) ) {
            \VictoriaERPConnector\WooCommerce\Pricing::sync_pricing();

            return rest_ensure_response( [ 'ok' => true, 'message' => 'Pricing sync started' ] );
        }

        return new WP_Error( 'vec_no_pricing', 'Pricing integration not available', [ 'status' => 500 ] );
    }

    /**
     * Permission callback for read endpoints.
     *
     * @return bool True when the current user may read plugin status.
     */
    public function permission_check_read(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Permission callback for write endpoints.
     *
     * @return bool True when the current user may perform write actions.
     */
    public function permission_check_write(): bool {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
    }

    /**
     * Filter settings values returned by the status endpoint to avoid exposing secrets.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private static function filter_status_settings( array $settings ): array {
        $allowed_keys = [
            'base_url',
            'company_code',
            'api_timeout',
            'enable_logging',
            'enable_stock_sync',
            'enable_price_sync',
            'enable_product_sync',
            'enable_promotion_engine',
            'promotion_rules',
        ];

        return array_intersect_key( $settings, array_flip( $allowed_keys ) );
    }
}
