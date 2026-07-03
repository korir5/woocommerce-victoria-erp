<?php
declare(strict_types=1);

namespace VictoriaERPConnector\Logger;

use VictoriaERPConnector\Plugin_Bootstrap;
use WP_Error;

/**
 * Class Logger
 *
 * Production-ready file logger that writes daily JSON lines with the following
 * fields: date, endpoint, duration, response_code, payload, errors.
 *
 * Provides convenience helper functions defined in this file as well.
 *
 * @package VictoriaERPConnector\Logger
 */
final class Logger {
    /**
     * Ensure log directory exists and return its absolute path.
     *
     * @return string
     */
    private static function get_log_dir(): string {
        $dir = rtrim( Plugin_Bootstrap::PLUGIN_DIR, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'logs';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        return $dir;
    }

    /**
     * Write a structured entry to today's log file.
     *
     * @param array<string,mixed> $entry
     * @return void
     */
    private static function write_entry( array $entry ): void {
        $dir = self::get_log_dir();
        $file = $dir . DIRECTORY_SEPARATOR . 'vec-' . gmdate( 'Y-m-d' ) . '.log';

        $line = json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( $line === false ) {
            $line = json_encode( [ 'date' => gmdate( 'c' ), 'error' => 'Failed to json_encode log entry' ] );
        }

        $fp = fopen( $file, 'a' );
        if ( $fp === false ) {
            return;
        }

        if ( flock( $fp, LOCK_EX ) ) {
            fwrite( $fp, $line . PHP_EOL );
            fflush( $fp );
            flock( $fp, LOCK_UN );
        }

        fclose( $fp );
    }

    /**
     * Log API request/response information.
     *
     * @param string $endpoint
     * @param array<string,mixed> $meta Must include at least 'duration' (float).
     * @return void
     */
    public static function log_api( string $endpoint, array $meta ): void {
        $entry = [
            'date'          => gmdate( 'c' ),
            'level'         => 'info',
            'type'          => 'api',
            'endpoint'      => $endpoint,
            'duration'      => isset( $meta['duration'] ) ? (float) $meta['duration'] : 0.0,
            'response_code' => isset( $meta['response_code'] ) ? (int) $meta['response_code'] : null,
            'payload'       => $meta['payload'] ?? null,
            'response'      => $meta['response'] ?? null,
        ];

        self::write_entry( $entry );
    }

    /**
     * Log an error with contextual information.
     *
     * @param string $endpoint
     * @param string|WP_Error $error
     * @param array<string,mixed> $context
     * @return void
     */
    public static function log_error( string $endpoint, $error, array $context = [] ): void {
        $message = $error instanceof WP_Error ? $error->get_error_message() : (string) $error;

        $entry = [
            'date'     => gmdate( 'c' ),
            'level'    => 'error',
            'type'     => 'error',
            'endpoint' => $endpoint,
            'message'  => $message,
            'context'  => $context,
        ];

        self::write_entry( $entry );
    }

    /**
     * Log debug information.
     *
     * @param string $message
     * @param array<string,mixed> $context
     * @return void
     */
    public static function log_debug( string $message, array $context = [] ): void {
        $entry = [
            'date'    => gmdate( 'c' ),
            'level'   => 'debug',
            'type'    => 'debug',
            'message' => $message,
            'context' => $context,
        ];

        self::write_entry( $entry );
    }
}

/**
 * Helper function: log API events.
 *
 * @param string $endpoint
 * @param array<string,mixed> $meta
 * @return void
 */
function vec_log_api( string $endpoint, array $meta ): void {
    Logger::log_api( $endpoint, $meta );
}

/**
 * Helper function: log errors.
 *
 * @param string $endpoint
 * @param string|WP_Error $error
 * @param array<string,mixed> $context
 * @return void
 */
function vec_log_error( string $endpoint, $error, array $context = [] ): void {
    Logger::log_error( $endpoint, $error, $context );
}

/**
 * Helper function: log debug messages.
 *
 * @param string $message
 * @param array<string,mixed> $context
 * @return void
 */
function vec_log_debug( string $message, array $context = [] ): void {
    Logger::log_debug( $message, $context );
}
