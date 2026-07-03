<?php
/**
 * Plugin Name: Victoria ERP Connector
 * Description: Connects WooCommerce with Victoria ERP.
 * Version: 0.1.0
 * Author: korir5
 * Text Domain: victoria-erp-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/Core/Loader.php';

$loader = new VEC_Loader();
$loader->run();
