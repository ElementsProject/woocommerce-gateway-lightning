<link rel="stylesheet" href="<?php echo plugins_url('css/payment.css', dirname(__FILE__))?>" type="text/css">
<noscript><style>.yesscript{display:none}</style></noscript>

<?php $expiry_datestr = date('r', $invoice->expires_at); ?>

<div class="ln-pay">
  <h1>Pay with Lightning</h1>
  <h3>
    <?php if ($order->get_currency() !== 'BTC'): ?> <?php echo $order->get_total() ?> <?php echo $order->get_currency() ?> = <?php endif ?>
    <?php echo rtrim(number_format($invoice->msatoshi/100000000, 8), "0.") ?> mBTC
  </h3>
  <img class="qr" src="<?php echo $qr_uri ?>">
  <code class="payreq"><?php echo $invoice->payreq ?></code>
  <p>
    <noscript>Your browser has JavaScript turned off. Please refresh the page manually after making the payment.</noscript>
    <span class="yesscript"><img src="<?php echo plugins_url( '../img/loader.gif', __FILE__ ) ?>" class="loader" alt="loading"> Awaiting payment.</span>
    The invoice expires <span id="expiry-timer" title="<?php echo $expiry_datestr ?>"><?php echo $expiry_datestr ?></span>.
  </p>
  <a class="checkout-button button alt" href="lightning:<?php echo $invoice->payreq ?>">Pay with Lightning</a>
</div>

<script>
(function($, ajax_url, invoice_id, redir_url, expires_at){
  $(function poll() {
    $.post(ajax_url, { action: 'ln_wait_invoice', invoice_id: invoice_id })
      .success((code, state, res) => {
        if (res.responseJSON === true) return document.location = redir_url

        setTimeout(poll, 10000);
        throw new Error('succesful response, but not the expected one')
      })
      .fail(res => {
        // 402 Payment Required: timeout reached without payment, invoice is still payable
        if (res.status === 402) return poll()
        // 410 Gone: invoice expired and can not longer be paid
        if (res.status === 410) return location.reload()

        // for unknown errors, wait for a little while, then try again
        setTimeout(poll, 10000);
        throw new Error('unexpected status code '+res.status)
      })
  })

  ;(function updateExpiry() {
    var left = expires_at - (+new Date()/1000|0)
    if (left <= 0) return location.reload()
    $('#expiry-timer').text('in '+formatDur(left))
    setTimeout(updateExpiry, 1000)
  })()

  function formatDur(x) {
    var h=x/3600|0, m=x%3600/60|0, s=x%60
    return ''+(h>0?h+':':'')+(m<10&&h>0?'0':'')+m+':'+(s<10?'0':'')+s
  }
})(jQuery, <?php echo json_encode(admin_url( 'admin-ajax.php' )) ?>, <?php echo json_encode($invoice->id) ?>,
           <?php echo json_encode($order->get_checkout_order_received_url()) ?>, <?php echo $invoice->expires_at ?>)

</script>

