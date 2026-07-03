<?php
declare(strict_types=1);

namespace VictoriaERPConnector\Core;

use VictoriaERPConnector\Cron\SyncScheduler;

/**
 * Class Activator
 *
 * Handles plugin activation tasks such as creating default options,
 * scheduling recurring cron events and performing one-time setup steps.
 *
 * @package VictoriaERPConnector\Core
 */
final class Activator {
    /**
     * Option name where plugin settings are stored.
     */
    private const OPTION_NAME = 'vec_settings';

    /**
     * Default plugin options.
     *
     * @return array<string,mixed>
     */
    private static function default_options(): array {
        return [
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
    }

    /**
     * Activation routine executed via WordPress `register_activation_hook`.
     *
     * @return void
     */
    public static function activate(): void {
        // Ensure functions exist before calling them to allow static analysis
        if ( function_exists( 'add_option' ) ) {
            $defaults = self::default_options();
            // Only add the option if it does not already exist.
            add_option( self::OPTION_NAME, $defaults );
        }

        if ( class_exists( SyncScheduler::class ) ) {
            SyncScheduler::ensure_schedules();
        } elseif ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) ) {
            if ( ! wp_next_scheduled( SyncScheduler::STOCK_JOB ) ) {
                wp_schedule_event( time(), 'hourly', SyncScheduler::STOCK_JOB );
            }

            if ( ! wp_next_scheduled( SyncScheduler::PRICING_JOB ) ) {
                wp_schedule_event( time(), 'daily', SyncScheduler::PRICING_JOB );
            }

            if ( ! wp_next_scheduled( SyncScheduler::PRODUCT_JOB ) ) {
                wp_schedule_event( time(), 'daily', SyncScheduler::PRODUCT_JOB );
            }
        }

        // Flush rewrite rules if the function exists — safe no-op otherwise.
        if ( function_exists( 'flush_rewrite_rules' ) ) {
            flush_rewrite_rules();
        }
    }
}
