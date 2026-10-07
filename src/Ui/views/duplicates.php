<h1>Duplicates</h1>
<p class="muted">Vape and Go sometimes sells one product on two (or more) pages. When they are the same product, both pages share one
  warehouse item, so their sales and deliveries count once and the stock is not split between them. Nothing changes on the website:
  each page keeps its own price and reviews until Vape and Go switches to the warehouse system. When they are different products,
  keep them separate: they will not be suggested again.</p>

<?php if ($rows === []): ?>
<p>No duplicate suggestions are waiting.</p>
<?php else: ?>
<p><?= $n($total) ?> group<?php if ($total !== 1): ?>s<?php endif; ?> to decide, the biggest sellers first. Many suggestions are not the same
  product: "What the rules say" lists the reasons against merging that the rules see (a different VG/PG, barcode, option, size ...).</p>
<table class="stack dups">
  <thead>
    <tr>
      <th scope="col">Suggested keeper</th>
      <th scope="col" class="num">Pages</th>
      <th scope="col" class="num">Sold, 365 days</th>
      <th scope="col" class="num">Sold, 30 days</th>
      <th scope="col">What the rules say</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><a href="/ui/review/duplicates/<?= $e($r['id']) ?>"><?= $e($r['title'] ?? 'Group ' . $r['id']) ?></a>
<?php if ($r['sku_code'] !== null): ?> <span class="muted"><?= $e($r['sku_code']) ?></span><?php endif; ?>
<?php if ($r['waiting']): ?> <span class="tag warn">waiting for a second person</span><?php endif; ?>
<?php if ($r['decided'] > 0): ?> <span class="tag">partly decided</span><?php endif; ?>
      </th>
      <td class="num" data-label="Pages"><?= $n($r['size']) ?></td>
      <td class="num" data-label="Sold, 365 days"><?= $n($r['units_365d']) ?></td>
      <td class="num" data-label="Sold, 30 days"><?= $n($r['units_30d']) ?></td>
      <td data-label="What the rules say"><?php if ($r['against'] !== []): ?><span class="muted">may be different:</span> <?php foreach ($r['against'] as $d): ?><span class="tag bad"><?= $e($d) ?></span> <?php endforeach; ?><?php elseif ($r['checked']): ?><span class="muted">no reason against found: check the live pages</span><?php else: ?><span class="muted">not checked (no listing profile)</span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($recent !== []): ?>
<section>
  <h2>Decided recently</h2>
  <p class="muted">Open a group to see what was decided, or to undo a merge that was wrong. <?= $n($recent_total) ?> decided group<?php if ($recent_total !== 1): ?>s<?php endif; ?><?php if ($recent_pages > 1): ?>, page <?= $n($recent_page) ?> of <?= $n($recent_pages) ?><?php endif; ?>.</p>
  <ul class="plain dups-recent">
<?php foreach ($recent as $r): ?>
    <li><a href="/ui/review/duplicates/<?= $e($r['id']) ?>"><?= $e($r['title'] ?? 'Group ' . $r['id']) ?></a>
      <span class="muted"><?= $n($r['size']) ?> pages, <?= $n($r['items']) ?> warehouse item<?php if ($r['items'] !== 1): ?>s<?php endif; ?><?php if ($r['at'] !== null): ?>, <?= $dt($r['at']) ?><?php endif; ?></span></li>
<?php endforeach; ?>
  </ul>
<?php if ($recent_prev !== null || $recent_next !== null): ?>
  <p class="pager"><?php if ($recent_prev !== null): ?><a href="<?= $e($recent_prev) ?>">&larr; Newer</a> <?php endif; ?><?php if ($recent_next !== null): ?><a href="<?= $e($recent_next) ?>">Older &rarr;</a><?php endif; ?></p>
<?php endif; ?>
</section>
<?php endif; ?>
