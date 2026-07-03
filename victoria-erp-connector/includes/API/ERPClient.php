<?php
declare(strict_types=1);

namespace VictoriaERPConnector\API;

use InvalidArgumentException;
use RuntimeException;
use WP_Error;
use function VictoriaERPConnector\Logger\vec_log_api;
use function VictoriaERPConnector\Logger\vec_log_error;
use function VictoriaERPConnector\Logger\vec_log_debug;
use function VictoriaERPConnector\Cache\vec_cache_remember;
use function VictoriaERPConnector\Cache\vec_cache_forget;
use function VictoriaERPConnector\Cache\vec_cache_flush;

/**
 * Class ERPClient
 *
 * Lightweight HTTP client for Victoria ERP. Reads connection settings from
 * the plugin settings and performs safe GET requests using `wp_remote_get()`.
 *
 * All methods validate parameters, handle `WP_Error`, decode JSON responses
 * and throw exceptions on failure. Successful responses are returned as typed
 * arrays.
 *
 * @package VictoriaERPConnector\API
 */
final class ERPClient {
    /**
     * Settings option name containing connection configuration.
     *
     * @var string
     */
    private const OPTION_NAME = '\\VictoriaERPConnector\\Admin\\Settings::OPTION_NAME';

    /**
     * Default request timeout in seconds used when settings do not provide one.
     */
    private const DEFAULT_TIMEOUT = 15;

    /**
     * Perform a stock lookup by SKU.
     *
     * @param string $sku SKU or product identifier. Must be non-empty.
     * @return array<string,mixed> Typed array containing ERP stock information.
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException On HTTP, decode or settings errors.
     */
    public function getStock(string $sku): array {
        $sku = trim($sku);
        if ($sku === '') {
            throw new InvalidArgumentException('SKU must be a non-empty string.');
        }

        return $this->request('stock', ['sku' => $sku]);
    }

    /**
     * Retrieve the selling price for a product.
     *
     * @param string $productId Product identifier in ERP.
     * @return array<string,mixed>
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getSellingPrice(string $productId): array {
        $productId = trim($productId);
        if ($productId === '') {
            throw new InvalidArgumentException('Product ID must be a non-empty string.');
        }

        return $this->request('pricing/selling', ['product_id' => $productId]);
    }

    /**
     * Retrieve the offer price for a product (promotional price).
     *
     * @param string $productId Product identifier in ERP.
     * @return array<string,mixed>
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getOfferPrice(string $productId): array {
        $productId = trim($productId);
        if ($productId === '') {
            throw new InvalidArgumentException('Product ID must be a non-empty string.');
        }

        return $this->request('pricing/offer', ['product_id' => $productId]);
    }

    /**
     * Retrieve customer details by identifier.
     *
     * @param string $customerId Customer identifier in ERP.
     * @return array<string,mixed>
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getCustomer(string $customerId): array {
        $customerId = trim($customerId);
        if ($customerId === '') {
            throw new InvalidArgumentException('Customer ID must be a non-empty string.');
        }

        return $this->request('customers/get', ['customer_id' => $customerId]);
    }

    /**
     * Retrieve historical data for a customer.
     *
     * @param string $customerId Customer identifier.
     * @param int $limit Maximum records to retrieve (1-1000).
     * @return array<string,mixed>
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getCustomerHistory(string $customerId, int $limit = 50): array {
        $customerId = trim($customerId);
        if ($customerId === '') {
            throw new InvalidArgumentException('Customer ID must be a non-empty string.');
        }

        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Limit must be between 1 and 1000.');
        }

        return $this->request('customers/history', ['customer_id' => $customerId, 'limit' => $limit]);
    }

    /**
     * Retrieve the status of a quote.
     *
     * @param string $quoteId Quote identifier.
     * @return array<string,mixed>
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getQuoteStatus(string $quoteId): array {
        $quoteId = trim($quoteId);
        if ($quoteId === '') {
            throw new InvalidArgumentException('Quote ID must be a non-empty string.');
        }

        return $this->request('quotes/status', ['quote_id' => $quoteId]);
    }

    /**
     * Retrieve paginated ERP product catalog entries.
     *
     * @param int $page Page number starting at 1.
     * @param int $perPage Number of items per page.
     * @return array<string,mixed>
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getProducts(int $page = 1, int $perPage = 100): array {
        if ($page < 1) {
            throw new InvalidArgumentException('Page number must be greater than zero.');
        }

        if ($perPage < 1 || $perPage > 250) {
            throw new InvalidArgumentException('Per-page value must be between 1 and 250.');
        }

        return $this->request('products', ['page' => $page, 'per_page' => $perPage]);
    }

    /**
     * Retrieve a combo definition (bundled product) from ERP.
     *
     * @param string $comboId Combo identifier.
     * @return array<string,mixed>
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getCombo(string $comboId): array {
        $comboId = trim($comboId);
        if ($comboId === '') {
            throw new InvalidArgumentException('Combo ID must be a non-empty string.');
        }

        return $this->request('combos/get', ['combo_id' => $comboId]);
    }

    /**
     * Retrieve a kit definition from ERP.
     *
     * @param string $kitId Kit identifier.
     * @return array<string,mixed>
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getKit(string $kitId): array {
        $kitId = trim($kitId);
        if ($kitId === '') {
            throw new InvalidArgumentException('Kit ID must be a non-empty string.');
        }

        return $this->request('kits/get', ['kit_id' => $kitId]);
    }

    /**
     * Perform a GET request to the ERP API and return decoded JSON as an array.
     *
     * @param string $endpoint Relative endpoint path (no leading slash).
     * @param array<string,mixed> $query Query parameters to append.
     * @return array<string,mixed>
     * @throws RuntimeException On configuration, HTTP, or decode errors.
     */
    private function request(string $endpoint, array $query = []): array {
        if (trim($endpoint) === '') {
            throw new InvalidArgumentException('Endpoint must be a non-empty string.');
        }

        $opts = get_option( $this->resolveOptionName(), [] );
        if ( ! is_array( $opts ) ) {
            throw new RuntimeException('Plugin settings are not available.');
        }

        $base = isset( $opts['base_url'] ) ? trim( (string) $opts['base_url'] ) : '';
        $company = isset( $opts['company_code'] ) ? trim( (string) $opts['company_code'] ) : '';
        $timeout = isset( $opts['api_timeout'] ) ? (int) $opts['api_timeout'] : self::DEFAULT_TIMEOUT;
        $api_key = isset( $opts['api_key'] ) ? trim( (string) $opts['api_key'] ) : '';

        if ( $base === '' ) {
            throw new RuntimeException('ERP Base URL is not configured.');
        }

        if ( $company === '' ) {
            throw new RuntimeException('ERP Company Code is not configured.');
        }

        $base = rtrim( $base, "\/" );

        // Build URL
        $url = $base . '/' . ltrim( $endpoint, '/' );

        // Merge company code into query parameters to ensure context.
        $query_params = array_merge( ['company' => $company], $query );
        $url .= '?' . http_build_query( $query_params );

        $args = [
            'timeout' => $timeout,
            'headers' => [ 'Accept' => 'application/json' ],
        ];

        if ( $api_key !== '' ) {
            $args['headers']['X-VEC-API-KEY'] = $api_key;
        }

        // TTL for cache (seconds). Use settings if provided.
        $cache_ttl = isset( $opts['cache_ttl'] ) ? (int) $opts['cache_ttl'] : 300;

        $data = vec_cache_remember( $endpoint, $query_params, function() use ( $url, $args, $endpoint, $query_params ) {
            $start = microtime( true );
            $response = wp_remote_get( $url, $args );
            $duration = microtime( true ) - $start;

            if ( is_wp_error( $response ) ) {
                vec_log_error( $endpoint, $response, [ 'url' => $url, 'duration' => $duration, 'query' => $query_params ] );
                do_action( 'vec_as_failed_request', $endpoint, $query_params, $response->get_error_message() );
                throw new RuntimeException( 'HTTP request failed: ' . $response->get_error_message() );
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );

            if ( $code < 200 || $code >= 300 ) {
                $reason = sprintf( 'Unexpected HTTP status code %d', $code );
                vec_log_error( $endpoint, $reason, [ 'url' => $url, 'duration' => $duration, 'response_body' => substr( $body, 0, 2000 ) ] );
                do_action( 'vec_as_failed_request', $endpoint, $query_params, $reason );
                throw new RuntimeException( sprintf( 'Unexpected HTTP status code %d from ERP (%s).', $code, $url ) );
            }

            $data = json_decode( $body, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $reason = 'Failed to decode JSON response: ' . json_last_error_msg();
                vec_log_error( $endpoint, $reason, [ 'url' => $url, 'duration' => $duration, 'raw_body' => substr( $body, 0, 2000 ) ] );
                do_action( 'vec_as_failed_request', $endpoint, $query_params, $reason );
                throw new RuntimeException( $reason );
            }

            if ( ! is_array( $data ) ) {
                vec_log_error( $endpoint, 'ERP response JSON was not an object/array.', [ 'url' => $url, 'duration' => $duration ] );
                throw new RuntimeException( 'ERP response JSON was not an object/array.' );
            }

            // Log successful API call
            vec_log_api( $endpoint, [ 'duration' => $duration, 'response_code' => $code, 'payload' => $query_params, 'response' => $data ] );

            return $data;
        }, $cache_ttl );

        return $data;
    }

    /**
     * Retry a previously failed ERP request.
     *
     * @param string $endpoint
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function retryRequest( string $endpoint, array $query ): array {
        return $this->request( $endpoint, $query );
    }

    /**
     * Resolve the option name for plugin settings.
     *
     * Uses the Admin Settings class constant if available, otherwise falls back
     * to the literal option name string.
     *
     * @return string
     */
    private function resolveOptionName(): string {
        // Attempt to use the Settings class constant if it exists.
        if ( defined( '\\VictoriaERPConnector\\Admin\\Settings::OPTION_NAME' ) ) {
            return constant( '\\VictoriaERPConnector\\Admin\\Settings::OPTION_NAME' );
        }

        // Fallback literal used earlier in the plugin.
        return 'vec_settings';
    }
}
