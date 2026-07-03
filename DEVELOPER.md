# Developer Guide

## Structure

- `victoria-erp-connector.php`: plugin bootstrap and PSR-4 autoloader.
- `includes/`: PHP class source files.
- `templates/`: theme-compatible template files.
- `assets/`: CSS and JavaScript assets.

## Namespaces

The plugin uses `VictoriaERPConnector\` and maps it to `includes/`.

## Available subsystems

- `Core\Loader`: initializes plugin subsystems and registers hooks.
- `API\ERPClient`: ERP HTTP client and API request handling.
- `WooCommerce\Stock`: stock sync integration.
- `WooCommerce\Pricing`: pricing sync integration.
- `WooCommerce\ProductSync`: ERP product catalog sync.
- `WooCommerce\PromotionEngine`: checkout and cart promotion rules.

## Release

Update `victoria-erp-connector.php` version and `composer.json` version for future releases.
