<h1><?= $e($band_label) ?> queue</h1>

<nav class="tabs" aria-label="Queues">
<?php foreach ($bands as $b): ?>
  <a href="<?= $u('/ui/review', ['queue' => $b['band'], 'channel' => $qc->channel]) ?>"<?php if ($b['band'] === $qc->band): ?> aria-current="page"<?php endif; ?>><?= $e($b['label']) ?></a>
<?php endforeach; ?>
  <a href="/ui/review?queue=pending">Second approval</a>
</nav>
<?php if ($qc->band === 'Manual'): ?>
<p class="note">These listings match a Vape and Go item except for a renamed line or brand (for example Electrofag &ldquo;Crystal Pro Max&rdquo;
  = Vape and Go &ldquo;Hayati Pro Max&rdquo;); each page names the rename and the items it pairs with. The rename itself (an alias) is not
  recorded on these screens: a mapping lead confirms aliases separately. Decide each listing on its own: link it to the paired item it is
  the same product as (&ldquo;Use this item&rdquo;), mark it as a new item, or ignore it.</p>
<?php endif; ?>

<form class="filters" method="get" action="/ui/review">
  <input type="hidden" name="queue" value="<?= $e($qc->band) ?>">
  <label>Site
    <select name="channel">
      <option value="">All sites</option>
<?php foreach ($channels as $c): ?>
      <option value="<?= $e($c['code']) ?>"<?php if ($qc->channel === $c['code']): ?> selected<?php endif; ?>><?= $e($c['code']) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label>Lane
    <select name="lane">
      <option value="">Any lane</option>
<?php foreach ($lanes as $lane): ?>
      <option value="<?= $e($lane) ?>"<?php if ($qc->lane === $lane): ?> selected<?php endif; ?>><?= $e($lane) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label>Units, 30 days, at least
    <input type="number" name="min" min="0" max="999999" value="<?= $e($qc->min > 0 ? $qc->min : '') ?>">
  </label>
  <label>Title, brand, variant id or barcode
    <input type="search" name="q" value="<?= $e($qc->text) ?>" maxlength="100">
  </label>
  <button type="submit">Filter</button>
</form>

<p class="muted"><?= $n($total) ?> listing<?php if ($total !== 1): ?>s<?php endif; ?> in this view, best sellers first (units in 365 days, then 30 days).
<?php if ($total === 0): ?>Nothing is waiting here.<?php endif; ?></p>

<?php if ($rows !== []): ?>
<table class="queue">
  <thead>
    <tr>
      <th scope="col">Listing</th>
      <th scope="col">Site</th>
      <th scope="col" class="num">365 days</th>
      <th scope="col" class="num">30 days</th>
      <th scope="col">Proposal</th>
      <th scope="col">AI</th>
      <th scope="col">Flags</th>
      <th scope="col"><span class="visually-hidden">Review</span></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <td>
        <a href="<?= $e($r['link']) ?>"><?= $e($r['title'] ?? '(no title)') ?></a>
<?php if ($r['variant_title'] !== null): ?>
        <span class="muted">&middot; <?= $e($r['variant_title']) ?></span>
<?php endif; ?>
<?php if ($r['brand'] !== null): ?>
        <div class="muted"><?= $e($r['brand']) ?></div>
<?php endif; ?>
      </td>
      <td><?= $e($r['channel']) ?> <span class="muted"><?= $e($r['variant']) ?></span></td>
      <td class="num"><?= $n($r['units_365d'] ?? 0) ?></td>
      <td class="num"><?= $n($r['units_30d'] ?? 0) ?></td>
      <td>
<?php if ($r['new_item']): ?>
        New item
<?php elseif ($r['sku_code'] !== null): ?>
        <?= $e($r['sku_code']) ?> <span class="muted"><?= $e($r['sku_name']) ?></span>
<?php else: ?>
        <span class="muted">none</span>
<?php endif; ?>
<?php if ($r['lane'] !== null): ?>
        <div class="muted"><?= $e($r['lane']) ?></div>
<?php endif; ?>
      </td>
      <td>
<?php if ($r['ai_outcome'] !== null): ?>
        <?= $e($r['ai_outcome']) ?><?php if ($r['confidence'] !== null): ?> <span class="muted"><?= $e($r['confidence']) ?>%</span><?php endif; ?>
<?php endif; ?>
      </td>
      <td>
<?php foreach ($r['flags'] as $flag): ?>
        <span class="tag"><?= $e($flag) ?></span>
<?php endforeach; ?>
      </td>
      <td><a class="button" href="<?= $e($r['link']) ?>">Review</a></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev">Previous</a><?php endif; ?>
  <span>Page <?= $n($page_no) ?> of <?= $n($pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next">Next</a><?php endif; ?>
</nav>
<?php endif; ?>
