<?php
if (!defined('ABSPATH')) exit;

require_once 'common.php';
require_once 'paytiko-http-api.php';

const PLUGIN_VERSION        = '1.0';
const SANDBOX_BASE_URL      = 'https://qa-core.paytiko.com';
const PROD_BASE_URL         = 'https://core.paytiko.com';
const API_PATH              = '/api/cashier/ecommerce/';
const CASHIER_RENDERER_PATH = '/cdn/ec/scripts/paytiko-ecommerce-sdk.1.0.js';

class Paytiko_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id = 'paytiko_gateway';
		$this->icon = PAYTIKO_PLUGIN_URL . 'assets/icon.png';
		$this->method_title = _x('Paytiko Payments', 'woocommerce');
		$this->method_description = __('Accept payments via Paytiko payment gateway', 'woocommerce');
		$this->supports = ['subscriptions', 'products', 'refunds'];

		$this->has_fields = true;
		$this->form_fields = [
			'enabled' => [
				'title' => __('Enable/Disable', 'woocommerce'),
				'type' => 'checkbox',
				'label' => __('Enable Paytiko Payments', 'woocommerce'),
				'default' => 'yes'
			],
			'title' => [
				'title' => __('Title', 'woocommerce'),
				'type' => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
				'default' => __('Online payment', 'woocommerce'),
				'desc_tip' => true
			],
			'description' => [
				'title' => __('Description', 'woocommerce'),
				'type' => 'textarea',
				'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce'),
				'default' => __('Your payment will be processed via Paytiko gateway', 'woocommerce'),
				'desc_tip' => true
			],
            'payment_mode' => [
                'title' => __('Payment mode', 'woocommerce'),
                'type' => 'select',
                'options' => [
                    'cart' => 'Using cart',
                    'direct' => 'Direct order'
                ],
                'description' => __('Use `direct order` only if your template supports paying by clicking directly on the product', 'woocommerce'),
                'default' => 'cart'
            ],
			'activation_key' => [
				'title' => __('Activation key', 'woocommerce'),
				'type' => 'text',
				'default' => '',
				'description' => __('Activation key from Paytiko', 'woocommerce')
			],
			'api_key' => [
				'title' => __('Your Paytiko API key', 'woocommerce'),
				'type' => 'text',
				'description' => __('Get this from your Paytiko dashboard', 'woocommerce'),
				'default' => '',
				'desc_tip' => true
			],
			'environment' => [
				'title' => __('Environment', 'woocommerce'),
				'type' => 'select',
				'options' => [
					'Production' => 'Production',
					'Sandbox' => 'Sandbox'
				],
				'default' => 'Sandbox'
			]
		];

		//////////

		$this->init_settings();

		$this->title = $this->get_option('title');
		$this->description = $this->get_option('description');

		$this->gateway_version = PLUGIN_VERSION;

        $this->initApi();

		add_action('admin_enqueue_scripts', function ( $hook_suffix ) {
			wp_enqueue_script('admin-stuff', PAYTIKO_PLUGIN_URL . 'assets/admin_stuff.js', ['jquery'], PLUGIN_VERSION);
		});

		/////////////////

		add_action("woocommerce_update_options_payment_gateways_{$this->id}", [$this, 'process_admin_options']);
		add_action("woocommerce_api_{$this->id}", [$this, 'callback_success']);

		add_action('woocommerce_after_checkout_form', [$this, 'embed_checkout']);
		add_action('woocommerce_pay_order_after_submit', [$this, 'embed_checkout']);

		add_action('woocommerce_order_status_cancelled', [$this, 'cancel_payment']);
		add_action('woocommerce_order_status_refunded', [$this, 'cancel_payment']);

		add_action('woocommerce_before_checkout_form', function () {
			if (isset($_GET['paytiko_payment']) && 'canceled'===$_GET['paytiko_payment']) {
				wc_print_notice(__('Payment has been declined or canceled', 'woocommerce'), 'error');
			}
		}, 10);
		add_action('woocommerce_thankyou', [$this, 'thankyou_handler'], 11, 1);

		//add_action('wp_footer', [$this, 'wp_footer']);
	}

    private function initApi() {
        $this->baseUrl = @strtolower($this->get_option('environment'))==='production' ? PROD_BASE_URL : SANDBOX_BASE_URL;
        $this->api = new Paytiko_API($this->baseUrl . API_PATH, $this->get_option('api_key'));
    }

	public function process_admin_options() {
		$WP_settings = new WC_Admin_Settings();
		try {
			$postData = $this->get_post_data();
            $this->update_option('environment', $postData["woocommerce_{$this->id}_environment"]);
            $this->initApi();
            $resp = $this->api->activatePlugin(
				$postData["woocommerce_{$this->id}_activation_key"],
				$postData["woocommerce_{$this->id}_api_key"]
			);
			$this->update_option('cashier_base_url', $resp->cashierBaseUrl);
			$this->update_option('core_base_url', $resp->coreBaseUrl);

			$WP_settings->add_message('Paytiko plugin has been activated successfully!');
		} catch (Exception $e) {
			$WP_settings->add_message("Paytiko plugin activation failed: {$e->getMessage()}");
			return false;
		}
		return parent::process_admin_options();
	}

	public function process_payment( $order_id ) {
		$order    = wc_get_order($order_id);

        $billAddr = $order->get_address('billing');
        $shipAddr = $order->get_address('shipping');
        $ccy      = $order->get_currency();

        $amountTotal = number_format($order->get_total() * 100, 0, '', '');
        $orderItems = [];
        $itemsTotal = 0;

        foreach ($order->get_items() as $itemId => $item) {
            $prod = $item->get_product();
            $itemsTotal += $prod->get_price() * $item->get_quantity();
            $orderItems[] = ['name' => $item->get_name(), 'quantity' => $item->get_quantity(),
                'unit_amount' => [
                    'currency_code' => $ccy,
                    'value' => number_format($prod->get_price() * 100, 0, '', '')
                ]
            ];
        }

		$data = [
			'amount' => $amountTotal,
			'currency' => $ccy,
			'orderId' => $order->get_id(),
			'successRedirectUrl' => $order->get_checkout_order_received_url(), //wc_get_checkout_url(),
			'failedRedirectUrl' => add_query_arg('paytiko_payment', 'canceled', wc_get_checkout_url()),
			'webhookUrl' => add_query_arg('wc-api', $this->id, trailingslashit(get_site_url())),    //...?wc-api=paytiko_gateway';
			'billingDetails' => [
				'uniqueIdentifier' => $order->get_customer_id()!='0' ? 'WC-'.$order->get_customer_id() : uniqid('WC-G-', true),
				'firstName' => isset($billAddr['first_name']) ? $billAddr['first_name'] : '',
				'lastName'  => isset($billAddr['last_name']) ? $billAddr['last_name'] : '',
				'email'     => isset($billAddr['email']) ? $billAddr['email'] : '',
				'street'    => isset($billAddr['address_1']) ? $billAddr['address_1'] : '',
				'region'    => isset($billAddr['region']) ? $billAddr['region'] : '',
				'city'      => isset($billAddr['city']) ? $billAddr['city'] : '',
				'phone'     => isset($billAddr['phone']) ? $billAddr['phone'] : '',
				'zipCode'   => isset($billAddr['postcode']) ? $billAddr['postcode'] : '',
				'country'   => isset($billAddr['country']) ? $billAddr['country'] : '',
				'dateOfBirth' => ''
			],
            'orderDetails' => [
                'shipping_address' => [
                    'address_line_1' => (isset($shipAddr['address_1']) ? $shipAddr['address_1'] : ''),
                    'address_line_2' => (isset($shipAddr['address_2']) ? $shipAddr['address_2'] : ''),
                    'admin_area_1'   => (isset($shipAddr['region']) ? $shipAddr['region'] : ''),
                    'admin_area_2'   => (isset($shipAddr['city']) ? $shipAddr['city'] : ''),
                    'postal_code'    => (isset($shipAddr['postcode']) ? $shipAddr['postcode'] : ''),
                    'country_code'   => (isset($shipAddr['country']) ? $shipAddr['country'] : '')
                ],
                'amount' => [
                    'currency_code' => $ccy,
                    'value' => $amountTotal,
                    'breakdown' => [
                        'item_total' => [
                            'currency_code' => $ccy,
                            'value' => number_format($itemsTotal * 100, 0, '', '')
                        ],
                        'tax_total' => [
                            'currency_code' => $ccy,
                            'value' => number_format($order->get_total_tax() * 100, 0, '', '')
                        ],
                        'shipping' => [
                            'currency_code' => $ccy,
                            'value' => number_format($order->get_shipping_total() * 100, 0, '', '')
                        ],
                        'handling' => [
                            'currency_code' => $ccy,
                            'value' => number_format($order->get_total_fees() * 100, 0, '', '')
                        ],
                        'discount' => [
                            'currency_code' => $ccy,
                            'value' => number_format($order->get_discount_total() * 100, 0, '', '')
                        ]
                    ]
                ],
                'items' => $orderItems
            ]
		];

		$sess = WC()->session;
		$currOrder = $sess->get('paytiko_current_order');
		if ($currOrder && (
				count(array_diff_assoc($billAddr, $currOrder['billingDetails'])) ||
				$currOrder['amount'] !== $data['amount'] ||
				$currOrder['orderId'] !== $data['orderId']
			)) {
			// order details changed
			$sess->set('paytiko_session_token', '');
		}
		$sess->set('paytiko_current_order', $data);

		$errMsg = null;
		if (!$sess->get('paytiko_session_token')) {
			try {
				$resp = $this->api->checkout($data);
				$sess->set('paytiko_session_token', $resp->cashierSessionToken);
				$order->update_meta_data('paytiko_session_token', $resp->cashierSessionToken);
				$order->update_status('pending');
			} catch (Exception $e) {
				$errMsg = $e->getMessage();
			}
		}

		if (!$errMsg) {
            $url = add_query_arg(
                'embed_paytiko', md5((string) rand()),
                $this->get_option('payment_mode')==='direct' ? $order->get_checkout_payment_url() : wc_get_checkout_url()
            );
			return ['result' => 'success', 'reload' => true, 'redirect' => $url];
		}
		wc_add_notice(__('Payment error: ', 'woocommerce') . $errMsg, 'error');
	}

	public function embed_checkout() {
		if (!is_checkout()) {
			return;
		}

		if (!@isset($_GET['embed_paytiko'])) {
			// user may have changed the cart (amount changed)
			WC()->session->set('paytiko_session_token', '');
			return;
		}

		$token = WC()->session->get('paytiko_session_token');
		if (!$token) {
			return;
		}

		wp_enqueue_script('embed_paytiko_renderer', $this->baseUrl . CASHIER_RENDERER_PATH, ['jquery'], PLUGIN_VERSION);

		wp_register_style('embed_paytiko_renderer_style', false, [], PLUGIN_VERSION);
		wp_enqueue_style('embed_paytiko_renderer_style');
		wp_add_inline_style('embed_paytiko_renderer_style', '.paytiko-cashier { width:100%; height:100%; border:none }');

		wp_add_inline_script(
			'embed_paytiko_renderer',
			"var paytikoCashierBaseUrl='{$this->get_option('cashier_base_url')}'; paytikoSessionToken='{$token}';",
			'before'
		);
		wp_enqueue_script('embed_paytiko_frame_logic', PAYTIKO_PLUGIN_URL . 'assets/paytiko.js', ['jquery'], PLUGIN_VERSION);

        if ($this->get_option('payment_mode')==='direct') {
            wp_add_inline_script(
                'embed_paytiko_frame_logic',
                'document.body.dispatchEvent(new Event("updated_checkout"));',
                'after'
            );
        }
	}

	public function wp_footer() {
//		if (!is_wc_endpoint_url('order-received') || empty($_GET['orderId'])) {
//			return;
//		}
//		$this->woocommerce_thankyou($_GET['orderId']);
	}

	public function cancel_payment( $orderId ) {
		$order = wc_get_order($orderId);
		if (!empty($order) && $order->get_payment_method() === $this->id) {
			$this->process_refund(
				$orderId,
				(float) $order->get_total() - (float) $order->get_total_refunded(),
				['full' => __('Full Refund', 'woocommerce'), 'partial' => __('Partial Refund', 'woocommerce')]
			);
		}
	}

	public function process_refund( $orderId, $amount = null, $reason = '' ) {
		throw new \Exception('Refunds are currently not supported');
	}

	private function setOrderStatusFromAPI( $order, $status ) {
		$st = @['success' => 'completed', 'rejected' => 'failed', 'failed' => 'failed'][strtolower($status)];
		if (!$st) {
			$st = 'pending';
		}
		$order->update_status($st);
		$order->save();
		return $st;
	}

	public function callback_success() {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once (ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        $data = $wp_filesystem->get_contents('php://input');
		$data = json_decode($data, true);

		if (!is_array($data)) {
			return;
		}
		$order = wc_get_order($data['OrderId']);
		if (empty($order) || $data['Signature'] !== $this->api->getSignature($data['OrderId'])) {
			return;
		}
		$st = $order->get_status();
		if ('completed'!==$st && 'failed'!==$st) {
			if ($this->setOrderStatusFromAPI($order, $data['TransactionStatus']) === 'completed') {
				$order->payment_complete();
			}
		}
	}

	public function thankyou_handler( $orderId ) {
		if (!$orderId) {
			return;
		}
		$order = wc_get_order($orderId);
		if (empty($order)) {
			return;
		}

		$st = $order->get_status();
		if ('completed'!==$st && 'failed'!==$st) {
			$statusData = $this->api->getOrderStatus($orderId);
			$st = $this->setOrderStatusFromAPI($order, $statusData->statusDescription);
		}

		if ('completed'===$st || 'failed'===$st) {
			WC()->session->set('paytiko_session_token', '');
			WC()->session->set('paytiko_current_order', '');
			$order->update_meta_data('paytiko_session_token', '');

			if ('completed'===$st) {
				global $woocommerce;
				$woocommerce->cart->empty_cart();
				$order->payment_complete();
			}
		}
	}
}
