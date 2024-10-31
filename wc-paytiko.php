<?php
/**
 * Plugin Name: Paytiko
 * Plugin URI: https://paytiko.com/wc-ext/
 * Description: Easily accept payments on your Woocommerce store using this plugin
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

define('PAYTIKO_PLUGIN_FILE', trailingslashit(WP_PLUGIN_DIR) . 'wc-paytiko/' . basename(__FILE__));

if (in_array(
	PAYTIKO_PLUGIN_FILE,
	array_merge(wp_get_active_and_valid_plugins(),
		function_exists('wp_get_active_network_plugins') ? wp_get_active_network_plugins() : []
	)
)) {
	define('PAYTIKO_PLUGIN_DIR', plugin_dir_path(PAYTIKO_PLUGIN_FILE));
	define('PAYTIKO_PLUGIN_URL', plugin_dir_url(__FILE__));

	add_action('plugins_loaded', function () {
		require_once PAYTIKO_PLUGIN_DIR . 'includes/PaytikoGateway.php';
	});

	add_filter('woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'Paytiko_Gateway';
		return $gateways;
	});
}
