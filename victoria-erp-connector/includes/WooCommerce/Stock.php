<?php
declare(strict_types=1);

namespace VictoriaERPConnector\WooCommerce;

/**
 * Class Stock
 *
 * Responsible for synchronizing stock levels between WooCommerce and
 * Victoria ERP. Methods are safe-guards: they check for WooCommerce
 * availability before attempting product updates.
 *
 * @package VictoriaERPConnector\WooCommerce
 */
final class Stock {
    /**
     * Trigger a stock synchronization.
     *
     * This method performs a lightweight sync trigger: it records the sync
     * timestamp and fires the `vec_stock_synced` action so background workers
     * or other listeners may perform the heavy lifting.
     *
     * @return bool True when the sync was initiated, false otherwise.
     */
    public static function sync_stock(): bool {
        if ( ! function_exists( 'update_option' ) ) {
            return false;
        }

        update_option( 'vec_last_stock_sync', current_time( 'mysql' ) );
        do_action( 'vec_stock_synced' );

        return true;
    }

    /**
     * Handle incoming webhook payloads related to stock updates.
     *
     * Expects an array of items with at least `sku` and `stock` keys.
     * For each item, attempts to locate the WooCommerce product by SKU
     * and update its stock quantity.
     *
     * @param mixed $payload Incoming webhook payload, expected to be an array.
     * @return bool True when at least one product was updated, false otherwise.
     */
    public static function handle_webhook( mixed $payload ): bool {
        if ( ! is_array( $payload ) ) {
            return false;
        }

        if ( ! function_exists( 'wc_get_product_id_by_sku' ) || ! function_exists( 'wc_get_product' ) ) {
            // WooCommerce not available — nothing to do here.
            return false;
        }

        $updated = false;

        foreach ( $payload as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $sku = isset( $item['sku'] ) ? (string) $item['sku'] : '';
            $stock = isset( $item['stock'] ) ? (int) $item['stock'] : null;

            if ( $sku === '' || $stock === null ) {
                continue;
            }

            $product_id = wc_get_product_id_by_sku( $sku );
            if ( ! $product_id ) {
                continue;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            if ( method_exists( $product, 'set_stock_quantity' ) ) {
                $product->set_stock_quantity( $stock );
                if ( method_exists( $product, 'save' ) ) {
                    $product->save();
                }
                do_action( 'vec_stock_updated', $product_id, $stock );
                $updated = true;
            }
        }

        if ( $updated && function_exists( 'update_option' ) ) {
            update_option( 'vec_last_stock_sync', current_time( 'mysql' ) );
        }

        return $updated;
    }
}
