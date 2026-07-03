<?php
declare(strict_types=1);

namespace VictoriaERPConnector\API;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Endpoints
 *
 * Registers additional REST endpoints used by external systems (for example
 * webhook receivers). Endpoints are protected using the plugin API key when set.
 *
 * @package VictoriaERPConnector\API
 */
final class Endpoints {
    /**
     * REST namespace used by the plugin.
     */
    private const NAMESPACE = 'vec/v1';

    /**
     * Register endpoints. Called from the plugin loader when appropriate.
     *
     * @return void
     */
    public static function register(): void {
        register_rest_route(
            self::NAMESPACE,
            '/webhook',
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'handle_webhook' ],
                'permission_callback' => [ self::class, 'permission_for_webhook' ],
            ]
        );
    }

    /**
     * Permission callback for the webhook endpoint.
     *
     * Verifies that the incoming request provides the configured API key in
     * the `X-VEC-API-KEY` header. Returns WP_Error on failure to allow WP
     * to return a proper HTTP status code.
     *
     * @param WP_REST_Request $request Incoming REST request.
     * @return true|WP_Error True when authorized, WP_Error otherwise.
     */
    public static function permission_for_webhook( WP_REST_Request $request ): true|WP_Error {
        $settings = get_option( 'vec_settings', [] );
        $configured = is_array( $settings ) && ! empty( $settings['api_key'] ) ? (string) $settings['api_key'] : '';

        if ( $configured === '' ) {
            return new WP_Error( 'vec_no_api_key', 'API key not configured', [ 'status' => 403 ] );
        }

        $provided = (string) $request->get_header( 'x-vec-api-key' );

        if ( ! hash_equals( $configured, $provided ) ) {
            return new WP_Error( 'vec_invalid_key', 'Invalid API key', [ 'status' => 401 ] );
        }

        return true;
    }

    /**
     * Handle incoming webhook requests from Victoria ERP.
     *
     * Expected JSON payload contains at minimum an `event` string and an
     * optional `data` object. This method delegates to integration classes
     * when they provide a matching handler; otherwise returns a descriptive
     * error.
     *
     * @param WP_REST_Request $request Incoming REST request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $body = $request->get_json_params();

        if ( ! is_array( $body ) || empty( $body['event'] ) ) {
            return new WP_Error( 'vec_invalid_payload', 'Invalid payload', [ 'status' => 400 ] );
        }

        $event = (string) $body['event'];
        $data  = $body['data'] ?? [];

        switch ( $event ) {
            case 'stock.updated':
                if ( class_exists( '\\VictoriaERPConnector\\WooCommerce\\Stock' ) ) {
                    if ( method_exists( '\\VictoriaERPConnector\\WooCommerce\\Stock', 'handle_webhook' ) ) {
                        $result = \VictoriaERPConnector\WooCommerce\Stock::handle_webhook( $data );
                    } else {
                        // Fallback to a generic sync trigger if available.
                        \VictoriaERPConnector\WooCommerce\Stock::sync_stock();
                        $result = true;
                    }

                    return rest_ensure_response( [ 'ok' => true, 'handled' => (bool) $result ] );
                }

                return new WP_Error( 'vec_no_stock_integration', 'Stock integration not available', [ 'status' => 501 ] );

            case 'pricing.updated':
                if ( class_exists( '\\VictoriaERPConnector\\WooCommerce\\Pricing' ) ) {
                    if ( method_exists( '\\VictoriaERPConnector\\WooCommerce\\Pricing', 'handle_webhook' ) ) {
                        $result = \VictoriaERPConnector\WooCommerce\Pricing::handle_webhook( $data );
                    } else {
                        \VictoriaERPConnector\WooCommerce\Pricing::sync_pricing();
                        $result = true;
                    }

                    return rest_ensure_response( [ 'ok' => true, 'handled' => (bool) $result ] );
                }

                return new WP_Error( 'vec_no_pricing_integration', 'Pricing integration not available', [ 'status' => 501 ] );

            default:
                return new WP_Error( 'vec_unknown_event', 'Unknown event type', [ 'status' => 400 ] );
        }
    }
}
