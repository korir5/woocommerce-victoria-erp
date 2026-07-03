<?php
declare(strict_types=1);

namespace VictoriaERPConnector\WooCommerce;

use VictoriaERPConnector\API\ERPClient;
use Throwable;

/**
 * Class Pricing
 *
 * Synchronizes WooCommerce product prices with Victoria ERP. Supports simple
 * and variable products, scheduled background sync, webhook updates, and
 * live front-end price displays.
 */
final class Pricing {
    /**
     * Register WordPress and WooCommerce integration hooks.
     */
    public static function register_hooks(): void {
        add_action( 'vec_sync_pricing', [ self::class, 'sync_pricing' ] );
        add_action( 'vec_as_refresh_prices', [ self::class, 'sync_pricing' ] );
        add_action( 'vec_pricing_synced', [ self::class, 'background_sync_handler' ] );
        add_filter( 'woocommerce_get_price_html', [ self::class, 'filter_price_html' ], 10, 2 );
    }

    /**
     * Trigger a full pricing sync in the background.
     *
     * @return void
     */
    public static function background_sync_handler(): void {
        self::sync_pricing();
    }

    /**
     * Perform a complete pricing synchronization for WooCommerce products.
     *
     * @return int Number of products or variations updated.
     */
    public static function sync_pricing(): int {
        if ( ! function_exists( 'wc_get_products' ) || ! function_exists( 'wc_get_product' ) ) {
            return 0;
        }

        $product_ids = wc_get_products( [ 'limit' => -1, 'return' => 'ids' ] );
        if ( empty( $product_ids ) ) {
            return 0;
        }

        $client = new ERPClient();
        $updated = 0;

        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            if ( $product->is_type( 'variable' ) ) {
                $updated += self::sync_variable_product_pricing( $product, $client );
                continue;
            }

            $updated += self::sync_simple_product_pricing( $product, $client );
        }

        if ( function_exists( 'update_option' ) ) {
            update_option( 'vec_last_pricing_sync', current_time( 'mysql' ) );
        }

        return $updated;
    }

    /**
     * Handle pricing webhook events from Victoria ERP.
     *
     * @param mixed $payload
     * @return bool True when the webhook was processed.
     */
    public static function handle_webhook( mixed $payload ): bool {
        if ( ! is_array( $payload ) || empty( $payload['sku'] ) ) {
            return false;
        }

        $sku = (string) $payload['sku'];
        if ( $sku === '' ) {
            return false;
        }

        return self::sync_pricing_for_sku( $sku );
    }

    /**
     * Synchronize pricing for a single SKU.
     *
     * @param string $sku
     * @return bool True if one or more products were updated.
     */
    public static function sync_pricing_for_sku( string $sku ): bool {
        if ( ! function_exists( 'wc_get_product_id_by_sku' ) || ! function_exists( 'wc_get_product' ) ) {
            return false;
        }

        $product_id = wc_get_product_id_by_sku( $sku );
        if ( ! $product_id ) {
            return false;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }

        $client = new ERPClient();
        try {
            $selling = $client->getSellingPrice( $sku );
            $offer   = $client->getOfferPrice( $sku );
        } catch ( Throwable $e ) {
            return false;
        }

        $regular_price = self::extract_price( $selling );
        $sale_price    = self::extract_price( $offer );

        if ( $product->is_type( 'variable' ) ) {
            return self::sync_variable_product_pricing( $product, $client ) > 0;
        }

        return self::update_product_price( $product, $regular_price, $sale_price );
    }

    /**
     * Synchronize pricing for a simple product.
     *
     * @param \WC_Product $product
     * @param ERPClient $client
     * @return int
     */
    private static function sync_simple_product_pricing( $product, ERPClient $client ): int {
        $sku = (string) $product->get_sku();
        if ( $sku === '' ) {
            return 0;
        }

        try {
            $selling = $client->getSellingPrice( $sku );
            $offer   = $client->getOfferPrice( $sku );
        } catch ( Throwable $e ) {
            return 0;
        }

        $regular_price = self::extract_price( $selling );
        $sale_price    = self::extract_price( $offer );

        return self::update_product_price( $product, $regular_price, $sale_price ) ? 1 : 0;
    }

    /**
     * Synchronize pricing for a variable product.
     *
     * @param \WC_Product $product
     * @param ERPClient $client
     * @return int
     */
    private static function sync_variable_product_pricing( $product, ERPClient $client ): int {
        $updated = 0;
        $children = $product->get_children();

        foreach ( $children as $child_id ) {
            $variation = wc_get_product( $child_id );
            if ( ! $variation ) {
                continue;
            }

            $sku = (string) $variation->get_sku();
            if ( $sku === '' ) {
                continue;
            }

            try {
                $selling = $client->getSellingPrice( $sku );
                $offer   = $client->getOfferPrice( $sku );
            } catch ( Throwable $e ) {
                continue;
            }

            $regular_price = self::extract_price( $selling );
            $sale_price    = self::extract_price( $offer );

            if ( self::update_product_price( $variation, $regular_price, $sale_price ) ) {
                $updated++;
            }
        }

        if ( $updated > 0 && method_exists( $product, 'save' ) ) {
            $product->save();
        }

        return $updated;
    }

    /**
     * Update a WC product's regular and sale prices.
     *
     * @param \WC_Product $product
     * @param float|null $regular_price
     * @param float|null $sale_price
     * @return bool
     */
    private static function update_product_price( $product, ?float $regular_price, ?float $sale_price ): bool {
        if ( $regular_price === null ) {
            return false;
        }

        $changed = false;

        if ( method_exists( $product, 'set_regular_price' ) ) {
            $current_regular = $product->get_regular_price();
            if ( (string) $current_regular !== (string) $regular_price ) {
                $product->set_regular_price( (string) $regular_price );
                $changed = true;
            }
        }

        if ( method_exists( $product, 'set_sale_price' ) ) {
            if ( $sale_price === null ) {
                if ( $product->get_sale_price() !== '' ) {
                    $product->set_sale_price( '' );
                    $changed = true;
                }
            } else {
                $current_sale = $product->get_sale_price();
                if ( (string) $current_sale !== (string) $sale_price ) {
                    $product->set_sale_price( (string) $sale_price );
                    $changed = true;
                }
            }
        }

        if ( $changed && method_exists( $product, 'save' ) ) {
            $product->save();
        }

        return $changed;
    }

    /**
     * Filter the price HTML on the product page to display live ERP prices.
     *
     * @param string $html
     * @param \WC_Product $product
     * @return string
     */
    public static function filter_price_html( string $html, $product ): string {
        if ( ! $product || ! function_exists( 'wc_get_product' ) ) {
            return $html;
        }

        if ( $product->is_type( 'simple' ) || $product->is_type( 'variation' ) ) {
            $sku = (string) $product->get_sku();
            if ( $sku === '' ) {
                return $html;
            }

            try {
                $client = new ERPClient();
                $offer = $client->getOfferPrice( $sku );
                $selling = $client->getSellingPrice( $sku );

                $regular_price = self::extract_price( $selling );
                $sale_price    = self::extract_price( $offer );

                if ( $regular_price === null ) {
                    return $html;
                }

                if ( $sale_price !== null && $sale_price < $regular_price ) {
                    $formatted = sprintf(
                        '<del>%s</del> <ins>%s</ins>',
                        wc_price( $regular_price ),
                        wc_price( $sale_price )
                    );
                } else {
                    $formatted = wc_price( $regular_price );
                }

                return sprintf( '<span class="vec-live-price">%s</span>', $formatted );
            } catch ( Throwable $e ) {
                return $html;
            }
        }

        return $html;
    }

    /**
     * Extract a numerical price from an ERP response payload.
     *
     * @param array<string,mixed> $data
     * @return float|null
     */
    private static function extract_price( array $data ): ?float {
        if ( isset( $data['offer_price'] ) && is_numeric( $data['offer_price'] ) ) {
            return (float) $data['offer_price'];
        }

        if ( isset( $data['sale_price'] ) && is_numeric( $data['sale_price'] ) ) {
            return (float) $data['sale_price'];
        }

        if ( isset( $data['price'] ) && is_numeric( $data['price'] ) ) {
            return (float) $data['price'];
        }

        if ( isset( $data['selling_price'] ) && is_numeric( $data['selling_price'] ) ) {
            return (float) $data['selling_price'];
        }

        return null;
    }
}
