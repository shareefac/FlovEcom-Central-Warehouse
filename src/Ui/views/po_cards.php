<?php
// Under the order's paper (docs/decisions.md RD-B4; one look, LP1: a draft's paper too): Related (its deliveries and invoices, the orders it
// corrects or that correct it, its cancellation and that PDF, its files) and History (what happened to it, newest first, then its checks and
// OKs). $related, $events: PurchaseOrdersController::docView's (the events: started, each person who changed it while a draft, asked,
// confirmed, sent, closed, cancelled: the order's ONE history, LP10; $eventsMore: the older changes left out); $history: viewData's.
?>
<div class="po-cards">
<section class="card po-related" aria-labelledby="related-h">
  <h2 id="related-h"><?= $word('ORDER', 'related') ?></h2>
<?php if ($related === []): ?>
  <p class="muted"><?= $word('ORDER', 'no_related') ?></p>
<?php else: ?>
  <ul class="plain po-rel-list">
<?php foreach ($related as $rel): ?>
<?php if ($rel['href'] === null): ?>
    <li><span class="po-rel-item"><span class="po-rel-text"><?= $e($rel['text']) ?><?php if (($rel['sub'] ?? null) !== null): ?><span class="o-sub"><?= $e($rel['sub']) ?></span><?php endif; ?></span><?php if (($rel['pill'] ?? null) !== null): ?><?= $chip($rel['tone'], $rel['pill']) ?><?php endif; ?></span></li>
<?php else: ?>
    <li><a href="<?= $u($rel['href']) ?>"><span class="po-rel-text"><?= $e($rel['text']) ?><?php if (($rel['sub'] ?? null) !== null): ?><span class="o-sub"><?= $e($rel['sub']) ?></span><?php endif; ?></span><?php if (($rel['pill'] ?? null) !== null): ?><?= $chip($rel['tone'], $rel['pill']) ?><?php endif; ?></a></li>
<?php endif; ?>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<?= $partial('po_history', ['events' => $events, 'eventsMore' => $eventsMore ?? null, 'history' => $history]) ?>
</div>
