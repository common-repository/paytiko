<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('paytiko_write_log')) {
	function paytiko_write_log( $log ) {
		if (!WP_DEBUG) {
			return;
		}
		if (is_array($log) || is_object($log)) {
			error_log(print_r($log, true));
		} else {
			error_log($log);
		}
	}
}
