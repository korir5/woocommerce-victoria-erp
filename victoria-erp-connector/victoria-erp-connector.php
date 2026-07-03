<?php
/**
 * Plugin Name: Victoria ERP Connector
 * Plugin URI:  https://example.com/plugins/victoria-erp-connector
 * Description: Connects WooCommerce with Victoria ERP — robust, extensible integration.
 * Version:     1.0.0
 * Author:      korir5
 * Author URI:  https://example.com
 * Text Domain: victoria-erp-connector
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Requires PHP: 8.2
 *
 * @package VictoriaERPConnector
 */

declare(strict_types=1);

namespace VictoriaERPConnector;

use InvalidArgumentException;

/**
 * Plugin bootstrap and initialization.
 *
 * Responsible for registering autoload, activation/deactivation hooks,
 * and starting the plugin loader.
 */
final class Plugin_Bootstrap {
    /**
     * Plugin file path constant.
     *
     * @var string
     */
    public const PLUGIN_FILE = __FILE__;

    /**
     * Plugin base directory.
     *
     * @var string
     */
    public const PLUGIN_DIR = __DIR__;

    /**
     * Plugin version.
     *
     * @var string
     */
    public const VERSION = '1.0.0';

    /**
     * PSR-4 namespace prefix used by this plugin.
     *
     * @var string
     */
    private const NAMESPACE_PREFIX = __NAMESPACE__ . '\\';

    /**
     * Relative base directory where the PSR-4 classes live.
     *
     * This maps the plugin namespace to the `includes/` directory.
     *
     * @var string
     */
    private const SRC_DIR = '/includes/';

    /**
     * Initialize the plugin.
     *
     * Registers autoloader, activation/deactivation hooks and runs the core loader when available.
     *
     * @return void
     */
    public static function init(): void {
        self::register_autoloader();

        // Register activation / deactivation hooks using fully-qualified callable strings.
        register_activation_hook(
            self::PLUGIN_FILE,
            '\\' . self::NAMESPACE_PREFIX . 'Core\\Activator::activate'
        );

        register_deactivation_hook(
            self::PLUGIN_FILE,
            '\\' . self::NAMESPACE_PREFIX . 'Core\\Deactivator::deactivate'
        );

        // Attempt to instantiate and run the core loader if present.
        $loader_class = '\\' . self::NAMESPACE_PREFIX . 'Core\\Loader';

        if (class_exists($loader_class)) {
            /** @var object $loader */
            $loader = new $loader_class(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
            if (method_exists($loader, 'run')) {
                $loader->run();
            }
        }
    }

    /**
     * Registers a PSR-4 autoloader for the plugin namespace.
     *
     * The autoloader maps the `VictoriaERPConnector\` namespace to the `includes/` directory.
     *
     * @return void
     */
    private static function register_autoloader(): void {
        spl_autoload_register(
            function (string $class): void {
                $prefix = self::NAMESPACE_PREFIX;

                // Only attempt to load classes from this plugin's namespace.
                if (str_starts_with($class, $prefix) === false) {
                    return;
                }

                // Strip prefix and convert namespace separators to directory separators.
                $relative_class = substr($class, strlen($prefix));
                if ($relative_class === false) {
                    throw new InvalidArgumentException('Invalid class name for autoload: ' . $class);
                }

                $relative_path = str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';

                // Build the absolute path to the file inside includes/.
                $file = self::PLUGIN_DIR . self::SRC_DIR . $relative_path;

                // Normalize path to avoid double slashes.
                $file = str_replace(['\\\\', '//'], DIRECTORY_SEPARATOR, $file);

                if (is_file($file) && is_readable($file)) {
                    require_once $file;
                }
            }
        );
    }
}

// Initialize the plugin.
Plugin_Bootstrap::init();
