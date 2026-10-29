<?php
// Stock › Reservations (docs/decisions.md RS1-RS12), a board (the approved design of 29 Oct 2026, pattern 1; docs/decisions.md W8): read
// only. One search and one Show row (the state), Clear and Export at the right; the store selector (the matching pages' partial: All stores
// and each store of the channel table, with its reservations held now); the figures strip; then the reservations grouped by state, newest
// first, each group a coloured heading with its count over a card with its colour on the left edge, the status cell filled with it. No form
// here posts anything.
?>
<?= $head(\CW\Ui\Words::MENU['reservations'], intro: 'reservations', aside: $explain('reservations', \CW\Ui\Words::MENU['reservations'])) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
<?php if ($f['channel'] !== null): ?>
  <input type="hidden" name="channel" value="<?= $e($f['channel']) ?>">
<?php endif; ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('RESV', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('RESV', 'find') ?>" title="<?= $word('RESV', 'find') ?>"></div>
  <div class="tb-state">
    <label for="tb-show"><?= $word('DOC_LIST', 'show') ?></label>
    <select id="tb-show" name="state">
      <option value=""><?= $word('RESV', 'any_state') ?></option>
<?php foreach ($states as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="<?= $e($clear) ?>"><?= $word('RESV', 'clear') ?></a>
<?php endif; ?>
<?php if ($rows_n > 0): ?>
    <a class="btn ghost sm" href="<?= $e($csv) ?>" title="<?= $word('RESV', 'download') ?>" download><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
<?php endif; ?>
  </div>
</form>
<?= $partial('store_seg', ['items' => $storeItems, 'countWords' => \CW\Ui\Words::RESV['store_count']]) ?>

<section class="doc-progress rp-figs" aria-label="<?= $word('RESV', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('RESV', 'tile_held') ?></p><p class="doc-step-big"><?= $n($totals['held_units']) ?></p><p class="step-sub"><?php if ($totals['holds'] === 1): ?><?= $word('RESV', 'tile_held_sub_one') ?><?php else: ?><?= $say('RESV', 'tile_held_sub', $totals['holds']) ?><?php endif; ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('RESV', 'tile_soon') ?></p><p class="doc-step-big"><?= $n($totals['soon']) ?></p><p class="step-sub"><?= $word('RESV', 'tile_soon_sub') ?></p></div>
<?php if ($totals['to_ship_units'] !== null): ?>
  <div class="doc-step"><p class="doc-step-label"><?= $word('RESV', 'tile_to_ship') ?></p><p class="doc-step-big"><?= $n($totals['to_ship_units']) ?></p><p class="step-sub"><?= $word('RESV', 'tile_to_ship_sub') ?></p></div>
<?php endif; ?>
</section>
<?php if ($totals['to_ship_units'] === null): ?>
<p class="muted"><?= $word('RESV', 'to_ship_all') ?></p>
<?php endif; ?>
<?php if ($productSearch): ?>
<p class="muted"><?= $word('RESV', 'product_search') ?></p>
<?php endif; ?>
<?php if ($capped !== null): ?>
<p class="note"><?= $say('RESV', 'capped', $capped) ?></p>
<?php endif; ?>

<?php if ($groups === []): ?>
<?php if ($narrowed): ?>
<?= $empty(\CW\Ui\Words::RESV['none_filtered'], \CW\Ui\Words::RESV['none_filtered_text'], $clear, \CW\Ui\Words::RESV['clear']) ?>
<?php elseif ($f['channel'] !== null): ?>
<?= $empty(\CW\Ui\Words::RESV['none_store'], \CW\Ui\Words::RESV['none_text']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::RESV['none'], \CW\Ui\Words::RESV['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php foreach ($groups as $g => $grp): ?>
<div class="grp-block doc-group <?= $e($grp['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $e($grp['name']) ?></button> <span class="grp-count"><?php if (count($grp['rows']) === 1): ?><?= $word('RESV', 'group_one') ?><?php else: ?><?= $say('RESV', 'group_many', count($grp['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g) ?>">
  <table class="stack list board doc-list reservations">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('RESV', 'order') ?></th>
        <th scope="col" class="c-status"><?= $word('RESV', 'status') ?></th>
        <th scope="col"><?= $word('RESV', 'store') ?></th>
        <th scope="col" class="num"><?= $word('RESV', 'products') ?></th>
        <th scope="col" class="num"><?= $word('RESV', 'units') ?></th>
        <th scope="col"><?= $word('RESV', 'started') ?></th>
        <th scope="col"><?= $word('RESV', 'expires') ?></th>
        <th scope="col"><?= $word('RESV', 'outcome') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($grp['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['ref']) ?></a><?php if ($r['try'] !== null): ?><span class="o-sub"><?= $e($r['try']) ?></span><?php endif; ?></th>
        <td class="c-status doc-st <?= $e($r['tone']) ?>"><?= $e($r['chipWord']) ?></td>
        <td data-label="<?= $word('RESV', 'store') ?>"><?= $e($r['store']) ?></td>
        <td class="num" data-label="<?= $word('RESV', 'products') ?>"><?= $n($r['products']) ?></td>
        <td class="num" data-label="<?= $word('RESV', 'units') ?>"><?= $n($r['units']) ?></td>
        <td data-label="<?= $word('RESV', 'started') ?>"><?= $when($r['started']) ?></td>
        <td data-label="<?= $word('RESV', 'expires') ?>"><?php if ($r['expires'] !== null): ?><?= $when($r['expires']) ?><?php if ($r['overdue']): ?><span class="o-sub"><?= $word('RESV', 'overdue') ?></span><?php endif; ?><?php endif; ?></td>
        <td class="c-wide" data-label="<?= $word('RESV', 'outcome') ?>"><?= $e($r['outcome']) ?><?php if ($r['outcome_at'] !== null): ?><span class="o-sub"><?= $e($r['outcome_at']) ?></span><?php endif; ?><?php if ($r['unlinked'] !== null): ?><span class="o-sub"><?= $e($r['unlinked']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($older_link !== null || $newest_link !== null): ?>
<p class="pager"><?php if ($newest_link !== null): ?><a href="<?= $e($newest_link) ?>"><?= $word('RESV', 'newest') ?></a><?php endif; ?><?php if ($older_link !== null): ?><a href="<?= $e($older_link) ?>"><?= $word('RESV', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
