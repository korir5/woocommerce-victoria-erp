<?php
declare(strict_types=1);

namespace VictoriaERPConnector\Core;

use VictoriaERPConnector\Cron\SyncScheduler;
use VictoriaERPConnector\Plugin_Bootstrap;

/**
 * Class Loader
 *
 * Initializes plugin subsystems, registers WordPress hooks and ties
 * together admin, API and integration classes when available.
 *
 * @package VictoriaERPConnector\Core
 */
final class Loader {
    /**
     * Holds instantiated admin class when available.
     *
     * @var object|null
     */
    private ?object $admin = null;

    /**
     * Holds instantiated API class when available.
     *
     * @var object|null
     */
    private ?object $api = null;

    /**
     * Plugin directory path.
     *
     * @var string
     */
    private string $plugin_dir;

    /**
     * Loader constructor.
     */
    public function __construct() {
        $this->plugin_dir = Plugin_Bootstrap::PLUGIN_DIR;
    }

    /**
     * Boot the loader: register hooks used by the plugin.
     */
    public function run(): void {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('init', [$this, 'init']);
        add_action('admin_init', [$this, 'admin_init']);
    }

    /**
     * Load plugin textdomain for translations.
     */
    public function load_textdomain(): void {
        $domain = 'victoria-erp-connector';
        $rel_path = dirname(plugin_basename(Plugin_Bootstrap::PLUGIN_FILE)) . '/languages';
        load_plugin_textdomain($domain, false, $rel_path);
    }

    /**
     * Initialize public and API components.
     */
    public function init(): void {
        // Initialize API subsystem if available.
        if (class_exists(\VictoriaERPConnector\API\Api::class)) {
            $this->api = new \VictoriaERPConnector\API\Api();
            add_action('rest_api_init', [$this, 'register_api_routes']);
        }

        // Initialize admin subsystem if available.
        if (class_exists(\VictoriaERPConnector\Admin\Admin::class)) {
            $this->admin = new \VictoriaERPConnector\Admin\Admin();
            add_action('admin_menu', [$this, 'register_admin_pages']);
        }

        // Initialize WooCommerce integration hooks if present.
        if ( class_exists( \VictoriaERPConnector\WooCommerce\Stock::class ) ) {
            \VictoriaERPConnector\WooCommerce\Stock::register_hooks();
        }

        if ( class_exists( \VictoriaERPConnector\WooCommerce\Pricing::class ) ) {
            \VictoriaERPConnector\WooCommerce\Pricing::register_hooks();
        }

        if ( class_exists( \VictoriaERPConnector\WooCommerce\ProductSync::class ) ) {
            \VictoriaERPConnector\WooCommerce\ProductSync::register_hooks();
        }

        if ( class_exists( \VictoriaERPConnector\WooCommerce\PromotionEngine::class ) ) {
            \VictoriaERPConnector\WooCommerce\PromotionEngine::register_hooks();
        }

        if ( class_exists( \VictoriaERPConnector\WooCommerce\MyAccount::class ) ) {
            \VictoriaERPConnector\WooCommerce\MyAccount::register_hooks();
        }

        if ( class_exists( \VictoriaERPConnector\Shortcodes\QuoteTracker::class ) ) {
            \VictoriaERPConnector\Shortcodes\QuoteTracker::register_hooks();
        }

        if ( class_exists( SyncScheduler::class ) ) {
            SyncScheduler::register_hooks();
            SyncScheduler::ensure_schedules();
        }
    }

    /**
     * Admin initialization hook.
     */
    public function admin_init(): void {
        if ($this->admin && method_exists($this->admin, 'init')) {
            $this->admin->init();
        }
    }

    /**
     * Register REST API routes by delegating to the API class.
     */
    public function register_api_routes(): void {
        if ($this->api && method_exists($this->api, 'register_routes')) {
            $this->api->register_routes();
        }
    }

    /**
     * Register admin pages by delegating to the Admin class.
     */
    public function register_admin_pages(): void {
        if ($this->admin && method_exists($this->admin, 'register_pages')) {
            $this->admin->register_pages();
        }
    }
}
