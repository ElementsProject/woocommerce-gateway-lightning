<link rel="stylesheet" href="<?php echo plugins_url('css/payment.css', dirname(__FILE__))?>" type="text/css">

<div class="ln-pay">
  <h4>
    <strong>Pay <?php echo $order->get_formatted_order_total() ?> with Lightning</strong>
    (<?php echo number_format($invoice->msatoshi/100000000, 8) ?> mBTC)
  </h4>
  <img class="qr" src="<?php echo $qr_uri ?>">
  <div class="payreq">
    <code><?php echo $invoice->payreq ?></code>
    <a class="checkout-button button alt" href="lightning:<?php echo $invoice->payreq ?>">Pay with Lightning</a>
  </div>

</div>

<script>
(function($, ajax_url, invoice_id, redir_url){
  $(function poll() {
    $.post(ajax_url, { action: 'ln_wait_invoice', invoice_id: invoice_id })
      .success((code, state, res) => {
        if (res.responseJSON === true) return document.location = redir_url

        setTimeout(poll, 10000);
        throw new Error('succesful response, but not the expected one')
      })
      .fail(res => {
        // immediatly re-poll on 402 Payment Required
        if (res.status === 402) return poll()

        // for unknown errors, wait for a little while, then try again
        setTimeout(poll, 10000);
        throw new Error('unexpected status code '+res.status)
      })
  })
})(jQuery, <?php echo json_encode(admin_url( 'admin-ajax.php' )) ?>, <?php echo json_encode($invoice->id) ?>,
           <?php echo json_encode($order->get_checkout_order_received_url()) ?>)

</script>

