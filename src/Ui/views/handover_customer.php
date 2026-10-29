<?php
// One hand-over customer, a record page (the approved design of 29 Oct 2026, pattern 2; docs/decisions.md W8): its name with its status
// block, the actions at the head's right (customer_bar: Change the details, More: switch it off or on), the figures strip (what it owes,
// its hand-overs, the last one, the prices kept), the paper card (the customer as its invoices show it beside its facts), then its related
// records: the prices it paid last and its latest hand-overs, each a board.
$bar = $partial('customer_bar', ['c' => $c, 'v' => $v, 'version' => $version, 'errors' => $errors, 'kinds' => $kinds, 'vatCodes' => $vatCodes, 'canManage' => $canManage,
    'error' => $error]);
?>
<p class="crumbs"><a href="/ui/stock/handovers/customers"><?= $word('SEGMENT', 'handover_customers') ?></a></p>
<div class="doc-head">
<?= $head((string) $c['name'], intro: 'handover_customer', look: $lookOnly,
    chips: (int) $c['is_active'] === 1 ? $chip('done', \CW\Ui\Words::CUSTOMER['on']) : $chip('off', \CW\Ui\Words::CUSTOMER['off']), side: $bar) ?>
</div>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<section class="doc-progress rp-figs" aria-label="<?= $word('CUSTOMER', 'details') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('CUSTOMER', 'balance') ?></p><p class="doc-step-big"><?= $money($balance) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('CUSTOMER', 'handovers') ?></p><p class="doc-step-big"><?= $n(count($handovers)) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('CUSTOMER', 'last') ?></p><p class="doc-step-big"><?php if ($lastHandover !== null): ?><?= $day($lastHandover) ?><?php endif; ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('CUSTOMER', 'prices_kept') ?></p><p class="doc-step-big"><?= $n(count($prices)) ?></p></div>
</section>

<article class="doc-view" aria-label="<?= $word('CUSTOMER', 'details') ?>">
<?= $partial('doc_party', ['party' => $party, 'facts' => $docFacts]) ?>
</article>

<section class="doc-related" aria-labelledby="prices-h">
  <div class="board-head"><h2 id="prices-h" class="board-sub"><?= $word('CUSTOMER', 'prices') ?></h2></div>
<?php if ($prices === []): ?>
  <p class="muted"><?= $word('CUSTOMER', 'no_prices') ?></p>
<?php else: ?>
  <div class="table-wrap doc-board info">
  <table class="stack list board doc-list prices">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('CUSTOMER', 'price_item') ?></th>
        <th scope="col" class="num"><?= $word('CUSTOMER', 'price') ?></th>
        <th scope="col"><?= $word('CUSTOMER', 'price_day') ?></th>
        <th scope="col"><?= $word('CUSTOMER', 'price_from') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($prices as $p): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a href="<?= $u('/ui/items/' . $p['sku_id']) ?>"><?= $e($p['name']) ?></a><span class="o-sub"><?= $e($p['code']) ?></span></th>
        <td class="num" data-label="<?= $word('CUSTOMER', 'price') ?>"><?= $money($p['unit_price']) ?></td>
        <td data-label="<?= $word('CUSTOMER', 'price_day') ?>"><?= $day($p['priced_on']) ?></td>
        <td data-label="<?= $word('CUSTOMER', 'price_from') ?>"><?php if ($p['source'] === 'handover' && $p['document_id'] !== null): ?><a href="<?= $u('/ui/stock/handovers/' . $p['document_id']) ?>"><?= $e($p['number']) ?></a><?php else: ?><?= $say('CUSTOMER', 'price_imported', (string) ($p['source_ref'] ?? '')) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<section class="doc-related" aria-labelledby="recent-h">
  <div class="board-head"><h2 id="recent-h" class="board-sub"><?= $word('CUSTOMER', 'recent') ?></h2><a href="<?= $e($listHref) ?>"><?= $word('CUSTOMER', 'its_handovers') ?></a></div>
<?php if ($handovers === []): ?>
  <p class="muted"><?= $word('CUSTOMER', 'no_handovers') ?></p>
<?php else: ?>
  <div class="table-wrap doc-board info">
  <table class="stack list board doc-list handover-records">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('HANDOVER', 'record') ?></th>
        <th scope="col" class="c-status"><?= $word('HANDOVER', 'status') ?></th>
        <th scope="col" class="num"><?= $word('HANDOVER', 'units') ?></th>
        <th scope="col" class="num"><?= $word('HANDOVER', 'value') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach (array_slice($handovers, 0, 10) as $h): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($h['href']) ?>"><?= $e($h['number_line']) ?></a><span class="o-sub"><?= $e($h['date_line']) ?></span></th>
        <td class="c-status doc-st <?= $e($h['tone']) ?>"><?= $e($h['state_word']) ?></td>
        <td class="num" data-label="<?= $word('HANDOVER', 'units') ?>"><?= $n($h['units']) ?></td>
        <td class="num" data-label="<?= $word('HANDOVER', 'value') ?>"><?php if ($h['value'] !== null): ?><?= $money($h['value']) ?><?php if (!$h['final']): ?><span class="o-sub"><?= $word('HANDOVER', 'before_vat') ?></span><?php endif; ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
