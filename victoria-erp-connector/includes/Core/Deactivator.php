<?php
declare(strict_types=1);

namespace VictoriaERPConnector\Core;

/**
 * Class Deactivator
 *
 * Handles plugin deactivation tasks such as removing scheduled cron events
 * and flushing rewrite rules to leave the site in a clean state.
 *
 * @package VictoriaERPConnector\Core
 */
final class Deactivator {
    /**
     * Cron hooks used by the plugin that should be cleared on deactivation.
     *
     * @var string[]
     */
    private static array $cron_hooks = [
        'vec_sync_stock',
        'vec_sync_pricing',
    ];

    /**
     * Deactivation routine executed via WordPress `register_deactivation_hook`.
     *
     * @return void
     */
    public static function deactivate(): void {
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            foreach ( self::$cron_hooks as $hook ) {
                wp_clear_scheduled_hook( $hook );
            }
        }

        // Ensure rewrite rules are flushed to avoid stale endpoints.
        if ( function_exists( 'flush_rewrite_rules' ) ) {
            flush_rewrite_rules();
        }
    }
}
