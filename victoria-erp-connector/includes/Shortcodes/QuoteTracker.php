<?php
declare(strict_types=1);

namespace VictoriaERPConnector\Shortcodes;

use InvalidArgumentException;
use VictoriaERPConnector\API\ERPClient;
use VictoriaERPConnector\Plugin_Bootstrap;
use RuntimeException;

/**
 * Class QuoteTracker
 *
 * Handles the [victoria_quote_tracker] shortcode and displays a responsive
 * quote lookup form backed by Victoria ERP.
 */
final class QuoteTracker {
    public static function register_hooks(): void {
        add_action( 'init', [ self::class, 'register_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    public static function register_shortcode(): void {
        add_shortcode( 'victoria_quote_tracker', [ self::class, 'render_shortcode' ] );
    }

    public static function enqueue_assets(): void {
        if ( ! function_exists( 'wp_enqueue_style' ) ) {
            return;
        }

        wp_enqueue_style(
            'vec-quote-tracker',
            plugin_dir_url( Plugin_Bootstrap::PLUGIN_FILE ) . 'assets/css/public.css',
            [],
            Plugin_Bootstrap::VERSION
        );
    }

    public static function render_shortcode( array $atts = [] ): string {
        $quote_id = '';
        $result = null;
        $error = '';

        if ( isset( $_POST['vec_quote_tracker_submit'] ) ) {
            if ( ! isset( $_POST['vec_quote_tracker_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['vec_quote_tracker_nonce'] ), 'vec_quote_tracker' ) ) {
                $error = __( 'Security check failed. Please try again.', 'victoria-erp-connector' );
            } else {
                $quote_id = sanitize_text_field( wp_unslash( $_POST['vec_quote_id'] ?? '' ) );
                try {
                    if ( $quote_id === '' ) {
                        throw new InvalidArgumentException( __( 'Please enter a quote number.', 'victoria-erp-connector' ) );
                    }

                    $client = new ERPClient();
                    $result = $client->getQuoteStatus( $quote_id );
                } catch ( InvalidArgumentException | RuntimeException $exception ) {
                    $error = $exception->getMessage();
                } catch ( \Throwable $exception ) {
                    $error = __( 'Unable to fetch quote status. Please try again later.', 'victoria-erp-connector' );
                }
            }
        }

        return self::render_template(
            'shortcodes/quote-tracker.php',
            [
                'quote_id' => $quote_id,
                'result'   => $result,
                'error'    => $error,
            ]
        );
    }

    private static function render_template( string $template, array $data = [] ): string {
        $template_path = self::get_templates_dir() . $template;
        if ( ! is_file( $template_path ) ) {
            return '<p>' . esc_html__( 'Quote tracker template not found.', 'victoria-erp-connector' ) . '</p>';
        }

        ob_start();
        extract( $data, EXTR_SKIP );
        include $template_path;
        return ob_get_clean();
    }

    private static function get_templates_dir(): string {
        return Plugin_Bootstrap::PLUGIN_DIR . '/templates/';
    }
}
