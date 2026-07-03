<?php
declare(strict_types=1);

namespace VictoriaERPConnector\Core;

use VictoriaERPConnector\Plugin_Bootstrap;

/**
 * Class Plugin
 *
 * Central plugin class responsible for registering hooks, enqueueing
 * assets, managing basic settings and scheduling recurring tasks.
 *
 * @package VictoriaERPConnector\Core
 */
final class Plugin {
    /**
     * Plugin version.
     *
     * @var string
     */
    private string $version;

    /**
     * Plugin directory path.
     *
     * @var string
     */
    private string $plugin_dir;

    /**
     * Plugin URL.
     *
     * @var string
     */
    private string $plugin_url;

    /**
     * Option name used to store plugin settings.
     */
    private const OPTION_NAME = 'vec_settings';

    /**
     * Plugin constructor.
     *
     * Registers high-level WordPress hooks used by the plugin.
     *
     * @param string|null $version Plugin version to use for asset versioning.
     */
    public function __construct(?string $version = null) {
        $this->version = $version ?? Plugin_Bootstrap::VERSION;
        $this->plugin_dir = Plugin_Bootstrap::PLUGIN_DIR;
        $this->plugin_url = plugin_dir_url( Plugin_Bootstrap::PLUGIN_FILE );

        add_action( 'init', [ $this, 'init' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_public_assets' ] );
    }

    /**
     * Initialization hook.
     *
     * Schedules recurring tasks and performs one-time setup safely.
     *
     * @return void
     */
    public function init(): void {
        $this->ensure_cron_schedules();
    }

    /**
     * Ensure the plugin's cron schedules are registered and scheduled.
     *
     * @return void
     */
    private function ensure_cron_schedules(): void {
        if ( ! function_exists( 'wp_next_scheduled' ) ) {
            return;
        }

        if ( ! wp_next_scheduled( 'vec_sync_stock' ) ) {
            wp_schedule_event( time(), 'hourly', 'vec_sync_stock' );
        }

        if ( ! wp_next_scheduled( 'vec_sync_pricing' ) ) {
            wp_schedule_event( time(), 'daily', 'vec_sync_pricing' );
        }
    }

    /**
     * Register plugin settings.
     *
     * @return void
     */
    public function register_settings(): void {
        if ( ! function_exists( 'register_setting' ) ) {
            return;
        }

        register_setting(
            'vec_options_group',
            self::OPTION_NAME,
            [ $this, 'sanitize_settings' ]
        );
    }

    /**
     * Sanitize settings input.
     *
     * @param mixed $input Raw input from the settings form.
     * @return array Sanitized settings array.
     */
    public function sanitize_settings( $input ): array {
        $sanitized = [
            'api_url'     => '',
            'api_key'     => '',
            'enable_sync' => false,
        ];

        if ( is_array( $input ) ) {
            if ( isset( $input['api_url'] ) ) {
                $sanitized['api_url'] = esc_url_raw( trim( (string) $input['api_url'] ) );
            }

            if ( isset( $input['api_key'] ) ) {
                $sanitized['api_key'] = sanitize_text_field( (string) $input['api_key'] );
            }

            $sanitized['enable_sync'] = ! empty( $input['enable_sync'] ) ? true : false;
        }

        return $sanitized;
    }

    /**
     * Retrieve plugin settings.
     *
     * @return array Settings array.
     */
    public function get_settings(): array {
        $opts = get_option( self::OPTION_NAME, [] );

        if ( ! is_array( $opts ) ) {
            return [];
        }

        return $opts;
    }

    /**
     * Enqueue public-facing assets.
     *
     * @return void
     */
    public function enqueue_public_assets(): void {
        if ( ! function_exists( 'wp_enqueue_script' ) ) {
            return;
        }

        $handle_style = 'vec-public-style';
        $handle_script = 'vec-public';

        wp_enqueue_style(
            $handle_style,
            $this->plugin_url . 'assets/css/public.css',
            [],
            $this->version
        );

        wp_enqueue_script(
            $handle_script,
            $this->plugin_url . 'assets/js/public.js',
            [ 'jquery' ],
            $this->version,
            true
        );
    }

    /**
     * Enqueue admin assets and localize scripts with useful data.
     *
     * @return void
     */
    public function enqueue_admin_assets(): void {
        if ( ! function_exists( 'wp_enqueue_script' ) ) {
            return;
        }

        $handle_style = 'vec-admin-style';
        $handle_script = 'vec-admin';

        wp_enqueue_style(
            $handle_style,
            $this->plugin_url . 'assets/css/admin.css',
            [ 'wp-components' ],
            $this->version
        );

        wp_enqueue_script(
            $handle_script,
            $this->plugin_url . 'assets/js/admin.js',
            [ 'wp-api', 'jquery' ],
            $this->version,
            true
        );

        $settings = $this->get_settings();

        wp_localize_script(
            $handle_script,
            'vecAdminSettings',
            [
                'apiUrl'    => $settings['api_url'] ?? '',
                'apiKey'    => $settings['api_key'] ?? '',
                'restNonce' => wp_create_nonce( 'wp_rest' ),
            ]
        );
    }
}
