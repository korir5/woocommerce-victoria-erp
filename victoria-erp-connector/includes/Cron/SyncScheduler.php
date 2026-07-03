<?php
declare(strict_types=1);

namespace VictoriaERPConnector\Cron;

use VictoriaERPConnector\WooCommerce\Pricing;
use VictoriaERPConnector\WooCommerce\Stock;
use VictoriaERPConnector\API\ERPClient;
use VictoriaERPConnector\Logger\Logger;

/**
 * Class SyncScheduler
 *
 * Integrates with Action Scheduler for robust recurring jobs and retries.
 * Falls back to WP Cron when Action Scheduler is not active.
 */
final class SyncScheduler {
    public const STOCK_JOB = 'vec_as_refresh_stock';
    public const PRICING_JOB = 'vec_as_refresh_prices';
    public const RETRY_JOB = 'vec_as_retry_failed_requests';
    public const CLEANUP_JOB = 'vec_as_cleanup_logs';
    public const PRODUCT_JOB = 'vec_as_refresh_products';
    public const MANUAL_JOB = 'vec_as_manual_sync';

    private const FAILED_REQUESTS_OPTION = 'vec_failed_requests';
    private const MAX_FAILED_ATTEMPTS = 3;
    private const AUTO_SYNC_INTERVAL = 1800;
    private const PRODUCT_SYNC_INTERVAL = 86400;
    private const RETRY_INTERVAL = 3600;
    private const CLEANUP_INTERVAL = 86400;
    private const LOG_RETENTION_SECONDS = 2592000; // 30 days

    public static function register_hooks(): void {
        add_action( self::STOCK_JOB, [ self::class, 'refresh_stock' ] );
        add_action( self::PRICING_JOB, [ self::class, 'refresh_prices' ] );
        add_action( self::PRODUCT_JOB, [ self::class, 'refresh_products' ] );
        add_action( self::RETRY_JOB, [ self::class, 'retry_failed_requests' ] );
        add_action( self::CLEANUP_JOB, [ self::class, 'cleanup_logs' ] );
        add_action( self::MANUAL_JOB, [ self::class, 'manual_sync' ] );
        add_action( 'vec_as_failed_request', [ self::class, 'enqueue_failed_request_action' ], 10, 3 );

        add_filter( 'cron_schedules', [ self::class, 'register_cron_schedules' ] );
    }

    public static function ensure_schedules(): void {
        if ( self::action_scheduler_available() ) {
            self::schedule_action_scheduler_job( self::STOCK_JOB, self::AUTO_SYNC_INTERVAL );
            self::schedule_action_scheduler_job( self::PRICING_JOB, self::AUTO_SYNC_INTERVAL );
            self::schedule_action_scheduler_job( self::PRODUCT_JOB, self::PRODUCT_SYNC_INTERVAL );
            self::schedule_action_scheduler_job( self::RETRY_JOB, self::RETRY_INTERVAL );
            self::schedule_action_scheduler_job( self::CLEANUP_JOB, self::CLEANUP_INTERVAL );
            return;
        }

        self::schedule_wp_cron_job( self::STOCK_JOB, 'vec_thirty_minutes' );
        self::schedule_wp_cron_job( self::PRICING_JOB, 'vec_thirty_minutes' );
        self::schedule_wp_cron_job( self::PRODUCT_JOB, 'daily' );
        self::schedule_wp_cron_job( self::RETRY_JOB, 'hourly' );
        self::schedule_wp_cron_job( self::CLEANUP_JOB, 'daily' );
    }

    public static function clear_schedules(): void {
        if ( self::action_scheduler_available() ) {
            self::clear_action_scheduler_job( self::STOCK_JOB );
            self::clear_action_scheduler_job( self::PRICING_JOB );
            self::clear_action_scheduler_job( self::PRODUCT_JOB );
            self::clear_action_scheduler_job( self::RETRY_JOB );
            self::clear_action_scheduler_job( self::CLEANUP_JOB );
            self::clear_action_scheduler_job( self::MANUAL_JOB );
            return;
        }

        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( self::STOCK_JOB );
            wp_clear_scheduled_hook( self::PRICING_JOB );
            wp_clear_scheduled_hook( self::RETRY_JOB );
            wp_clear_scheduled_hook( self::CLEANUP_JOB );
        }
    }

    public static function schedule_manual_sync(): void {
        if ( self::action_scheduler_available() ) {
            if ( ! as_next_scheduled_action( self::MANUAL_JOB ) ) {
                as_schedule_single_action( time(), self::MANUAL_JOB );
            }
            return;
        }

        do_action( self::MANUAL_JOB );
    }

    public static function manual_sync(): void {
        self::refresh_stock();
        self::refresh_prices();
        self::refresh_products();
    }

    public static function refresh_products(): void {
        if ( ! class_exists( '\VictoriaERPConnector\WooCommerce\ProductSync' ) ) {
            return;
        }

        $updated = \VictoriaERPConnector\WooCommerce\ProductSync::refresh_products();
        Logger::log_debug( 'Scheduled product refresh completed.', [ 'updated' => $updated ] );
    }

    public static function refresh_stock(): void {
        if ( ! class_exists( Stock::class ) ) {
            return;
        }

        $updated = Stock::sync_stock_full();
        Logger::log_debug( 'Scheduled stock refresh completed.', [ 'updated' => $updated ] );
    }

    public static function refresh_prices(): void {
        if ( ! class_exists( Pricing::class ) ) {
            return;
        }

        $updated = Pricing::sync_pricing();
        Logger::log_debug( 'Scheduled pricing refresh completed.', [ 'updated' => $updated ] );
    }

    public static function retry_failed_requests(): void {
        $requests = self::get_failed_requests();
        if ( empty( $requests ) ) {
            return;
        }

        $client = new ERPClient();
        $next_queue = [];

        foreach ( $requests as $request ) {
            if ( ! isset( $request['endpoint'], $request['query'], $request['attempts'] ) ) {
                continue;
            }

            $attempts = (int) $request['attempts'];
            try {
                $client->retryRequest( $request['endpoint'], $request['query'] );
                continue;
            } catch ( \Throwable $e ) {
                $attempts++;
                if ( $attempts < self::MAX_FAILED_ATTEMPTS ) {
                    $request['attempts'] = $attempts;
                    $request['last_attempt'] = current_time( 'mysql' );
                    $next_queue[] = $request;
                } else {
                    Logger::log_error( self::RETRY_JOB, $e, [ 'endpoint' => $request['endpoint'], 'query' => $request['query'], 'attempts' => $attempts ] );
                }
            }
        }

        self::save_failed_requests( $next_queue );
    }

    public static function cleanup_logs(): void {
        $log_dir = rtrim( dirname( dirname( __DIR__ ) ) . DIRECTORY_SEPARATOR . 'logs', DIRECTORY_SEPARATOR );
        if ( ! is_dir( $log_dir ) ) {
            return;
        }

        $files = glob( $log_dir . DIRECTORY_SEPARATOR . 'vec-*.log' );
        if ( ! is_array( $files ) ) {
            return;
        }

        foreach ( $files as $file ) {
            if ( ! is_file( $file ) ) {
                continue;
            }

            $age = time() - filemtime( $file );
            if ( $age > self::LOG_RETENTION_SECONDS ) {
                @unlink( $file );
            }
        }
    }

    public static function enqueue_failed_request_action( string $endpoint, array $query, string $reason ): void {
        self::enqueue_failed_request( $endpoint, $query, $reason );
    }

    public static function enqueue_failed_request( string $endpoint, array $query, string $reason ): void {
        $requests = self::get_failed_requests();
        $requests[] = [
            'endpoint'     => $endpoint,
            'query'        => $query,
            'reason'       => $reason,
            'attempts'     => 1,
            'created_at'   => current_time( 'mysql' ),
            'last_attempt' => current_time( 'mysql' ),
        ];
        self::save_failed_requests( $requests );
    }

    public static function register_cron_schedules( array $schedules ): array {
        if ( ! isset( $schedules['vec_thirty_minutes'] ) ) {
            $schedules['vec_thirty_minutes'] = [
                'interval' => self::AUTO_SYNC_INTERVAL,
                'display'  => 'Every 30 Minutes',
            ];
        }

        return $schedules;
    }

    private static function get_failed_requests(): array {
        $data = get_option( self::FAILED_REQUESTS_OPTION, [] );
        if ( ! is_array( $data ) ) {
            return [];
        }

        return $data;
    }

    private static function save_failed_requests( array $requests ): void {
        update_option( self::FAILED_REQUESTS_OPTION, $requests );
    }

    private static function action_scheduler_available(): bool {
        return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' );
    }

    private static function schedule_action_scheduler_job( string $hook, int $interval ): void {
        if ( ! self::action_scheduler_available() ) {
            return;
        }

        if ( ! as_next_scheduled_action( $hook ) ) {
            as_schedule_recurring_action( time(), $interval, $hook );
        }
    }

    private static function clear_action_scheduler_job( string $hook ): void {
        if ( ! self::action_scheduler_available() ) {
            return;
        }

        as_unschedule_all_actions( $hook );
    }

    private static function schedule_wp_cron_job( string $hook, string $recurrence ): void {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
            return;
        }

        if ( ! wp_next_scheduled( $hook ) ) {
            wp_schedule_event( time(), $recurrence, $hook );
        }
    }
}
