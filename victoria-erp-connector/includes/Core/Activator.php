<?php
declare(strict_types=1);

namespace VictoriaERPConnector\Core;

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
            'api_url'     => '',
            'api_key'     => '',
            'enable_sync' => false,
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

        // Schedule recurring tasks if scheduling functions are available.
        if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) ) {
            if ( ! wp_next_scheduled( 'vec_sync_stock' ) ) {
                wp_schedule_event( time(), 'hourly', 'vec_sync_stock' );
            }

            if ( ! wp_next_scheduled( 'vec_sync_pricing' ) ) {
                wp_schedule_event( time(), 'daily', 'vec_sync_pricing' );
            }
        }

        // Flush rewrite rules if the function exists — safe no-op otherwise.
        if ( function_exists( 'flush_rewrite_rules' ) ) {
            flush_rewrite_rules();
        }
    }
}
