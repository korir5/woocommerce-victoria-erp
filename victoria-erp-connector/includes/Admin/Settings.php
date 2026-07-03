<?php
declare(strict_types=1);

namespace VictoriaERPConnector\Admin;

use WP_Error;

/**
 * Class Settings
 *
 * Registers plugin settings via the WordPress Settings API and provides
 * helper methods to access and test ERP connection settings.
 *
 * @package VictoriaERPConnector\Admin
 */
final class Settings {
    /**
     * Option name where plugin settings are stored.
     */
    public const OPTION_NAME = 'vec_settings';

    /**
     * Register settings, sections and fields.
     *
     * @return void
     */
    public static function register(): void {
        if ( ! function_exists( 'register_setting' ) ) {
            return;
        }

        register_setting(
            'vec_options_group',
            self::OPTION_NAME,
            [ self::class, 'sanitize' ]
        );

        add_settings_section(
            'vec_section_connection',
            __( 'ERP Connection', 'victoria-erp-connector' ),
            function() { echo '<p>' . esc_html__( 'Configure connection details for Victoria ERP.', 'victoria-erp-connector' ) . '</p>'; },
            'vec_settings_page'
        );

        add_settings_field(
            'vec_base_url',
            __( 'Base URL', 'victoria-erp-connector' ),
            [ self::class, 'field_base_url' ],
            'vec_settings_page',
            'vec_section_connection'
        );

        add_settings_field(
            'vec_company_code',
            __( 'Company Code', 'victoria-erp-connector' ),
            [ self::class, 'field_company_code' ],
            'vec_settings_page',
            'vec_section_connection'
        );

        add_settings_field(
            'vec_api_timeout',
            __( 'API Timeout (seconds)', 'victoria-erp-connector' ),
            [ self::class, 'field_api_timeout' ],
            'vec_settings_page',
            'vec_section_connection'
        );

        add_settings_field(
            'vec_api_key',
            __( 'API Key', 'victoria-erp-connector' ),
            [ self::class, 'field_api_key' ],
            'vec_settings_page',
            'vec_section_connection'
        );

        add_settings_section(
            'vec_section_debug',
            __( 'Debug', 'victoria-erp-connector' ),
            function() { echo '<p>' . esc_html__( 'Debugging and logging options.', 'victoria-erp-connector' ) . '</p>'; },
            'vec_settings_page'
        );

        add_settings_field(
            'vec_enable_logging',
            __( 'Enable Logging', 'victoria-erp-connector' ),
            [ self::class, 'field_enable_logging' ],
            'vec_settings_page',
            'vec_section_debug'
        );

        add_settings_section(
            'vec_section_sync',
            __( 'Synchronization', 'victoria-erp-connector' ),
            function() { echo '<p>' . esc_html__( 'Choose which data to synchronize automatically.', 'victoria-erp-connector' ) . '</p>'; },
            'vec_settings_page'
        );

        add_settings_field(
            'vec_enable_stock_sync',
            __( 'Enable Stock Sync', 'victoria-erp-connector' ),
            [ self::class, 'field_enable_stock_sync' ],
            'vec_settings_page',
            'vec_section_sync'
        );

        add_settings_field(
            'vec_enable_price_sync',
            __( 'Enable Price Sync', 'victoria-erp-connector' ),
            [ self::class, 'field_enable_price_sync' ],
            'vec_settings_page',
            'vec_section_sync'
        );

        add_settings_field(
            'vec_enable_product_sync',
            __( 'Enable Product Sync', 'victoria-erp-connector' ),
            [ self::class, 'field_enable_product_sync' ],
            'vec_settings_page',
            'vec_section_sync'
        );

        add_settings_section(
            'vec_section_promotions',
            __( 'Promotion Engine', 'victoria-erp-connector' ),
            function() { echo '<p>' . esc_html__( 'Configure automatic promotion rules for cart and checkout.', 'victoria-erp-connector' ) . '</p>'; },
            'vec_settings_page'
        );

        add_settings_field(
            'vec_enable_promotion_engine',
            __( 'Enable Promotion Engine', 'victoria-erp-connector' ),
            [ self::class, 'field_enable_promotion_engine' ],
            'vec_settings_page',
            'vec_section_promotions'
        );

        add_settings_field(
            'vec_promotion_rules',
            __( 'Promotion Rules (JSON)', 'victoria-erp-connector' ),
            [ self::class, 'field_promotion_rules' ],
            'vec_settings_page',
            'vec_section_promotions'
        );
    }

    /**
     * Sanitize settings input.
     *
     * @param mixed $input Raw input.
     * @return array Sanitized settings.
     */
    public static function sanitize( $input ): array {
        $defaults = [
            'base_url'             => '',
            'company_code'         => '',
            'api_timeout'          => 15,
            'enable_logging'       => false,
            'enable_stock_sync'    => false,
            'enable_price_sync'    => false,
            'enable_product_sync'  => false,
            'enable_promotion_engine' => false,
            'promotion_rules'      => '',
            'api_key'              => '',
        ];

        $sanitized = $defaults;

        if ( is_array( $input ) ) {
            $sanitized['base_url'] = isset( $input['base_url'] ) ? esc_url_raw( trim( (string) $input['base_url'] ) ) : $defaults['base_url'];
            $sanitized['company_code'] = isset( $input['company_code'] ) ? sanitize_text_field( (string) $input['company_code'] ) : $defaults['company_code'];
            $sanitized['api_timeout'] = isset( $input['api_timeout'] ) ? (int) $input['api_timeout'] : $defaults['api_timeout'];
            $sanitized['enable_logging'] = ! empty( $input['enable_logging'] ) ? true : false;
            $sanitized['enable_stock_sync'] = ! empty( $input['enable_stock_sync'] ) ? true : false;
            $sanitized['enable_price_sync'] = ! empty( $input['enable_price_sync'] ) ? true : false;
            $sanitized['enable_product_sync'] = ! empty( $input['enable_product_sync'] ) ? true : false;
            $sanitized['enable_promotion_engine'] = ! empty( $input['enable_promotion_engine'] ) ? true : false;
            $sanitized['promotion_rules'] = isset( $input['promotion_rules'] ) ? sanitize_textarea_field( (string) $input['promotion_rules'] ) : $defaults['promotion_rules'];
            $sanitized['api_key'] = isset( $input['api_key'] ) ? sanitize_text_field( (string) $input['api_key'] ) : $defaults['api_key'];
        }

        return $sanitized;
    }

    /** Field renderers **/
    public static function field_base_url(): void {
        $opts = self::get_options();
        printf('<input type="url" id="vec_base_url" name="%1$s[base_url]" value="%2$s" class="regular-text">', esc_attr( self::OPTION_NAME ), esc_attr( $opts['base_url'] ?? '' ) );
    }

    public static function field_company_code(): void {
        $opts = self::get_options();
        printf('<input type="text" id="vec_company_code" name="%1$s[company_code]" value="%2$s" class="regular-text">', esc_attr( self::OPTION_NAME ), esc_attr( $opts['company_code'] ?? '' ) );
    }

    public static function field_api_timeout(): void {
        $opts = self::get_options();
        printf('<input type="number" id="vec_api_timeout" name="%1$s[api_timeout]" value="%2$d" min="1" max="120">', esc_attr( self::OPTION_NAME ), absint( $opts['api_timeout'] ?? 15 ) );
    }

    public static function field_api_key(): void {
        $opts = self::get_options();
        printf('<input type="text" id="vec_api_key" name="%1$s[api_key]" value="%2$s" class="regular-text">', esc_attr( self::OPTION_NAME ), esc_attr( $opts['api_key'] ?? '' ) );
    }

    public static function field_enable_logging(): void {
        $opts = self::get_options();
        printf('<input type="checkbox" id="vec_enable_logging" name="%1$s[enable_logging]" value="1" %2$s> <label for="vec_enable_logging">%3$s</label>', esc_attr( self::OPTION_NAME ), checked( 1, $opts['enable_logging'] ?? false, false ), esc_html__( 'Log API requests and responses to help debugging.', 'victoria-erp-connector' ) );
    }

    public static function field_enable_stock_sync(): void {
        $opts = self::get_options();
        printf('<input type="checkbox" id="vec_enable_stock_sync" name="%1$s[enable_stock_sync]" value="1" %2$s> <label for="vec_enable_stock_sync">%3$s</label>', esc_attr( self::OPTION_NAME ), checked( 1, $opts['enable_stock_sync'] ?? false, false ), esc_html__( 'Automatically sync stocks on schedule.', 'victoria-erp-connector' ) );
    }

    public static function field_enable_price_sync(): void {
        $opts = self::get_options();
        printf('<input type="checkbox" id="vec_enable_price_sync" name="%1$s[enable_price_sync]" value="1" %2$s> <label for="vec_enable_price_sync">%3$s</label>', esc_attr( self::OPTION_NAME ), checked( 1, $opts['enable_price_sync'] ?? false, false ), esc_html__( 'Automatically sync prices on schedule.', 'victoria-erp-connector' ) );
    }

    public static function field_enable_product_sync(): void {
        $opts = self::get_options();
        printf('<input type="checkbox" id="vec_enable_product_sync" name="%1$s[enable_product_sync]" value="1" %2$s> <label for="vec_enable_product_sync">%3$s</label>', esc_attr( self::OPTION_NAME ), checked( 1, $opts['enable_product_sync'] ?? false, false ), esc_html__( 'Enable product catalog synchronization from ERP.', 'victoria-erp-connector' ) );
    }

    public static function field_enable_promotion_engine(): void {
        $opts = self::get_options();
        printf('<input type="checkbox" id="vec_enable_promotion_engine" name="%1$s[enable_promotion_engine]" value="1" %2$s> <label for="vec_enable_promotion_engine">%3$s</label>', esc_attr( self::OPTION_NAME ), checked( 1, $opts['enable_promotion_engine'] ?? false, false ), esc_html__( 'Enable automatic cart and checkout promotions.', 'victoria-erp-connector' ) );
    }

    public static function field_promotion_rules(): void {
        $opts = self::get_options();
        printf(
            '<textarea id="vec_promotion_rules" name="%1$s[promotion_rules]" rows="12" class="large-text code">%2$s</textarea><p class="description">%3$s</p>',
            esc_attr( self::OPTION_NAME ),
            esc_textarea( $opts['promotion_rules'] ?? '' ),
            esc_html__( 'Enter promotion rules as a JSON array. Supported rule types: combo, bundle, free_item, automatic_discount.', 'victoria-erp-connector' )
        );
    }

    /**
     * Retrieve stored options.
     *
     * @return array<string,mixed>
     */
    public static function get_options(): array {
        $opts = get_option( self::OPTION_NAME, [] );
        if ( ! is_array( $opts ) ) {
            return [];
        }
        return $opts;
    }

    /**
     * Test ERP connection using saved settings.
     *
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    public static function test_connection(): true|WP_Error {
        $opts = self::get_options();
        $url = $opts['base_url'] ?? '';
        $timeout = isset( $opts['api_timeout'] ) ? (int) $opts['api_timeout'] : 15;
        $api_key = $opts['api_key'] ?? '';

        if ( empty( $url ) ) {
            return new WP_Error( 'vec_no_url', __( 'Base URL is not configured.', 'victoria-erp-connector' ) );
        }

        $args = [
            'timeout' => $timeout,
            'headers' => [],
        ];

        if ( $api_key !== '' ) {
            $args['headers']['X-VEC-API-KEY'] = $api_key;
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code >= 200 && $code < 400 ) {
            return true;
        }

        return new WP_Error( 'vec_bad_response', sprintf( __( 'Unexpected HTTP response code: %d', 'victoria-erp-connector' ), $code ) );
    }
}

