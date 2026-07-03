<?php
declare(strict_types=1);

namespace VictoriaERPConnector\Cache;

use InvalidArgumentException;
use WP_Error;

/**
 * Simple cache backed by WordPress transients.
 *
 * Provides per-endpoint keys, automatic expiry via transient TTL, manual
 * flush/forget and helper wrappers.
 */
final class Cache {
    private const PREFIX = 'vec_cache_';

    /**
     * Build a stable cache key for an endpoint + query parameters.
     */
    public static function make_key( string $endpoint, array $query = [] ): string {
        ksort( $query );
        $payload = $endpoint . '|' . wp_json_encode( $query );
        return self::PREFIX . md5( $payload );
    }

    /**
     * Remember an entry: return cached value or compute and store with TTL.
     *
     * @param string $endpoint
     * @param array $query
     * @param callable $callback Should return an array on success.
     * @param int $ttl Seconds to keep the cached value.
     * @return array|string|int|float|bool|null
     */
    public static function remember( string $endpoint, array $query, callable $callback, int $ttl = 300 ) {
        if ( $ttl < 1 ) {
            throw new InvalidArgumentException( 'TTL must be a positive integer.' );
        }

        $key = self::make_key( $endpoint, $query );
        $cached = get_transient( $key );
        if ( $cached !== false ) {
            return $cached;
        }

        $value = $callback();

        // Only cache scalars/arrays/objects that are serializable; set_transient
        // will return false on failure but we ignore that here.
        set_transient( $key, $value, $ttl );

        return $value;
    }

    /**
     * Forget a specific endpoint+query cache entry.
     */
    public static function forget( string $endpoint, array $query = [] ): void {
        $key = self::make_key( $endpoint, $query );
        delete_transient( $key );
    }

    /**
     * Flush all plugin caches (transients with the configured prefix).
     */
    public static function flush(): void {
        global $wpdb;

        $option_name_like = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
        $sql = $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $option_name_like );
        $rows = $wpdb->get_col( $sql );
        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $option_name ) {
            $transient = preg_replace( '/^_transient_/', '', $option_name );
            if ( $transient ) {
                delete_transient( $transient );
            }
        }
    }
}

// Helper functions
function vec_cache_remember( string $endpoint, array $query, callable $callback, int $ttl = 300 ) {
    return Cache::remember( $endpoint, $query, $callback, $ttl );
}

function vec_cache_forget( string $endpoint, array $query = [] ): void {
    Cache::forget( $endpoint, $query );
}

function vec_cache_flush(): void {
    Cache::flush();
}
