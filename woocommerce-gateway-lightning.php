<?php
/*
    Plugin Name: WooCommerce Lightning Gateway
    Plugin URI:  https://github.com/ElementsProject/woocommerce-gateway-lightning
    Description: Enable your WooCommerce store to accept Bitcoin Lightning payments.
    Author:      Blockstream
    Author URI:  https://blockstream.com

    Version:           0.1.5
    GitHub Plugin URI: https://github.com/ElementsProject/woocommerce-gateway-lightning
*/

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

require_once 'vendor/autoload.php';

define('LIGHTNING_HOOK_KEY', hash_hmac('sha256', 'lightning-hook-token', AUTH_KEY));
define('LIGHTNING_LONGPOLL_TIMEOUT', min(120, max(5, ini_get('max_execution_time') * 0.8)));

if (!function_exists('init_wc_lightning')) {

  function init_wc_lightning() {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Gateway_Lightning extends WC_Payment_Gateway {

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

        // Lightning Charge REST client
        $this->charge = new LightningChargeClient($this->get_option('charge_url', 'http://localhost:9112'), $this->get_option('charge_token'));

        add_action('woocommerce_payment_gateways', array($this, 'register_gateway'));
        add_action('woocommerce_update_options_payment_gateways_lightning', array($this, 'process_admin_options'));
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
          'charge_url' => array (
            'title'       => __('Lightning Charge server', 'lightning'),
            'type'        => 'text',
            'description' => __('URL of the Lightning Charge REST server to connect to.', 'lightning'),
            'default'     => __('http://localhost:9112', 'lightning'),
            'desc_tip'    => true,
          ),
          'charge_token' => array (
            'title'       => __('Lightning Charge API token', 'lightning'),
            'type'        => 'text',
            'description' => __('API access token configured in the Lightning Charge server.', 'lightning'),
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
          $invoice = $this->charge->invoice([
            'currency'    => $order->get_currency(),
            'amount'      => $order->get_total(),
            'description' => self::make_desc($order),
            'metadata'    => [ 'order_id' => $order->get_id() ],
            'webhook'     => self::get_webhook_url($order->get_id())
          ]);
          $this->update_invoice($order, $invoice);

          $order->add_order_note(sprintf(__('Lightning Charge invoice created, id=%s, rhash=%s.', 'lightning'), $invoice->id, $invoice->rhash));
        }

        return array(
          'result'   => 'success',
          'redirect' => $order->get_checkout_payment_url(true)
        );
      }

      /**
       * Process webhook callbacks.
       */
      public function webhook_callback() {
        if (!self::verify_token($_GET['order'], $_GET['token'])) wp_die('invalid token');

        $order = wc_get_order($_GET['order']);
        $invoice = json_decode(file_get_contents("php://input"));

        if (!$invoice) {
          status_header(400);
          wp_die('cannot decode request body invoice');
        }

        $order->add_order_note(__('Lightning webhook notification received.', 'lightning'));
        $this->update_invoice($order, $invoice);
      }

      /**
       * JSON endpoint for long polling payment updates.
       */
      public function wait_invoice() {
        $paid_inv = $this->charge->wait($_POST['invoice_id'], LIGHTNING_LONGPOLL_TIMEOUT);

        if ($paid_inv) {
          $order = wc_get_order($paid_inv->metadata->order_id);
          $this->update_invoice($order, $paid_inv);
          wp_send_json(true);
        } else {
          status_header($paid_inv === false ? 410 : 402);
          wp_send_json(false);
        }
      }

      /**
       * Hooks into the checkout page to display Lightning-related payment info.
       */
      public function show_payment_info($order_id) {
        global $wp;

        $order = wc_get_order($order_id);

        if (!empty($wp->query_vars['order-received']) && $order->needs_payment()) {
          // thankyou page requested, but order is still unpaid
          wp_redirect($order->get_checkout_payment_url(true));
          exit;
        }

        $invoice = $order->get_meta('_lightning_invoice');
        if ($invoice->status == 'unpaid') {
          $invoice = $this->charge->fetch($invoice->id);
          $this->update_invoice($order, $invoice);
        }

        if ($order->has_status('cancelled')) {
          // invoice expired, reload page to display expiry message
          wp_redirect($order->get_checkout_payment_url(true));
          exit;
        }

        if ($order->needs_payment()) {
          $qr_uri = self::get_qr_uri($invoice);
          require __DIR__.'/templates/payment.php';
        } elseif ($order->has_status(array('processing', 'completed'))) {
          require __DIR__.'/templates/completed.php';
        }
      }

      /**
       * Register as a WooCommerce gateway.
       */
      public function register_gateway($methods) {
        $methods[] = $this;
        return $methods;
      }

      protected function update_invoice($order, $invoice) {
        $order->update_meta_data('_lightning_invoice', $invoice);
        $order->save_meta_data();

        if ($invoice->status == 'paid' && $order->needs_payment()) {
          $order->payment_complete($invoice->id);
        } else if ($invoice->status == 'expired' && $order->has_status(array('pending', 'on-hold'))) {
          $order->update_status('cancelled', 'Lightning invoice expired');
        }
      }

      protected static function make_token($order_id) {
        return hash_hmac('sha256', $order_id, LIGHTNING_HOOK_KEY);
      }

      protected static function verify_token($order_id, $token) {
        return self::make_token($order_id) === $token;
      }

      protected static function get_webhook_url($order_id) {
        return add_query_arg(array('order' => $order_id, 'token' => self::make_token($order_id)),
          WC()->api_request_url('WC_Gateway_Lightning'));
      }

      protected static function get_qr_uri($invoice) {
        $renderer = new \BaconQrCode\Renderer\Image\Png;
        $renderer->setWidth(180);
        $renderer->setHeight(180);
        $renderer->setMargin(0);
        $writer = new \BaconQrCode\Writer($renderer);
        $image = $writer->writeString(strtoupper('lightning:' . $invoice->payreq));
        return 'data:image/png;base64,' . base64_encode($image);
      }

      protected static function make_desc($order) {
        $total = $order->get_total() . ' ' .$order->get_currency();
        $desc = get_bloginfo('name') . ': ' . $total . ' for ';
        $products = $order->get_items();
        while (strlen($desc) < 100 && count($products)) {
          $product = array_shift($products);
          if (count($products)) $desc .= $product['name'] . ' x ' . $product['qty'] . ', ';
          else $desc = substr($desc, 0, -2) . ' and ' . $product['name'].' x '.$product['qty'];
        }
        if (count($products)) $desc = substr($desc, 0, -2) . ' and ' . count($products) . ' more items';
        return str_replace('"', '', $desc); // c-lightning's json parser doesn't like these, should eventually be fixed
      }

      protected static function format_msat($msat) {
        return rtrim(rtrim(number_format($msat/100000000, 8), '0'), '.') . ' mBTC';
      }
    }

    new WC_Gateway_Lightning();
  }

  add_action('plugins_loaded', 'init_wc_lightning');
}
