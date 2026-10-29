<?= $head((string) $title, intro: 'queue_' . $qc->band, look: $lookOnly, aside: $explain('how_sure', \CW\Ui\Words::THING['band'])) ?>
<?= $partial('store_seg', ['items' => $stores, 'countWords' => \CW\Ui\Words::BULK['store_count_review']]) ?>

<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <input type="hidden" name="queue" value="<?= $e($qc->band) ?>">
<?php if ($qc->channel !== null): ?>
  <input type="hidden" name="channel" value="<?= $e($qc->channel) ?>">
<?php endif; ?>
<?php if ($show !== null): ?>
  <input type="hidden" name="show" value="<?= $e($show) ?>">
<?php endif; ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('QUEUE', 'text') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($qc->text) ?>" maxlength="100" placeholder="<?= $word('FIND', 'listings') ?>" aria-keyshortcuts="/" data-page-search></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($qc->lane !== null || $qc->min > 0): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
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
      <div class="pop-actions"><a class="btn ghost sm" href="<?= $e($clear_link) ?>"><?= $word('QUEUE', 'clear') ?></a><button type="submit" class="btn sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
</form>
<?php if ($qc->text !== '' && $total > 0): ?>
<?= $partial('search_note', ['count' => $total, 'text' => $qc->text, 'clear' => $clear_search]) ?>
<?php endif; ?>
<?php $bandTotal = array_sum(array_column($bands, 'count')); ?>
<section class="card strength" aria-label="<?= $word('QUEUE', 'lists') ?>">
  <p class="hint"><?= $word('QUEUE', 'strength') ?></p>
<?php if ($bandTotal > 0): ?>
  <div class="battery" aria-hidden="true"><?php foreach ($bands as $b): ?><?php if ($b['count'] > 0): ?><span class="<?= $e(\CW\Ui\Words::tone('BAND', $b['band'])) ?> w-<?= $e(max(1, (int) round($b['count'] * 100 / $bandTotal))) ?>"></span><?php endif; ?><?php endforeach; ?></div>
<?php endif; ?>
  <nav class="tabs legend" aria-label="<?= $word('QUEUE', 'lists') ?>">
<?php foreach ($bands as $b): ?>
    <a class="tab <?= $e(\CW\Ui\Words::tone('BAND', $b['band'])) ?>"<?php if ($b['band'] === $qc->band): ?> aria-current="page"<?php else: ?> href="<?= $u('/ui/review', ['queue' => $b['band'], 'channel' => $qc->channel, 'show' => $show]) ?>"<?php endif; ?>><span class="key" aria-hidden="true"></span><?= $e($b['label']) ?> <span class="count"><?= $n($b['count']) ?></span></a>
<?php endforeach; ?>
  </nav>
</section>
<?php if ($qc->band === 'Manual'): ?>
<p class="note"><?= $word('QUEUE', 'renamed_note') ?></p>
<?php endif; ?>
<?php if ($leadOnly): ?>
<p class="note"><?= $word('QUEUE', 'lead_only') ?></p>
<?php endif; ?>
<?php if ($shows !== []): ?>
<nav class="tabs show-tabs" aria-label="<?= $word('QUEUE', 'show') ?>">
<?php foreach ($shows as $s): ?>
  <a class="tab"<?php if ($s['current']): ?> aria-current="page"<?php else: ?> href="<?= $e($s['href']) ?>"<?php endif; ?>><?= $e($s['label']) ?> <span class="count"><?= $n($s['count']) ?></span></a>
<?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($noBox !== null): ?>
<div class="alert info no-box" role="note">
  <p><?= $e($noBox['text']) ?><?php if ($noBox['link'] !== null): ?> <a href="<?= $e($noBox['link'][0]) ?>"><?= $e($noBox['link'][1]) ?></a><?php endif; ?><?php if ($noBox['none'] !== null): ?> <?= $e($noBox['none']) ?><?php endif; ?></p>
</div>
<?php endif; ?>

<?php if ($total === 0 && $filtered): ?>
<?= $empty(\CW\Ui\Words::QUEUE['empty_filter'], \CW\Ui\Words::QUEUE['empty_filter_text'] . ($qc->text !== '' ? ' ' . \CW\Ui\Words::FIND['try'] : ''), $clear_link, \CW\Ui\Words::QUEUE['clear']) ?>
<?php elseif ($total === 0 && $next_list !== null): ?>
<?= $empty(\CW\Ui\Words::QUEUE['empty'], \CW\Ui\Words::QUEUE['empty_text'], $next_list['href'], \CW\Ui\Words::say('QUEUE', 'next_list', $next_list['label'], $next_list['count'])) ?>
<?php elseif ($total === 0): ?>
<?= $empty(\CW\Ui\Words::QUEUE['empty'], \CW\Ui\Words::QUEUE['all_done'], '/ui/', \CW\Ui\Words::MENU['home']) ?>
<?php else: ?>
<div class="board-head"><h2 class="board-title"><?= $word('QUEUE', 'board') ?></h2><p class="board-note"><?php if ($total === 1): ?><?= $word('QUEUE', 'total_one') ?><?php else: ?><?= $say('QUEUE', 'total_many', $total) ?><?php endif; ?></p><?php if ($progress !== null): ?><p class="progress-line" data-progress><?= $e($progress) ?></p><?php endif; ?></div>
<?php endif; ?>

<?php if ($rows !== []): ?>
<?php $tone = \CW\Ui\Words::tone('BAND', $qc->band); ?>
<?php $bulk = $bulkActions !== []; ?>
<?php if ($bulk): ?>
<form class="bulk-form" method="post" action="/ui/review/bulk" data-bulk>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="source" value="review">
<?php foreach ($bulkKeep as $key => $value): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
<?php endif; ?>
<h3 class="grp-title <?= $e($tone) ?>"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-band"><?= $word('BAND', $qc->band) ?></button><span class="grp-count"><?= $say('QUEUE', 'in_all', $total, count($rows)) ?></span><?php if ($bulk): ?> <a class="pick-all" href="<?= $e($allLink) ?>" data-bulk-all-link><?= $word('BULK', 'select_all') ?></a><?php endif; ?></h3>
<div class="table-wrap" id="g-band">
<table class="stack list queue board <?= $e($tone) ?><?php if ($bulk): ?> picks<?php endif; ?>">
  <thead>
    <tr>
<?php if ($bulk): ?>
      <th scope="col" class="c-pick"><label class="pick"><input type="checkbox" data-bulk-all hidden><span class="visually-hidden"><?= $word('BULK', 'select_all') ?></span></label></th>
<?php endif; ?>
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
    <tr id="l-<?= $e($r['listing_id']) ?>"<?php if ($bulk && $r['picked']): ?> class="picked"<?php endif; ?>>
<?php if ($bulk): ?>
      <td class="c-pick" data-label=""><?php if ($r['manual'] === null): ?><label class="pick"><input type="checkbox" name="pick_<?= $e($r['listing_id']) ?>" value="1" data-bulk-row<?php if ($r['picked']): ?> checked<?php endif; ?>><span class="visually-hidden"><?= $say('BULK', 'pick_row', $r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></span></label>
        <input type="hidden" name="ver_<?= $e($r['listing_id']) ?>" value="<?= $e($r['map_version']) ?>"><input type="hidden" name="prop_<?= $e($r['listing_id']) ?>" value="<?= $e($r['proposal_id']) ?>"><?php endif; ?></td>
<?php endif; ?>
      <th scope="row" class="c-head">
        <a class="o-name" href="<?= $e($r['link']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a>
<?php if ($r['variant_title'] !== null): ?>
        <span class="o-sub"><?= $e($r['variant_title']) ?></span>
<?php endif; ?>
<?php if ($r['brand'] !== null): ?>
        <span class="o-sub"><?= $e($r['brand']) ?></span>
<?php endif; ?>
<?php if ($r['manual'] !== null): ?>
        <?= $partial('one_at_a_time', ['why' => $r['manual']]) ?>
<?php endif; ?>
<?php if ($r['check'] !== null): ?>
        <?= $partial('check_fold', ['check' => $r['check'], 'listing' => $r['listing_id'], 'title' => $r['title'], 'link' => $r['link']]) ?>
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
      <td data-label="<?= $word('QUEUE', 'watch') ?>"><?php foreach ($r['watch'] as $w): ?><span class="tag needs"><?= $e($w) ?></span> <?php endforeach; ?></td>
      <td class="c-next"><a class="btn sm" href="<?= $e($r['link']) ?>"><?= $e($button) ?></a></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($bulk): ?>
<?= $partial('bulk_bar', ['actions' => $bulkActions, 'max' => $bulkMax]) ?>
</form>
<?php endif; ?>
<?php endif; ?>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('QUEUE', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('QUEUE', 'previous') ?></a><?php endif; ?>
  <span><?= $say('QUEUE', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('QUEUE', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
