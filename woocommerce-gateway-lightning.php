<?php
/*
    Plugin Name: WooCommerce Lightning Gateway
    Plugin URI:  https://github.com/ElementsProject/woocommerce-gateway-lightning
    Description: Enable your WooCommerce store to accept Bitcoin Lightning payments.
    Author:      Blockstream
    Author URI:  https://blockstream.com

    Version:           0.1.0
    GitHub Plugin URI: https://github.com/ElementsProject/woocommerce-gateway-lightning
*/

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

//require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__.'/lightning-strike-client-php/client.php';

define('LIGHTNING_HOOK_KEY', hash_hmac('sha256', 'lightning-hook-token', AUTH_KEY));
define('LIGHTNING_LONGPOLL_TIMEOUT', min(120, max(5, ini_get('max_execution_time') * 0.8)));

if (!function_exists('init_wc_lightning')) {

  function init_wc_lightning() {
    class WC_Gateway_Lightning extends WC_Payment_Gateway {

      public function register_gateway($methods) {
        $methods[] = $this;
        return $methods;
      }

      protected static function make_token($order_id) {
        return hash_hmac('sha256', $order_id, LIGHTNING_HOOK_KEY);
      }

      protected static function verify_token($order_id, $token) {
        return self::make_token($order_id) === $token;
      }

      protected static function get_webhook_url($order_id) {
        return add_query_arg(array('order' => $order_id, 'token' => self::make_token($order_id)),
          WC()::api_request_url('WC_Gateway_Lightning'));
      }

      protected static function get_msat($order) {
        // @XXX temp hack with fixed exchange rate, should eventually be done on lightning-strike-rest's side
        return number_format($order->get_total() / 6500 * 100000000 * 1000, 0, '', '');
      }

      public function __construct() {
        $this->id                 = 'lightning';
        $this->order_button_text  = __('Proceed to Lightning Payment', 'woocommerce');
        $this->method_title       = __('Lightning', 'woocommerce');
        $this->method_description = __('Lightning Network Payment');
        //$this->icon               = plugin_dir_url(__FILE__).'assets/img/icon.png';
        $this->supports           = array();

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables.
        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');

        // Lightning Strike REST client
        $this->strike = new LightningStrikeClient($this->get_option('server_url', 'http://localhost:8009'));

        add_action('woocommerce_api_wc_gateway_lightning', array($this, 'webhook_callback'));
        add_action('woocommerce_receipt_lightning', array($this, 'show_payment_info'));
        add_action('woocommerce_thankyou_lightning', array($this, 'show_payment_info'));
        add_action('wp_ajax_ln_wait_invoice', array($this, 'wait_invoice'));
        add_action('wp_ajax_nopriv_ln_wait_invoice', array($this, 'wait_invoice'));
      }

      /**
       * Initialise Gateway Settings Form Fields.
       */
      public function init_form_fields() {
        $this->form_fields = array(
          'enabled' => array(
            'title'       => __( 'Enable/Disable', 'woocommerce-gateway-lightning' ),
            'label'       => __( 'Enable Lightning payments', 'woocommerce-gateway-lightning' ),
            'type'        => 'checkbox',
            'description' => '',
            'default'     => 'no',
          ),
          'title' => array(
            'title'       => __('Title', 'lightning'),
            'type'        => 'text',
            'description' => __('Controls the name of this payment method as displayed to the customer during checkout.', 'lightning'),
            'default'     => __('Bitcoin Lightning', 'lightning'),
            'desc_tip'    => true,
           ),
          'server_url' => array (
            'title'       => __('Lightning Strike server', 'lightning'),
            'type'        => 'text',
            'description' => __('URL of the Lightning Strike REST server to connect to.', 'lightning'),
            'default'     => __('http://localhost:8009', 'lightning'),
            'desc_tip'    => true,
          ),
          'description' => array(
            'title'       => __('Customer Message', 'lightning'),
            'type'        => 'textarea',
            'description' => __('Message to explain how the customer will be paying for the purchase.', 'lightning'),
            'default'     => 'You will pay using the Lightning Network.',
            'desc_tip'    => true,
          ),
        );
      }

      /**
       * Process the payment and return the result.
       * @param  int $order_id
       * @return array
       */
      public function process_payment( $order_id ) {
        $order = wc_get_order($order_id);
        $invoice = $order->get_meta('_lightning_invoice');

        if (!$invoice) {
          $msatoshi = self::get_msat($order);
          $invoice = $this->strike->invoice($msatoshi, [ 'order_id' => $order_id ]);
          $this->strike->registerHook($invoice->id, self::get_webhook_url($order->get_id()));
          $order->update_meta_data('_lightning_invoice', $invoice);
          $order->save_meta_data();
        }

        return array(
          'result'   => 'success',
          'redirect' => $order->get_checkout_payment_url(true)
        );
      }

      public function webhook_callback() {
        if (!self::verify_token($_GET['order'], $_GET['token'])) wp_die('invalid token');

        $order = wc_get_order($_GET['order']);
        $post = file_get_contents("php://input");
        $invoice = json_decode($post);

        $order->add_order_note( __('Lightning payment completed', 'lightning') );
        $order->update_meta_data('_lightning_invoice', $invoice);
        $order->save_meta_data();
        $order->payment_complete();
      }

      public function wait_invoice() {
        if ($this->strike->wait($_POST['invoice_id'], LIGHTNING_LONGPOLL_TIMEOUT)) {
          wp_send_json(true);
        } else {
          status_header(402);
          wp_send_json(false);
        }
      }

      public function show_payment_info($order_id) {
        global $wp;

        $order = wc_get_order($order_id);
        $invoice = $order->get_meta('_lightning_invoice');

        if (!empty($wp->query_vars['order-received']) && $order->needs_payment()) {
          wp_redirect($order->get_checkout_payment_url(true));
          exit;
        }

        require __DIR__.'/templates/payment-info.php';
      }
    }

    $gateway = new WC_Gateway_Lightning();
    add_action('woocommerce_payment_gateways', array($gateway, 'register_gateway'));
  }

  add_action('plugins_loaded', 'init_wc_lightning');
}
