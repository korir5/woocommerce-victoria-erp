<?php
declare(strict_types=1);

namespace VictoriaERPConnector\WooCommerce;

use VictoriaERPConnector\API\ERPClient;
use Throwable;

/**
 * Class Stock
 *
 * Synchronizes stock from Victoria ERP into WooCommerce products.
 * Supports simple and variable products, per-SKU updates, background sync
 * and front-end display of current stock levels.
 */
final class Stock {
    /**
     * Register WordPress and WooCommerce hooks.
     */
    public static function register_hooks(): void {
        // Background sync hook (cron or manual trigger).
        add_action( 'vec_background_stock_sync', [ self::class, 'background_sync_handler' ] );
        add_action( 'vec_sync_stock', [ self::class, 'background_sync_handler' ] );

        // Expose a lightweight trigger for on-demand syncs.
        add_action( 'vec_stock_synced', [ self::class, 'background_sync_handler' ] );

        // Webhook handler can be wired by the API endpoints to call this method.
        // Display stock on the product page by filtering stock HTML.
        add_filter( 'woocommerce_get_stock_html', [ self::class, 'filter_stock_html' ], 10, 2 );
    }

    /**
     * Background sync handler executed by cron or manual trigger.
     *
     * @return void
     */
    public static function background_sync_handler(): void {
        // Pull all products' SKUs and update them in background.
        if ( ! function_exists( 'wc_get_products' ) ) {
            return;
        }

        $args = [ 'limit' => -1, 'return' => 'ids' ];
        $product_ids = wc_get_products( $args );
        if ( empty( $product_ids ) ) {
            return;
        }

        self::sync_stock_full( $product_ids );
    }

    /**
     * Perform a full synchronization for the provided product IDs.
     * If $product_ids is null, all products will be scanned.
     *
     * @param int[]|null $product_ids
     * @return int Number of products updated
     */
    public static function sync_stock_full( ?array $product_ids = null ): int {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return 0;
        }

        if ( $product_ids === null ) {
            $product_ids = wc_get_products( [ 'limit' => -1, 'return' => 'ids' ] );
        }

        $updated_count = 0;
        $client = new ERPClient();

        foreach ( $product_ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) {
                continue;
            }

            // For variable products, update each variation by SKU.
            if ( $product->is_type( 'variable' ) ) {
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
                        $data = $client->getStock( $sku );
                        if ( isset( $data['stock'] ) ) {
                            $qty = (int) $data['stock'];
                            if ( self::update_product_stock( $variation, $qty ) ) {
                                $updated_count++;
                            }
                        }
                    } catch ( Throwable $e ) {
                        // Swallow per-item errors and continue; ERPClient already logs.
                        continue;
                    }
                }

                // After updating variations, optionally update parent stock status.
                self::refresh_parent_stock_status( $product );
                continue;
            }

            // Simple product flow
            $sku = (string) $product->get_sku();
            if ( $sku === '' ) {
                continue;
            }

            try {
                $data = $client->getStock( $sku );
                if ( isset( $data['stock'] ) ) {
                    $qty = (int) $data['stock'];
                    if ( self::update_product_stock( $product, $qty ) ) {
                        $updated_count++;
                    }
                }
            } catch ( Throwable $e ) {
                continue;
            }
        }

        if ( function_exists( 'update_option' ) ) {
            update_option( 'vec_last_stock_sync', current_time( 'mysql' ) );
        }

        return $updated_count;
    }

    /**
     * Update a product object's stock quantity and status. Saves the product.
     *
     * @param \WC_Product $product
     * @param int $qty
     * @return bool True if changed, false otherwise.
     */
    private static function update_product_stock( $product, int $qty ): bool {
        if ( ! method_exists( $product, 'set_stock_quantity' ) ) {
            return false;
        }

        $changed = false;

        // Ensure manage stock enabled
        if ( method_exists( $product, 'set_manage_stock' ) ) {
            $product->set_manage_stock( true );
        }

        $current = (int) $product->get_stock_quantity();
        if ( $current !== $qty ) {
            $product->set_stock_quantity( $qty );
            $changed = true;
        }

        // Update stock status
        $status = $qty > 0 ? 'instock' : 'outofstock';
        if ( method_exists( $product, 'set_stock_status' ) ) {
            $product->set_stock_status( $status );
        }

        if ( $changed && method_exists( $product, 'save' ) ) {
            $product->save();
            do_action( 'vec_stock_updated', $product->get_id(), $qty );
        }

        return $changed;
    }

    /**
     * After variations are updated, refresh the parent product stock status
     * based on aggregated availability of its children.
     */
    private static function refresh_parent_stock_status( $parent ): void {
        if ( ! $parent->is_type( 'variable' ) ) {
            return;
        }

        $children = $parent->get_children();
        $any_in_stock = false;
        foreach ( $children as $child_id ) {
            $variation = wc_get_product( $child_id );
            if ( ! $variation ) {
                continue;
            }

            if ( $variation->is_in_stock() ) {
                $any_in_stock = true;
                break;
            }
        }

        $status = $any_in_stock ? 'instock' : 'outofstock';
        if ( method_exists( $parent, 'set_stock_status' ) ) {
            $parent->set_stock_status( $status );
            if ( method_exists( $parent, 'save' ) ) {
                $parent->save();
            }
        }
    }

    /**
     * Filter the HTML shown for stock on the product page.
     *
     * @param string $html
     * @param \WC_Product $product
     * @return string
     */
    public static function filter_stock_html( string $html, $product ): string {
        if ( ! $product ) {
            return $html;
        }

        // Prefer to show exact stock for simple products and variations when available.
        if ( $product->managing_stock() ) {
            $qty = $product->get_stock_quantity();
            $label = $qty === null ? $html : sprintf( '<p class="vec-stock">%s: %d</p>', esc_html__( 'Available', 'victoria-erp-connector' ), $qty );
            return $label;
        }

        return $html;
    }
}
