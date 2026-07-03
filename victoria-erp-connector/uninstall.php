<?php
/**
 * Uninstall handler for Victoria ERP Connector.
 *
 * This file is executed directly by WordPress when the plugin is uninstalled.
 * It performs necessary cleanup such as removing options, network options,
 * transients and clearing scheduled cron hooks used by the plugin.
 *
 * @package VictoriaERPConnector\Uninstall
 */

declare( strict_types=1 );

namespace VictoriaERPConnector\Uninstall;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Class Uninstaller
 *
 * Performs safe removal of plugin data on uninstall.
 */
final class Uninstaller {
    /**
     * Option key used to store plugin settings.
     */
    private const OPTION_NAME = 'vec_settings';

    /**
     * Transient keys to remove on uninstall.
     *
     * @var string[]
     */
    private static array $transients = [
        'vec_last_sync',
    ];

    /**
     * Cron hooks used by the plugin that should be cleared.
     *
     * @var string[]
     */
    private static array $cron_hooks = [
        'vec_sync_stock',
        'vec_sync_pricing',
    ];

    /**
     * Execute uninstall routine.
     *
     * @return void
     */
    public static function uninstall(): void {
        if ( function_exists( 'delete_option' ) ) {
            delete_option( self::OPTION_NAME );
        }

        if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'delete_site_option' ) ) {
            // Remove network option when running on multisite.
            delete_site_option( self::OPTION_NAME );
        }

        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            foreach ( self::$cron_hooks as $hook ) {
                wp_clear_scheduled_hook( $hook );
            }
        }

        if ( function_exists( 'delete_transient' ) ) {
            foreach ( self::$transients as $transient ) {
                delete_transient( $transient );
            }
        }
    }
}

Uninstaller::uninstall();
