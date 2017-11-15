<h2>Payment details</h2>
<ul class="order_details">
  <?php if ($invoice->completed): ?>
    <li>Payment completed at: <strong><?php echo date('r', round($invoice->completed_at/1000)) ?></strong></li>
  <?php endif ?>
  <li>
    Lightning peerid: <strong><?php echo $invoice->peerid ?></strong>
  </li>
  <li>
    Lightning rhash: <strong><?php echo $invoice->rhash ?></strong>
  </li>
  <li>
    BTC amount: <strong><?php echo $invoice->msatoshi ?> milli-satoshis (<?php echo number_format($invoice->msatoshi / 100000000000, 8) ?> BTC)</strong>
  </li>
  <li>
    BOLT11 payment request: <strong><?php echo $invoice->payreq ?></strong>
  </li>
  <?php if (!$invoice->completed): ?>
  <li>
    Pay with c-lightning:
    <strong>lightning-cli sendpay `lightning-cli getroute <?php echo $invoice->peerid ?> <?php echo $invoice->msatoshi ?> 0 | jq -c .route` <?php echo $invoice->rhash ?></strong>
  </li>
  <?php endif ?>
</ul>

<?php if (!isset($_GET['order-received']) && !$invoice->completed): ?>
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
<?php endif ?>
