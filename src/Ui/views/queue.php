<?php
// Matching › To review, one list (a match strength) as a board (the approved design of 29 Oct 2026, GI_List; docs/decisions.md W12.5): one
// search and ONE Show control (DC27's pop-over: the store, the list, what it shows, how a match was found, the units sold at least: the
// store tabs, the strength tabs, the show tabs and the Filter pop-over before), Clear at the right; how much is left and answered today under the title; then the
// list's group (its colour and count, "Select all on this page"), each row its status cell, a row's Check & match and Open at its end.
$tone = \CW\Ui\Words::tone('BAND', $qc->band);
?>
<?= $head((string) $title, intro: 'queue_' . $qc->band, look: $lookOnly, meta: '<span data-progress="' . $e($progress) . '">' . $e($progress) . '</span>') ?>

<form class="toolbar" method="get" action="/ui/review" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('QUEUE', 'text') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($qc->text) ?>" maxlength="100" placeholder="<?= $word('FIND', 'listings') ?>" aria-keyshortcuts="/" data-page-search></div>
<?php $on = ($qc->channel !== null ? 1 : 0) + ($qc->show !== 'all' ? 1 : 0) + ($qc->lane !== null ? 1 : 0) + ($qc->min > 0 ? 1 : 0); ?>
  <div class="tb-state">
    <details class="tb-pop tb-show">
      <summary class="btn"><?= $word('QUEUE', 'show') ?><?php if ($on > 0): ?> <span class="count"><?= $n($on) ?><span class="visually-hidden"> <?= $word('CARDS', 'filters_on') ?></span></span><?php endif; ?></summary>
      <div class="pop pop-form">
    <label for="tb-show"><?= $word('BULK', 'stores') ?>
    <select id="tb-show" name="channel">
<?php foreach ($stores as $it): ?>
      <option value="<?= $e($it['query']['channel'] ?? '') ?>"<?php if ($it['current']): ?> selected<?php endif; ?>><?= $e($it['label']) ?> (<?= $n($it['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    </label>
    <label for="tb-list"><?= $word('QUEUE', 'lists') ?>
    <select id="tb-list" name="queue">
<?php foreach ($bands as $b): ?>
      <option value="<?= $e($b['band']) ?>"<?php if ($b['band'] === $qc->band): ?> selected<?php endif; ?>><?= $e($b['label']) ?> (<?= $n($b['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    </label>
<?php if ($shows !== []): ?>
    <label for="tb-what"><?= $word('QUEUE', 'show') ?>
    <select id="tb-what" name="show" aria-label="<?= $word('QUEUE', 'show') ?>">
<?php foreach ($shows as $s): ?>
      <option value="<?php if ($s['key'] !== 'all'): ?><?= $e($s['key']) ?><?php endif; ?>"<?php if ($s['current']): ?> selected<?php endif; ?>><?= $e($s['label']) ?> (<?= $n($s['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    </label>
<?php endif; ?>
    <label for="tb-lane"><?= $word('QUEUE', 'found_by') ?>
    <select id="tb-lane" name="lane">
      <option value=""><?= $word('QUEUE', 'any') ?></option>
<?php foreach ($lanes as $lane): ?>
      <option value="<?= $e($lane) ?>"<?php if ($qc->lane === $lane): ?> selected<?php endif; ?>><?= $word('LANE', $lane) ?></option>
<?php endforeach; ?>
    </select>
    </label>
    <label for="tb-min"><?= $word('QUEUE', 'min') ?>
    <input id="tb-min" type="number" name="min" min="0" max="999999" inputmode="numeric" value="<?= $e($qc->min > 0 ? $qc->min : '') ?>">
    </label>
        <div class="pop-actions"><button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button></div>
      </div>
    </details>
  </div>
  <div class="tb-end">
<?php if ($qc->filtered()): ?>
    <a class="btn ghost sm" href="<?= $e($clear_link) ?>"><?= $word('QUEUE', 'clear') ?></a>
<?php endif; ?>
  </div>
</form>
<?php if ($qc->text !== '' && $total > 0): ?>
<?= $partial('search_note', ['count' => $total, 'text' => $qc->text, 'clear' => $clear_search]) ?>
<?php endif; ?>
<?php if ($leadOnly): ?>
<p class="note"><?= $word('QUEUE', 'lead_only') ?></p>
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
<?php endif; ?>

<?php if ($rows !== []): ?>
<?php $bulk = $bulkActions !== []; ?>
<?php if ($bulk): ?>
<form class="bulk-form" method="post" action="/ui/review/bulk" data-bulk data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="source" value="review">
<?php foreach ($bulkKeep as $key => $value): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
<?php endif; ?>
<div class="grp-block doc-group <?= $e($tone) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-band"><?= $word('BAND', $qc->band) ?></button> <span class="grp-count"><?= $say('QUEUE', 'in_all', $total, count($rows)) ?></span><?php if ($bulk): ?> <a class="pick-all" href="<?= $e($allLink) ?>" data-bulk-all-link><?= $word('BULK', 'select_all') ?></a><?php endif; ?></h3>
  <div class="table-wrap doc-board" id="g-band">
  <table class="stack list board queue<?php if ($bulk): ?> picks<?php endif; ?>">
    <thead>
      <tr>
<?php if ($bulk): ?>
        <th scope="col" class="c-pick"><label class="pick"><input type="checkbox" data-bulk-all hidden><span class="visually-hidden"><?= $word('BULK', 'select_all') ?></span></label></th>
<?php endif; ?>
        <th scope="col" class="c-item"><?= $word('QUEUE', 'product') ?></th>
        <th scope="col" class="c-status"><?= $word('BY_STORE', 'strength') ?></th>
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
        <th scope="row" class="c-head c-item">
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
        <td class="c-status doc-st <?= $e($tone) ?>"><span class="chip"><?= $word('BAND', $qc->band) ?></span></td>
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
          <span class="o-sub"><span class="tag done"><?= $word('QUEUE', 'same_barcode') ?></span></span>
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
