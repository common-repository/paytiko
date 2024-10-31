<?php
if (!defined('ABSPATH')) exit;

require_once('common.php');

const REQUEST_TIMEOUT = 10;

class Paytiko_API {
	public function __construct( $url, $apiKey = null ) {
		$this->baseUrl = trailingslashit($url);
		$this->apiKey = $apiKey;
	}

	private function APIReq( $path, $method, $data, $apiKey = null ) {
		return $this->send("{$this->baseUrl}{$path}", $method, $data, $apiKey ? $apiKey : $this->apiKey);
	}

	private function send( $url, $method, $data = '', $apiKey = null ) {
		$args = [
			'method' => strtoupper($method),
			'body' => '',
			'headers' => [],
			'timeout' => REQUEST_TIMEOUT
		];
		if (!empty($apiKey)) {
			$args['headers']['Api-Key'] = $apiKey;
		}
		if (!empty($data)) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = is_string($data) ? $data : json_encode($data);
		}
		$res = wp_remote_request($url, $args);

		if (is_wp_error($res)) {
			throw new Exception("Paytiko server returned an error: {$res->get_error_message()}");
		}
		if (false === $res) {
			throw new Exception('Having trouble communicating with Paytiko servers');
		}

		$body = $res['body'];
		if (200 === $res['response']['code']) {
			return empty($body) ? [] : json_decode($body);
		}

		if (!empty($body)) {
			throw new Exception($body);
		}
		throw new Exception($res['response']['message']);
	}

	////////////////

	public function activatePlugin( $activationKey, $apiKey ) {
		return $this->APIReq("config/{$activationKey}", 'get', '', $apiKey);
	}

	public function checkout( $data ) {
		return $this->APIReq('checkout', 'post', $data);
	}

	public function getOrderStatus( $orderId ) {
		return $this->APIReq("orderStatus/{$orderId}", 'get', '');
	}

	public function getSignature( $orderId ) {
		return hash('sha256', "{$this->apiKey}:{$orderId}");
	}
}

