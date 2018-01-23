<h2>Payment completed successfully</h2>
<ul class="order_details">
  <li>
    Payment completed at: <strong><?php echo date('r', $invoice->completed_at) ?></strong>
  </li>
  <li>
    Lightning rhash: <strong><?php echo $invoice->rhash ?></strong>
  </li>
  <li>
    Invoice amount: <strong><?php echo self::format_msat($invoice->msatoshi) ?></strong>
  </li>
  <li>
    Payment request: <strong><?php echo $invoice->payreq ?></strong>
  </li>
</ul>
