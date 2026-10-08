<div class="head-help">
  <h1><?= $e($title) ?></h1>
  <?= $explain('how_sure', \CW\Ui\Words::THING['band']) ?>
</div>
<?= $intro('queue_' . $qc->band, $lookOnly) ?>

<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <input type="hidden" name="queue" value="<?= $e($qc->band) ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('QUEUE', 'text') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($qc->text) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($qc->channel !== null || $qc->lane !== null || $qc->min > 0): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
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
      <div class="pop-actions"><a class="btn ghost sm" href="<?= $e($clear_link) ?>"><?= $word('QUEUE', 'clear') ?></a><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
</form>
<?php $bandTotal = array_sum(array_column($bands, 'count')); ?>
<section class="card strength" aria-label="<?= $word('QUEUE', 'lists') ?>">
  <p class="hint"><?= $word('QUEUE', 'strength') ?></p>
<?php if ($bandTotal > 0): ?>
  <div class="battery" aria-hidden="true"><?php foreach ($bands as $b): ?><?php if ($b['count'] > 0): ?><span class="<?= $e(\CW\Ui\Words::tone('BAND', $b['band'])) ?> w-<?= $e(max(1, (int) round($b['count'] * 100 / $bandTotal))) ?>"></span><?php endif; ?><?php endforeach; ?></div>
<?php endif; ?>
  <nav class="legend" aria-label="<?= $word('QUEUE', 'lists') ?>">
<?php foreach ($bands as $b): ?>
    <a class="<?= $e(\CW\Ui\Words::tone('BAND', $b['band'])) ?>" href="<?= $u('/ui/review', ['queue' => $b['band'], 'channel' => $qc->channel]) ?>"<?php if ($b['band'] === $qc->band): ?> aria-current="page"<?php endif; ?>><span class="key" aria-hidden="true"></span><?= $e($b['label']) ?> <strong class="tab-count"><?= $n($b['count']) ?></strong></a>
<?php endforeach; ?>
  </nav>
</section>
<?php if ($qc->band === 'Manual'): ?>
<p class="note"><?= $word('QUEUE', 'renamed_note') ?></p>
<?php endif; ?>
<?php if ($leadOnly): ?>
<p class="note"><?= $word('QUEUE', 'lead_only') ?></p>
<?php endif; ?>

<?php if ($total === 0 && $filtered): ?>
<?= $empty(\CW\Ui\Words::QUEUE['empty_filter'], \CW\Ui\Words::QUEUE['empty_filter_text'], $clear_link, \CW\Ui\Words::QUEUE['clear']) ?>
<?php elseif ($total === 0 && $next_list !== null): ?>
<?= $empty(\CW\Ui\Words::QUEUE['empty'], \CW\Ui\Words::QUEUE['empty_text'], $next_list['href'], \CW\Ui\Words::say('QUEUE', 'next_list', $next_list['label'], $next_list['count'])) ?>
<?php elseif ($total === 0): ?>
<?= $empty(\CW\Ui\Words::QUEUE['empty'], \CW\Ui\Words::QUEUE['all_done'], '/ui/', \CW\Ui\Words::MENU['home']) ?>
<?php else: ?>
<div class="board-head"><h2 class="board-title"><?= $word('QUEUE', 'board') ?></h2><p class="board-note"><?php if ($total === 1): ?><?= $word('QUEUE', 'total_one') ?><?php else: ?><?= $say('QUEUE', 'total_many', $total) ?><?php endif; ?></p></div>
<?php endif; ?>

<?php if ($rows !== []): ?>
<?php $tone = \CW\Ui\Words::tone('BAND', $qc->band); ?>
<h3 class="grp-title <?= $e($tone) ?>"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-band"><?= $word('BAND', $qc->band) ?></button><span class="grp-count"><?= $say('QUEUE', 'in_all', $total, count($rows)) ?></span></h3>
<div class="table-wrap" id="g-band">
<table class="stack list queue board <?= $e($tone) ?>">
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
