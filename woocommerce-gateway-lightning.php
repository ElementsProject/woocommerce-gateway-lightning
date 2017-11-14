<?php
/*
    Plugin Name: Lightning for WooCommerce
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

if (!function_exists('init_wc_lightning')) {

function init_wc_lightning() {
  class WC_Gateway_Lightning extends WC_Payment_Gateway {

    /** @var bool Whether or not logging is enabled */
    public static $log_enabled = false;

    /** @var WC_Logger Logger instance */
    public static $log = false;

    public function __construct() {
      $this->id                 = 'lightning';
      $this->enabled            = 'yes';
      $this->has_fields         = true;
      $this->order_button_text  = __( 'Proceed to Lightning Payment', 'woocommerce' );
      $this->method_title       = __( 'Lightning', 'woocommerce' );
      $this->method_description =  __('Lightning Network payment');
      //$this->icon               = plugin_dir_url(__FILE__).'assets/img/icon.png';
      $this->supports           = array();

      // Load the settings.
      $this->init_form_fields();
      $this->init_settings();

      // Define user set variables.
      $this->title          = $this->get_option( 'title' );
      $this->description    = $this->get_option( 'description' );
      $this->testmode       = 'yes' === $this->get_option( 'testmode', 'no' );
      $this->debug          = 'yes' === $this->get_option( 'debug', 'no' );
      $this->strike         = new LightningStrikeClient(get_option('woocommerce_lightning_url', 'http://localhost:8009'));

      self::$log_enabled    = $this->debug;

      if ( $this->testmode ) {
        $this->description .= ' ' . sprintf( __( 'SANDBOX ENABLED. You can use sandbox testing accounts only. See the <a href="%s">PayPal Sandbox Testing Guide</a> for more details.', 'woocommerce' ), 'https://developer.paypal.com/docs/classic/lifecycle/ug_sandbox/' );
        $this->description  = trim( $this->description );
      }

      add_action( 'woocommerce_api_wc_gateway_lightning', array($this, 'ipn_callback') );
      add_action( 'woocommerce_receipt_lightning', array($this, 'show_payment_info') );
      add_action( 'woocommerce_thankyou_lightning', array($this, 'show_payment_info') );

      $this->is_initialized = true;
    }

    /**
     * Logging method.
     *
     * @param string $message Log message.
     * @param string $level   Optional. Default 'info'.
     *     emergency|alert|critical|error|warning|notice|info|debug
     */
    public static function log( $message, $level = 'info' ) {
      if ( self::$log_enabled ) {
        if ( empty( self::$log ) ) {
          self::$log = wc_get_logger();
        }
        self::$log->log( $level, $message, array( 'source' => 'paypal' ) );
      }
    }

    protected static function make_token($order_id) {
      return hash_hmac('sha256', $order_id, LIGHTNING_HOOK_KEY);
    }
    protected static function verify_token($order_id, $token) {
      return self::make_token($order_id) === $token;
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields() {
      $this->form_fields = array(
        'enabled' => array(
          'title'       => __( 'Enable/Disable', 'woocommerce-gateway-lightning' ),
          'label'       => __( 'Enable Lightning', 'woocommerce-gateway-lightning' ),
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
        $msatoshi = round($order->get_total() / 6500 * 100000000 * 1000);
        $invoice = $this->strike->invoice($msatoshi, [ 'order_id' => $order_id ]);
        $this->strike->registerHook($invoice->id, $this->get_webhook_url($order));
        $order->update_meta_data('_lightning_invoice', $invoice);
        $order->save_meta_data();
      }

      return array(
        'result'   => 'success',
        'redirect' => $order->get_checkout_payment_url(true)
      );
    }

    public function get_webhook_url($order) {
      return add_query_arg(array('order' => $order->get_id(), 'token' => self::make_token($order->get_id())),
        WC()::api_request_url('WC_Gateway_Lightning'));
    }

    public function payment_fields() {

    }

    public function ipn_callback() {
      if (!self::verify_token($_GET['order'], $_GET['token'])) wp_die('invalid token');

      $order = wc_get_order($_GET['order']);
      $post = file_get_contents("php://input");
      $invoice = json_decode($post);

      $order->add_order_note( __('Lightning payment completed', 'lightning') );
      $order->update_meta_data('_lightning_invoice', $invoice);
      $order->save_meta_data();
      $order->payment_complete();
    }

    public function show_payment_info($order_id) {
      $order = wc_get_order($order_id);
      $invoice = $order->get_meta('_lightning_invoice');

      require __DIR__.'/templates/payment-info.php';
    }

  }
}

function register_wc_lightning($methods) {
  $methods[] = 'WC_Gateway_Lightning';
  return $methods;
}

add_action('plugins_loaded', 'init_wc_lightning');
add_action('woocommerce_payment_gateways', 'register_wc_lightning');

}
