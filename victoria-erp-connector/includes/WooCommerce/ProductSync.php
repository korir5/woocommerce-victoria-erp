<?php
declare(strict_types=1);

namespace VictoriaERPConnector\WooCommerce;

use VictoriaERPConnector\API\ERPClient;
use VictoriaERPConnector\Admin\Settings;
use VictoriaERPConnector\Cron\SyncScheduler;
use VictoriaERPConnector\Logger\Logger;
use Throwable;
use WC_Product;
use WC_Product_Attribute;

/**
 * Class ProductSync
 *
 * Synchronizes WooCommerce products with Victoria ERP product catalog data.
 * Supports prices, stock, attributes and image metadata for future importing.
 *
 * @package VictoriaERPConnector\WooCommerce
 */
final class ProductSync {
    public const SYNC_OPTION = 'vec_last_product_sync';
    public const SYNC_COUNT_OPTION = 'vec_last_product_sync_count';
    public const IMAGE_META_KEY = '_vec_erp_image_urls';

    public static function register_hooks(): void {
        add_action( SyncScheduler::PRODUCT_JOB, [ self::class, 'refresh_products' ] );
        add_action( 'vec_as_manual_product_sync', [ self::class, 'manual_sync' ] );
    }

    public static function refresh_products(): int {
        return self::sync_products();
    }

    public static function manual_sync(): int {
        return self::sync_products();
    }

    public static function sync_products(): int {
        if ( ! self::is_enabled() ) {
            return 0;
        }

        if ( ! function_exists( 'wc_get_product_id_by_sku' ) || ! function_exists( 'wc_get_product' ) ) {
            return 0;
        }

        $client = new ERPClient();
        $page = 1;
        $updated = 0;
        $processed = 0;
        $per_page = 100;

        while ( true ) {
            try {
                $items = $client->getProducts( $page, $per_page );
            } catch ( Throwable $exception ) {
                Logger::log_error( 'products/sync', $exception, [ 'page' => $page, 'per_page' => $per_page ] );
                break;
            }

            if ( ! is_array( $items ) || empty( $items ) ) {
                break;
            }

            foreach ( $items as $item ) {
                if ( ! is_array( $item ) || empty( $item['sku'] ) ) {
                    continue;
                }

                $processed++;
                if ( self::sync_product_item( $item ) ) {
                    $updated++;
                }
            }

            if ( count( $items ) < $per_page ) {
                break;
            }

            $page++;
        }

        update_option( self::SYNC_OPTION, current_time( 'mysql' ) );
        update_option( self::SYNC_COUNT_OPTION, $updated );

        Logger::log_debug( 'Product sync completed.', [ 'updated' => $updated, 'processed' => $processed ] );

        return $updated;
    }

    private static function sync_product_item( array $item ): bool {
        $sku = trim( (string) ( $item['sku'] ?? '' ) );
        if ( $sku === '' ) {
            return false;
        }

        $product_id = wc_get_product_id_by_sku( $sku );
        if ( ! $product_id ) {
            $product_id = self::create_product_from_erp( $item );
        }

        if ( ! $product_id ) {
            return false;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }

        return self::update_product_from_erp( $product, $item );
    }

    private static function create_product_from_erp( array $item ): ?int {
        if ( ! function_exists( 'wp_insert_post' ) ) {
            return null;
        }

        $title = trim( (string) ( $item['name'] ?? $item['sku'] ?? '' ) );
        if ( $title === '' ) {
            return null;
        }

        $post_id = wp_insert_post(
            [
                'post_type'   => 'product',
                'post_title'  => $title,
                'post_status' => 'publish',
                'post_content'=> trim( (string) ( $item['description'] ?? '' ) ),
            ], true
        );

        if ( is_wp_error( $post_id ) || $post_id <= 0 ) {
            return null;
        }

        return (int) $post_id;
    }

    private static function update_product_from_erp( WC_Product $product, array $item ): bool {
        $changed = false;

        $sku = trim( (string) ( $item['sku'] ?? '' ) );
        if ( $sku !== '' && method_exists( $product, 'set_sku' ) && (string) $product->get_sku() !== $sku ) {
            $product->set_sku( $sku );
            $changed = true;
        }

        $title = trim( (string) ( $item['name'] ?? '' ) );
        if ( $title !== '' && method_exists( $product, 'set_name' ) && (string) $product->get_name() !== $title ) {
            $product->set_name( $title );
            $changed = true;
        }

        if ( method_exists( $product, 'set_description' ) ) {
            $description = trim( (string) ( $item['description'] ?? '' ) );
            if ( $description !== '' && (string) $product->get_description() !== $description ) {
                $product->set_description( $description );
                $changed = true;
            }
        }

        if ( method_exists( $product, 'set_short_description' ) ) {
            $short_description = trim( (string) ( $item['short_description'] ?? '' ) );
            if ( $short_description !== '' && (string) $product->get_short_description() !== $short_description ) {
                $product->set_short_description( $short_description );
                $changed = true;
            }
        }

        $regular_price = self::extract_price( $item, 'selling_price', 'price', 'regular_price' );
        $sale_price    = self::extract_price( $item, 'offer_price', 'sale_price' );

        if ( method_exists( $product, 'set_regular_price' ) && $regular_price !== null ) {
            if ( (string) $product->get_regular_price() !== (string) $regular_price ) {
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
            } elseif ( (string) $product->get_sale_price() !== (string) $sale_price ) {
                $product->set_sale_price( (string) $sale_price );
                $changed = true;
            }
        }

        if ( method_exists( $product, 'set_manage_stock' ) && method_exists( $product, 'set_stock_quantity' ) ) {
            $stock_value = $item['stock'] ?? $item['quantity'] ?? null;
            if ( is_numeric( $stock_value ) ) {
                $qty = (int) $stock_value;
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $qty );
                $status = $qty > 0 ? 'instock' : 'outofstock';
                if ( method_exists( $product, 'set_stock_status' ) ) {
                    $product->set_stock_status( $status );
                }
                $changed = true;
            }
        }

        $attributes = self::build_product_attributes( $item['attributes'] ?? [] );
        if ( ! empty( $attributes ) && method_exists( $product, 'set_attributes' ) ) {
            $product->set_attributes( $attributes );
            $changed = true;
        }

        $image_urls = self::normalize_image_urls( $item['images'] ?? [] );
        if ( ! empty( $image_urls ) ) {
            update_post_meta( $product->get_id(), self::IMAGE_META_KEY, wp_json_encode( $image_urls ) );
            $changed = true;
        }

        if ( $changed && method_exists( $product, 'save' ) ) {
            $product->save();
        }

        return $changed;
    }

    private static function extract_price( array $item, string ...$keys ): ?float {
        foreach ( $keys as $key ) {
            if ( isset( $item[ $key ] ) && is_numeric( $item[ $key ] ) ) {
                return (float) $item[ $key ];
            }
        }

        return null;
    }

    private static function build_product_attributes( mixed $raw_attributes ): array {
        if ( ! class_exists( 'WC_Product_Attribute' ) || ! is_array( $raw_attributes ) ) {
            return [];
        }

        $attributes = [];
        foreach ( $raw_attributes as $index => $attribute ) {
            if ( ! is_array( $attribute ) ) {
                continue;
            }

            $name = '';
            $options = [];

            if ( isset( $attribute['name'] ) ) {
                $name = trim( (string) $attribute['name'] );
            }

            if ( isset( $attribute['value'] ) ) {
                if ( is_array( $attribute['value'] ) ) {
                    $options = array_map( 'strval', $attribute['value'] );
                } else {
                    $options = [ trim( (string) $attribute['value'] ) ];
                }
            } elseif ( isset( $attribute['values'] ) && is_array( $attribute['values'] ) ) {
                $options = array_map( 'strval', $attribute['values'] );
            }

            if ( $name === '' || empty( $options ) ) {
                continue;
            }

            $attr = new WC_Product_Attribute();
            $attr->set_id( 0 );
            $attr->set_name( $name );
            $attr->set_options( array_values( array_filter( array_map( 'trim', $options ), 'strlen' ) ) );
            $attr->set_position( (int) $index );
            $attr->set_visible( true );
            $attr->set_variation( false );

            $attributes[ sanitize_title( $name ) ] = $attr;
        }

        return $attributes;
    }

    private static function normalize_image_urls( mixed $images ): array {
        if ( ! is_array( $images ) ) {
            return [];
        }

        $urls = [];
        foreach ( $images as $image ) {
            if ( is_string( $image ) && filter_var( $image, FILTER_VALIDATE_URL ) !== false ) {
                $urls[] = $image;
            } elseif ( is_array( $image ) && ! empty( $image['url'] ) && filter_var( (string) $image['url'], FILTER_VALIDATE_URL ) !== false ) {
                $urls[] = (string) $image['url'];
            }
        }

        return array_values( array_unique( $urls ) );
    }

    private static function is_enabled(): bool {
        $settings = Settings::get_options();
        return ! empty( $settings['enable_product_sync'] );
    }

    public static function get_last_sync_time(): string {
        return (string) get_option( self::SYNC_OPTION, '' );
    }

    public static function get_last_sync_count(): int {
        return (int) get_option( self::SYNC_COUNT_OPTION, 0 );
    }
}
