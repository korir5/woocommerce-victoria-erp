<?php
declare(strict_types=1);

namespace VictoriaERPConnector\WooCommerce;

use VictoriaERPConnector\Admin\Settings;
use WC_Cart;
use WC_Order;
use WC_Product;
use Throwable;

/**
 * Class PromotionEngine
 *
 * Handles promotion rules for WooCommerce with support for combo offers,
 * bundle pricing, automatic free items, and checkout/cart discount hooks.
 *
 * Promotion rules are stored as JSON in the plugin settings and evaluated
 * on cart recalculations and order creation.
 *
 * @package VictoriaERPConnector\WooCommerce
 */
final class PromotionEngine {
    /**
     * Register WordPress and WooCommerce hooks for promotion handling.
     */
    public static function register_hooks(): void {
        add_action( 'woocommerce_before_calculate_totals', [ self::class, 'apply_promotions' ], 20 );
        add_action( 'woocommerce_cart_calculate_fees', [ self::class, 'apply_cart_fees' ], 20, 1 );
        add_action( 'woocommerce_checkout_create_order', [ self::class, 'checkout_create_order' ], 10, 2 );
    }

    /**
     * Apply cart modifications required for the promotion engine.
     *
     * @param WC_Cart $cart
     * @return void
     */
    public static function apply_promotions( WC_Cart $cart ): void {
        if ( ! self::is_enabled() || self::is_cart_empty( $cart ) ) {
            return;
        }

        $rules = self::get_promotion_rules();
        if ( empty( $rules ) ) {
            return;
        }

        self::remove_existing_free_items( $cart );
        self::apply_bundle_pricing( $cart, $rules );
        self::add_free_items( $cart, $rules );
    }

    /**
     * Add fees and discounts to the cart based on active promotion rules.
     *
     * @param WC_Cart $cart
     * @return void
     */
    public static function apply_cart_fees( WC_Cart $cart ): void {
        if ( ! self::is_enabled() || self::is_cart_empty( $cart ) ) {
            return;
        }

        $rules = self::get_promotion_rules();
        if ( empty( $rules ) ) {
            return;
        }

        $cart_skus = self::get_cart_skus( $cart );
        $cart_subtotal = self::get_cart_subtotal( $cart );
        $applied = [];

        foreach ( $rules as $rule ) {
            if ( ! self::is_rule_applicable( $rule, $cart, $cart_skus, $cart_subtotal ) ) {
                continue;
            }

            if ( isset( $rule['discount'] ) && is_array( $rule['discount'] ) ) {
                $amount = self::calculate_discount_amount( $cart, $rule );
                if ( $amount > 0.0 ) {
                    self::add_cart_fee( $cart, $rule['id'] ?? 'promotion', $rule['label'] ?? __( 'Promotion Discount', 'victoria-erp-connector' ), -$amount );
                    $applied[] = $rule['id'] ?? 'promotion';
                }
            }

            if ( isset( $rule['bundle_price'] ) && self::has_required_skus( $rule, $cart_skus ) ) {
                $amount = self::calculate_bundle_savings( $cart, $rule );
                if ( $amount > 0.0 ) {
                    self::add_cart_fee( $cart, $rule['id'] ?? 'bundle', $rule['label'] ?? __( 'Bundle Savings', 'victoria-erp-connector' ), -$amount );
                    $applied[] = $rule['id'] ?? 'bundle';
                }
            }
        }

        if ( function_exists( 'WC' ) && self::is_checkout() && ! empty( $applied ) ) {
            WC()->session->set( 'vec_applied_promotions', $applied );
        }
    }

    /**
     * Save promotion metadata onto the order when checkout is created.
     *
     * @param WC_Order $order
     * @param array $data
     * @return void
     */
    public static function checkout_create_order( WC_Order $order, array $data ): void {
        if ( ! self::is_enabled() ) {
            return;
        }

        $rules = self::get_promotion_rules();
        if ( empty( $rules ) ) {
            return;
        }

        $cart = WC()->cart;
        if ( ! $cart ) {
            return;
        }

        $applied = [];
        $cart_skus = self::get_cart_skus( $cart );
        $cart_subtotal = self::get_cart_subtotal( $cart );

        foreach ( $rules as $rule ) {
            if ( self::is_rule_applicable( $rule, $cart, $cart_skus, $cart_subtotal ) ) {
                $applied[] = [
                    'id'    => $rule['id'] ?? '',
                    'type'  => $rule['type'] ?? '',
                    'label' => $rule['label'] ?? '',
                ];
            }
        }

        if ( ! empty( $applied ) ) {
            $order->update_meta_data( '_vec_applied_promotions', wp_json_encode( $applied ) );
        }
    }

    /**
     * Determine whether the promotion engine is enabled in settings.
     *
     * @return bool
     */
    private static function is_enabled(): bool {
        $settings = Settings::get_options();
        return ! empty( $settings['enable_promotion_engine'] );
    }

    /**
     * Retrieve promotion rules from plugin settings.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function get_promotion_rules(): array {
        $settings = Settings::get_options();
        $raw = isset( $settings['promotion_rules'] ) ? $settings['promotion_rules'] : '';

        if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
            return [];
        }

        $rules = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $rules ) ) {
            return [];
        }

        return $rules;
    }

    /**
     * Remove free promotion items from cart before re-applying rules.
     *
     * @param WC_Cart $cart
     * @return void
     */
    private static function remove_existing_free_items( WC_Cart $cart ): void {
        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( isset( $cart_item['vec_promotion_free_item'] ) && $cart_item['vec_promotion_free_item'] === true ) {
                $cart->remove_cart_item( $cart_item_key );
            }
        }
    }

    /**
     * Add free items to the cart when free-item promotion rules match.
     *
     * @param WC_Cart $cart
     * @param array<int,array<string,mixed>> $rules
     * @return void
     */
    private static function add_free_items( WC_Cart $cart, array $rules ): void {
        $cart_skus = self::get_cart_skus( $cart );

        foreach ( $rules as $rule ) {
            if ( ! self::is_rule_applicable( $rule, $cart, $cart_skus, self::get_cart_subtotal( $cart ) ) ) {
                continue;
            }

            if ( empty( $rule['free_sku'] ) ) {
                continue;
            }

            $free_sku = sanitize_text_field( (string) $rule['free_sku'] );
            $free_product_id = wc_get_product_id_by_sku( $free_sku );
            if ( ! $free_product_id ) {
                continue;
            }

            $quantity = 1;
            if ( isset( $rule['free_quantity'] ) && is_numeric( $rule['free_quantity'] ) ) {
                $quantity = max( 1, (int) $rule['free_quantity'] );
            }

            $cart->add_to_cart(
                $free_product_id,
                $quantity,
                0,
                [],
                [
                    'vec_promotion_free_item' => true,
                    'vec_promotion_rule_id'   => $rule['id'] ?? '',
                ]
            );
        }
    }

    /**
     * Apply bundle pricing adjustments by setting product prices or adding cart fees.
     *
     * @param WC_Cart $cart
     * @param array<int,array<string,mixed>> $rules
     * @return void
     */
    private static function apply_bundle_pricing( WC_Cart $cart, array $rules ): void {
        $cart_skus = self::get_cart_skus( $cart );

        foreach ( $rules as $rule ) {
            if ( ! isset( $rule['type'] ) || $rule['type'] !== 'bundle' ) {
                continue;
            }

            if ( empty( $rule['bundle_price'] ) || ! is_numeric( $rule['bundle_price'] ) ) {
                continue;
            }

            if ( ! self::has_required_skus( $rule, $cart_skus ) ) {
                continue;
            }

            if ( ! empty( $rule['bundle_product_sku'] ) ) {
                $bundle_id = wc_get_product_id_by_sku( sanitize_text_field( (string) $rule['bundle_product_sku'] ) );
                if ( $bundle_id ) {
                    foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                        $product = $cart_item['data'];
                        if ( ! $product instanceof WC_Product ) {
                            continue;
                        }

                        if ( (string) $product->get_sku() === $rule['bundle_product_sku'] && method_exists( $product, 'set_price' ) ) {
                            $product->set_price( (float) $rule['bundle_price'] );
                        }
                    }
                }
            }
        }
    }

    /**
     * Add negative fee for bundle savings when bundle rules match.
     *
     * @param WC_Cart $cart
     * @param array<string,mixed> $rule
     * @return float
     */
    private static function calculate_bundle_savings( WC_Cart $cart, array $rule ): float {
        $bundle_price = (float) $rule['bundle_price'];
        $required_skus = isset( $rule['required_skus'] ) && is_array( $rule['required_skus'] ) ? $rule['required_skus'] : [];
        if ( empty( $required_skus ) ) {
            return 0.0;
        }

        $cart_items = $cart->get_cart();
        $total = 0.0;
        $matched = [];

        foreach ( $cart_items as $cart_item ) {
            $product = $cart_item['data'];
            if ( ! $product instanceof WC_Product ) {
                continue;
            }

            $sku = (string) $product->get_sku();
            if ( in_array( $sku, $required_skus, true ) && ! isset( $matched[ $sku ] ) ) {
                $total += (float) $product->get_price() * $cart_item['quantity'];
                $matched[ $sku ] = true;
            }
        }

        if ( count( $matched ) !== count( array_unique( $required_skus ) ) ) {
            return 0.0;
        }

        return max( 0.0, $total - $bundle_price );
    }

    /**
     * Determine whether a rule should be applied to the current cart.
     *
     * @param array<string,mixed> $rule
     * @param WC_Cart $cart
     * @param string[] $cart_skus
     * @param float $subtotal
     * @return bool
     */
    private static function is_rule_applicable( array $rule, WC_Cart $cart, array $cart_skus, float $subtotal ): bool {
        if ( empty( $rule['type'] ) ) {
            return false;
        }

        if ( ! empty( $rule['required_skus'] ) && is_array( $rule['required_skus'] ) ) {
            if ( ! self::has_required_skus( $rule, $cart_skus ) ) {
                return false;
            }
        }

        if ( isset( $rule['minimum_subtotal'] ) && is_numeric( $rule['minimum_subtotal'] ) ) {
            if ( $subtotal < (float) $rule['minimum_subtotal'] ) {
                return false;
            }
        }

        if ( isset( $rule['minimum_quantity'] ) && is_numeric( $rule['minimum_quantity'] ) ) {
            $quantity = array_sum( wp_list_pluck( $cart->get_cart(), 'quantity' ) );
            if ( $quantity < (int) $rule['minimum_quantity'] ) {
                return false;
            }
        }

        if ( $rule['type'] === 'free_item' ) {
            return ! empty( $rule['free_sku'] );
        }

        if ( $rule['type'] === 'automatic_discount' ) {
            return isset( $rule['discount'] ) && is_array( $rule['discount'] );
        }

        if ( $rule['type'] === 'combo' || $rule['type'] === 'bundle' ) {
            return ! empty( $rule['required_skus'] );
        }

        return false;
    }

    /**
     * Calculate a discount amount for a generic discount rule.
     *
     * @param WC_Cart $cart
     * @param array<string,mixed> $rule
     * @return float
     */
    private static function calculate_discount_amount( WC_Cart $cart, array $rule ): float {
        if ( empty( $rule['discount'] ) || ! is_array( $rule['discount'] ) ) {
            return 0.0;
        }

        $discount = $rule['discount'];
        $type = $discount['type'] ?? 'fixed';
        $value = isset( $discount['value'] ) ? (float) $discount['value'] : 0.0;

        if ( $value <= 0.0 ) {
            return 0.0;
        }

        $target_total = self::get_cart_subtotal( $cart );
        if ( ! empty( $rule['required_skus'] ) && is_array( $rule['required_skus'] ) ) {
            $target_total = self::get_target_total_for_skus( $cart, $rule['required_skus'] );
        }

        if ( $type === 'percent' ) {
            return round( $target_total * $value / 100, wc_get_price_decimals() );
        }

        return min( $target_total, $value );
    }

    /**
     * Compute the total value of cart items matching a set of SKUs.
     *
     * @param WC_Cart $cart
     * @param string[] $skus
     * @return float
     */
    private static function get_target_total_for_skus( WC_Cart $cart, array $skus ): float {
        $total = 0.0;
        foreach ( $cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            if ( ! $product instanceof WC_Product ) {
                continue;
            }

            if ( in_array( (string) $product->get_sku(), $skus, true ) ) {
                $total += (float) $product->get_price() * $cart_item['quantity'];
            }
        }

        return $total;
    }

    /**
     * Add a promotion fee to the cart.
     *
     * @param WC_Cart $cart
     * @param string $id
     * @param string $label
     * @param float $amount
     * @return void
     */
    private static function add_cart_fee( WC_Cart $cart, string $id, string $label, float $amount ): void {
        if ( $amount === 0.0 ) {
            return;
        }

        $fee = new \WC_Cart_Fee( $label, $amount, true, '' );
        $fee->id = sanitize_title( $id );
        $cart->add_fee( $fee );
    }

    /**
     * Determine whether the cart contains all SKUs required by the rule.
     *
     * @param array<string,mixed> $rule
     * @param string[] $cart_skus
     * @return bool
     */
    private static function has_required_skus( array $rule, array $cart_skus ): bool {
        if ( empty( $rule['required_skus'] ) || ! is_array( $rule['required_skus'] ) ) {
            return false;
        }

        $required = array_map( 'strval', $rule['required_skus'] );
        sort( $required );
        $present = array_map( 'strval', $cart_skus );
        sort( $present );

        return count( array_intersect( $required, $present ) ) === count( array_unique( $required ) );
    }

    /**
     * Return the cart SKUs currently present.
     *
     * @param WC_Cart $cart
     * @return string[]
     */
    private static function get_cart_skus( WC_Cart $cart ): array {
        $skus = [];
        foreach ( $cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            if ( ! $product instanceof WC_Product ) {
                continue;
            }

            $sku = (string) $product->get_sku();
            if ( $sku === '' ) {
                continue;
            }

            $skus[] = $sku;
        }

        return array_unique( $skus );
    }

    /**
     * Get the current cart subtotal.
     *
     * @param WC_Cart $cart
     * @return float
     */
    private static function get_cart_subtotal( WC_Cart $cart ): float {
        return (float) $cart->get_subtotal();
    }

    /**
     * Check whether the current request is a checkout flow.
     *
     * @return bool
     */
    private static function is_checkout(): bool {
        return function_exists( 'is_checkout' ) && is_checkout();
    }

    /**
     * Check whether the cart is empty.
     *
     * @param WC_Cart $cart
     * @return bool
     */
    private static function is_cart_empty( WC_Cart $cart ): bool {
        return $cart->is_empty();
    }
}
