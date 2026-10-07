<p class="crumbs"><a href="/ui/receiving">Receive + invoice</a><?php if ($canBench): ?> <a href="<?= $u('/ui/receiving/' . $doc->id . '/bench') ?>">Bench check of this delivery</a><?php endif; ?></p>
<h1><?= $e($doc->label()) ?> <span class="muted"><?= $e($supplier['code'] ?? '') ?> <?= $e($supplier['name'] ?? '') ?></span> <span class="status"><?= $e($statusText) ?></span></h1>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($problems !== []): ?>
<ul class="error problems">
<?php foreach ($problems as $p): ?>
  <li><?= $e($p) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($reversal !== null): ?>
<p class="note">Reversed by <a href="<?= $u('/ui/documents/' . $reversal->id) ?>"><?= $e($reversal->label()) ?></a>: its stock, its order receipts and its incidents were taken back.</p>
<?php endif; ?>
<?php if ($doc->reviewState === 'rejected'): ?>
<p class="error">Rejected at review.</p>
<?php endif; ?>

<dl class="wide">
  <dt>Supplier invoice</dt><dd><?= $e($doc->externalRef) ?><?php if (($gr['invoice_date'] ?? null) !== null): ?> of <?= $e($gr['invoice_date']) ?><?php endif; ?></dd>
<?php if (($gr['delivery_note'] ?? null) !== null): ?>
  <dt>Delivery note</dt><dd><?= $e($gr['delivery_note']) ?></dd>
<?php endif; ?>
  <dt>Purchase order</dt><dd><?php if ($po !== null): ?><a href="<?= $u('/ui/purchasing/orders/' . $po['id']) ?>"><?= $e($po['number']) ?></a> (<?= $e(str_replace('_', ' ', $po['state'])) ?>)<?php else: ?>none<?php endif; ?></dd>
  <dt>Received at (UK)</dt><dd><?= $e(str_replace('T', ' ', $receivedLocal)) ?><?php if ((int) ($gr['paper_sheet'] ?? 0) === 1): ?> <span class="tag warn">keyed from a paper sheet</span><?php endif; ?><?php if (($gr['backdate_reason'] ?? null) !== null): ?> <span class="muted"><?= $e($gr['backdate_reason']) ?></span><?php endif; ?></dd>
  <dt>Bench check</dt><dd><?php if (($gr['checked_at'] ?? null) === null): ?>not done yet<?php else: ?><?php if ((int) $gr['paperwork_ok'] === 1): ?>supplier and paperwork credible<?php else: ?>NOT credible<?php endif; ?>, <?= $uk($gr['checked_at']) ?><?php if (($gr['bench_note'] ?? null) !== null): ?> &middot; <?= $e($gr['bench_note']) ?><?php endif; ?><?php endif; ?></dd>
  <dt>Keyed by</dt><dd><?= $e($people['created']) ?></dd>
<?php if ($doc->postedAt !== null): ?>
  <dt>Posted</dt><dd><?= $uk($doc->postedAt) ?> by <?= $e($people['posted']) ?></dd>
  <dt>Review</dt><dd><?= $e(str_replace('_', ' ', (string) $doc->reviewState)) ?></dd>
<?php endif; ?>
<?php if ($doc->status === 'cancelled'): ?>
  <dt>Cancelled</dt><dd><?= $uk($doc->cancelledAt) ?> by <?= $e($people['cancelled']) ?>: <?= $e($doc->cancelReason) ?></dd>
<?php endif; ?>
<?php if ($doc->note !== null): ?>
  <dt>Note</dt><dd><?= $e($doc->note) ?></dd>
<?php endif; ?>
  <dt>Totals</dt><dd><?= $n($totals['lines']) ?> lines, <?= $n($totals['units']) ?> units on the paperwork, net <?= $e($totals['net']) ?>: <?= $n($totals['accepted']) ?> into MAIN,
    <?= $n($totals['verify']) ?> into VERIFY, <?= $n($totals['quarantine']) ?> quarantined, <?= $n($totals['refused']) ?> refused, <?= $n($totals['short']) ?> short</dd>
</dl>

<?php if ($plan !== null): ?>
<section class="card box checklist" aria-labelledby="ready-h">
  <h2 id="ready-h">Before posting</h2>
<?php if ($plan['problems'] === []): ?>
  <p class="notice">Ready to post.</p>
<?php else: ?>
  <ul class="problems">
<?php foreach ($plan['problems'] as $p): ?>
    <li><?= $e($p['message']) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($plan['warnings'] !== []): ?>
  <ul class="warnings">
<?php foreach ($plan['warnings'] as $w): ?>
    <li><?= $e($w) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($canSetInvoice): ?>
  <form class="record" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/invoice') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <fieldset>
      <legend>The supplier invoice</legend>
      <label>Supplier invoice number (needed before posting) <input type="text" name="invoice_number" maxlength="64" autocomplete="off" value="<?= $e($doc->externalRef) ?>"></label>
      <label>Invoice date <input type="date" name="invoice_date" value="<?= $e($gr['invoice_date'] ?? '') ?>"></label>
      <label>Delivery note <input type="text" name="delivery_note" maxlength="64" value="<?= $e($gr['delivery_note'] ?? '') ?>"></label>
      <button type="submit">Set the invoice</button>
    </fieldset>
  </form>
  <p class="muted">Anyone who receives goods can set the supplier invoice of a receipt someone else started (at the bench, from the delivery note); attach the
    invoice copy below. The lines stay with the person who keyed them. Whoever sets the invoice of a receipt they did not key does not review it.</p>
<?php endif; ?>
<?php if ($canPostDraft): ?>
  <form class="inline" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/post') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <button type="submit" class="primary">Post the receipt</button>
  </form>
  <p class="muted">Only the person who keyed it changes its lines; anyone who receives goods may check it at the bench, set its supplier invoice and post it.</p>
<?php endif; ?>
</section>
<?php endif; ?>

<section aria-labelledby="lines-h">
  <h2 id="lines-h">Lines</h2>
  <div class="scroll">
  <table class="stack grn-lines">
    <thead>
      <tr>
        <th scope="col" class="num">#</th>
        <th scope="col">Item</th>
        <th scope="col">Pack</th>
        <th scope="col" class="num">Packs</th>
        <th scope="col" class="num">Units</th>
        <th scope="col" class="num">Pack price</th>
        <th scope="col">Order line</th>
        <th scope="col">Booked</th>
        <th scope="col">Duty stamp</th>
        <th scope="col">Selling mode</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $no => $l): ?>
      <tr>
        <td class="num" data-label="Line"><?= $n($no) ?></td>
        <td data-label="Item"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_code']) ?></a> <?= $e($l['sku_name']) ?><?php if ($l['supplier_code'] !== null): ?> <span class="muted">(<?= $e($l['supplier_code']) ?>)</span><?php endif; ?><?php if ($l['description'] !== null): ?> <span class="muted"><?= $e($l['description']) ?></span><?php endif; ?></td>
        <td data-label="Pack"><?= $e($l['pack_label']) ?></td>
        <td class="num" data-label="Packs"><?= $n($l['packs']) ?></td>
        <td class="num" data-label="Units"><?= $n($l['units']) ?></td>
        <td class="num" data-label="Pack price"><?= $e($l['price']) ?></td>
        <td data-label="Order line"><?php if ($l['po_line_no'] !== null): ?>line <?= $n($l['po_line_no']) ?><?php endif; ?></td>
        <td data-label="Booked"><?= $n($l['split']['accepted']) ?> MAIN<?php if ($l['split']['verify'] > 0): ?>, <?= $n($l['split']['verify']) ?> VERIFY<?php endif; ?><?php if ($l['split']['quarantine'] > 0): ?>, <?= $n($l['split']['quarantine']) ?> UNSTAMPED<?php endif; ?><?php if ($l['split']['refused'] > 0): ?>, <?= $n($l['split']['refused']) ?> refused<?php endif; ?><?php if ($l['split']['short'] > 0): ?>, <?= $n($l['split']['short']) ?> short<?php endif; ?><?php if ($doc->status === 'draft'): ?> <span class="muted">(when posted)</span><?php endif; ?></td>
        <td data-label="Duty stamp"><?php if ($l['stamp_req'] === false): ?><span class="muted">not needed</span><?php elseif ($l['stamp_on_pack'] === null): ?><span class="tag warn">not checked</span><?php elseif ((int) $l['stamp_on_pack'] === 1): ?>on the pack<?php if ($l['stamp_type'] !== null): ?>, <?= $e($l['stamp_type']) ?><?php endif; ?><?php else: ?><span class="tag bad">not on the pack</span><?php endif; ?><?php if ($l['stamp_code'] !== null): ?> <code><?= $e($l['stamp_code']) ?></code><?php endif; ?><?php if ($l['duty_text'] !== null): ?> <span class="muted">duty about <?= $e($l['duty_text']) ?></span><?php endif; ?></td>
        <td data-label="Selling mode"><?php if ($l['mode_resolved'] !== null): ?><?= $e($l['mode_resolved']['mode']) ?> <span class="muted">(<?= $e($modeSources[$l['mode_resolved']['source']] ?? $l['mode_resolved']['source']) ?>)</span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<?php if ($incidents !== []): ?>
<section aria-labelledby="incidents-h">
  <h2 id="incidents-h">Incidents</h2>
  <ul class="plain">
<?php foreach ($incidents as $i): ?>
    <li><span class="tag<?php if ($i['status'] === 'open'): ?> warn<?php endif; ?>"><?= $e($i['status']) ?></span> line <?= $n($i['line_no']) ?>, <?= $e($i['sku_code']) ?>: <?= $n($i['units']) ?>
      <?= $e(str_replace('_', ' ', $i['kind'])) ?>, <?= $e(str_replace('_', ' ', $i['disposition'])) ?><?php if ($i['resolution'] !== null): ?> &middot; <span class="muted"><?= $e($i['resolution']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
<?php if ($canResolve): ?>
  <p><a href="/ui/receiving/incidents">Close incidents in the register</a></p>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tasks !== []): ?>
<section aria-labelledby="review-h">
  <h2 id="review-h">Review</h2>
  <ul class="plain">
<?php foreach ($tasks as $t): ?>
    <li><?= $e($t['of']) ?>: <?= $e($t['kind']) ?> <span class="status"><?= $e($t['state']) ?></span> opened <?= $uk($t['opened_at']) ?> by <?= $e($t['opened_by_name']) ?>, due <?= $uk($t['due_at']) ?><?php if ($t['overdue']): ?> <span class="tag bad">overdue</span><?php endif; ?><?php if ($t['decided_by_name'] !== null): ?>; <?= $e($t['state']) ?> by <?= $e($t['decided_by_name']) ?><?php if ($t['decision_note'] !== null): ?>: <?= $e($t['decision_note']) ?><?php endif; ?><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
<?php foreach ($decide as $d): ?>
<?php if ($d['refusal'] !== null): ?>
  <p class="note"><?= $e($d['refusal']) ?></p>
<?php else: ?>
  <article class="review card">
    <h3>Review <?= $e($d['of']) ?></h3>
    <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $d['task']['id'] . '/approve') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <label>Note <input type="text" name="note" maxlength="500"></label>
      <button type="submit" class="primary">Approve</button>
    </form>
<?php if (!$d['reversal'] && $poClosed !== null): ?>
    <p class="note">This receipt cannot be rejected here: rejecting reverses it, and <?= $e($poClosed) ?> was closed after the delivery (its receipts would reopen
      it). Approve it if it is right; if it is wrong, leave the review open and ask the purchasing manager (the stock is corrected with an adjustment).</p>
<?php else: ?>
    <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $d['task']['id'] . '/reject') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <label>Why (required) <input type="text" name="note" minlength="3" maxlength="500" required></label>
      <button type="submit"><?php if ($d['reversal']): ?>Reject (records it)<?php else: ?>Reject and reverse<?php endif; ?></button>
    </form>
<?php if (!$d['reversal']): ?>
    <p class="muted">Rejecting reverses the receipt: its stock, its order receipts and its incidents are taken back; the desk keys it again if the goods are here.</p>
<?php endif; ?>
<?php endif; ?>
  </article>
<?php endif; ?>
<?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($reverseReasons !== []): ?>
<section aria-labelledby="reverse-h">
  <h2 id="reverse-h">Reverse the receipt</h2>
  <form class="inline" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/reverse') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>Why
      <select name="reason_code" required>
<?php foreach ($reverseReasons as $r): ?>
        <option value="<?= $e($r['code']) ?>"><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Note <input type="text" name="note" maxlength="1000"></label>
    <button type="submit">Reverse</button>
  </form>
  <p class="muted">A correction: the stock booked by this receipt, its order receipts and its open incidents are taken back, and its invoice number is free to
    key again. A second person reviews the reversal.</p>
</section>
<?php endif; ?>

<?= $partial('receipt_files', ['doc' => $doc, 'files' => $files, 'fileRoles' => $fileRoles, 'csrf' => $csrf, 'canAttach' => $canAttach, 'back' => '']) ?>
