<div class="head-help">
  <h1><?= $e($title) ?></h1>
  <?= $explain('how_sure', \CW\Ui\Words::THING['band']) ?>
</div>
<?= $intro('queue_' . $qc->band, $lookOnly) ?>

<nav class="tabs" aria-label="<?= $word('QUEUE', 'lists') ?>">
<?php foreach ($bands as $b): ?>
  <a href="<?= $u('/ui/review', ['queue' => $b['band'], 'channel' => $qc->channel]) ?>"<?php if ($b['band'] === $qc->band): ?> aria-current="page"<?php endif; ?>><?= $e($b['label']) ?> <span class="tab-count">(<?= $n($b['count']) ?>)</span></a>
<?php endforeach; ?>
</nav>
<p class="see-also"><a href="/ui/review?queue=pending"><?= $word('MENU', 'pending') ?></a></p>
<?php if ($qc->band === 'Manual'): ?>
<p class="note"><?= $word('QUEUE', 'renamed_note') ?></p>
<?php endif; ?>
<?php if ($leadOnly): ?>
<p class="note"><?= $word('QUEUE', 'lead_only') ?></p>
<?php endif; ?>

<form class="filters" method="get" action="/ui/review">
  <input type="hidden" name="queue" value="<?= $e($qc->band) ?>">
  <label><?= $word('QUEUE', 'website') ?>
    <select name="channel">
      <option value=""><?= $word('QUEUE', 'any_website') ?></option>
<?php foreach ($channels as $c): ?>
      <option value="<?= $e($c['code']) ?>"<?php if ($qc->channel === $c['code']): ?> selected<?php endif; ?>><?= $e($c['name']) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('QUEUE', 'found_by') ?>
    <select name="lane">
      <option value=""><?= $word('QUEUE', 'any') ?></option>
<?php foreach ($lanes as $lane): ?>
      <option value="<?= $e($lane) ?>"<?php if ($qc->lane === $lane): ?> selected<?php endif; ?>><?= $word('LANE', $lane) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('QUEUE', 'min') ?>
    <input type="number" name="min" min="0" max="999999" inputmode="numeric" value="<?= $e($qc->min > 0 ? $qc->min : '') ?>">
  </label>
  <label><?= $word('QUEUE', 'text') ?>
    <input type="search" name="q" value="<?= $e($qc->text) ?>" maxlength="100">
  </label>
  <button type="submit"><?= $word('QUEUE', 'show') ?></button>
</form>

<?php if ($total === 0 && $filtered): ?>
<?= $empty(\CW\Ui\Words::QUEUE['empty_filter'], \CW\Ui\Words::QUEUE['empty_filter_text'], $clear_link, \CW\Ui\Words::QUEUE['clear']) ?>
<?php elseif ($total === 0 && $next_list !== null): ?>
<?= $empty(\CW\Ui\Words::QUEUE['empty'], \CW\Ui\Words::QUEUE['empty_text'], $next_list['href'], \CW\Ui\Words::say('QUEUE', 'next_list', $next_list['label'], $next_list['count'])) ?>
<?php elseif ($total === 0): ?>
<?= $empty(\CW\Ui\Words::QUEUE['empty'], \CW\Ui\Words::QUEUE['all_done'], '/ui/', \CW\Ui\Words::MENU['home']) ?>
<?php else: ?>
<p class="muted"><?php if ($total === 1): ?><?= $word('QUEUE', 'total_one') ?><?php else: ?><?= $say('QUEUE', 'total_many', $total) ?><?php endif; ?></p>
<?php endif; ?>

<?php if ($rows !== []): ?>
<div class="table-wrap">
<table class="stack list queue">
  <thead>
    <tr>
      <th scope="col"><?= $word('QUEUE', 'product') ?></th>
      <th scope="col"><?= $word('QUEUE', 'website') ?></th>
      <th scope="col" class="num"><?= $word('QUEUE', 'sold_365') ?></th>
      <th scope="col" class="num"><?= $word('QUEUE', 'sold_30') ?></th>
      <th scope="col"><?= $word('QUEUE', 'suggested') ?></th>
      <th scope="col"><?= $word('QUEUE', 'ai') ?></th>
      <th scope="col"><?= $word('QUEUE', 'watch') ?></th>
      <th scope="col"><span class="visually-hidden"><?= $e($button) ?></span></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head">
        <a class="o-name" href="<?= $e($r['link']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a>
<?php if ($r['variant_title'] !== null): ?>
        <span class="o-sub"><?= $e($r['variant_title']) ?></span>
<?php endif; ?>
<?php if ($r['brand'] !== null): ?>
        <span class="o-sub"><?= $e($r['brand']) ?></span>
<?php endif; ?>
      </th>
      <td data-label="<?= $word('QUEUE', 'website') ?>"><?= $e($r['channel']) ?> <span class="muted small"><?= $say('QUEUE', 'option', (string) $r['variant']) ?></span></td>
      <td class="num" data-label="<?= $word('QUEUE', 'sold_365') ?>"><?= $n($r['units_365d'] ?? 0) ?></td>
      <td class="num" data-label="<?= $word('QUEUE', 'sold_30') ?>"><?= $n($r['units_30d'] ?? 0) ?></td>
      <td data-label="<?= $word('QUEUE', 'suggested') ?>">
<?php if ($r['new_item']): ?>
        <?= $word('QUEUE', 'new_product') ?>
<?php elseif ($r['sku_code'] !== null): ?>
        <?= $e($r['sku_code']) ?> <span class="muted"><?= $e($r['sku_name']) ?></span>
<?php else: ?>
        <span class="muted"><?= $word('QUEUE', 'none') ?></span>
<?php endif; ?>
<?php if ($r['same_barcode']): ?>
        <span class="o-sub"><?= $chip('done', \CW\Ui\Words::QUEUE['same_barcode']) ?></span>
<?php endif; ?>
      </td>
      <td data-label="<?= $word('QUEUE', 'ai') ?>"><?= $e($r['ai'] ?? '') ?></td>
      <td data-label="<?= $word('QUEUE', 'watch') ?>"><?php foreach ($r['watch'] as $w): ?><span class="tag warn"><?= $e($w) ?></span> <?php endforeach; ?></td>
      <td class="c-next"><a class="btn<?php if ($button === \CW\Ui\Words::QUEUE['open']): ?> primary<?php else: ?> secondary<?php endif; ?>" href="<?= $e($r['link']) ?>"><?= $e($button) ?></a></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('QUEUE', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('QUEUE', 'previous') ?></a><?php endif; ?>
  <span><?= $say('QUEUE', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('QUEUE', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
