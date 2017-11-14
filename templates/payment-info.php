<h2>Payment details</h2>
<ul class="order_details">
  <li>
    Lightning peerid: <strong><?php echo $invoice->peerid ?></strong>
  </li>
  <li>
    Lightning rhash: <strong><?php echo $invoice->rhash ?></strong>
  </li>
  <li>
    BTC amount: <strong><?php echo $invoice->msatoshi ?> milli-satoshis (<?php echo round((float)$invoice->msatoshi / 100000000000, 5) ?> BTC)</strong>
  </li>
  <li>
    BOLT11 payment request: <strong><?php echo $invoice->payreq ?></strong>
  </li>
  <?php if (!$invoice->completed): ?>
  <li>
    Pay with c-lightning:
    <strong>lightning-cli sendpay `lightning-cli getroute <?php echo $invoice->peerid ?> <?php echo $invoice->msatoshi ?> 0 | jq -c .route` <?php echo $invoice->rhash ?></strong>
  </li>
  <?php else: ?>
    <li>Payment completed at: <strong><?php echo date('r', round($invoice->completed_at/1000)) ?></strong></li>
  <?php endif ?>
</ul>
