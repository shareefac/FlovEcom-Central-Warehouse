<?php
// Inventory › Stock counts › Count sheets (docs/decisions.md CT14; the approved redesign of 30 Oct 2026, artboard Inventory: Stock counts,
// RD-A6): every count record, newest first, grouped by status (open sheets, final ones, cancelled ones, the records that put a count back,
// stopped ones); one search and the ONE Show choice of Stock counts; the Filter of before (the state, the warehouse), Clear and the download
// under the head's More; four columns: the sheet (its date under it, and what waits on it), where, how many lines are counted (the units it
// changed), who opened it. Older pages by id.
?>
<?= $head(\CW\Ui\Words::INV_LIST['counts'], intro: 'count_sheets', side: $partial('rda_bar', $bar)) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('COUNTS', 'find_sheet') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('COUNTS', 'find_sheet') ?>"></div>
<?php if ($f['state'] !== null): ?>
  <input type="hidden" name="state" value="<?= $e($f['state']) ?>">
<?php endif; ?>
<?php if ($f['warehouse'] !== null): ?>
  <input type="hidden" name="warehouse" value="<?= $e($f['warehouse']) ?>">
<?php endif; ?>
  <?= $partial('rda_show', ['choices' => $show, 'label' => \CW\Ui\Words::INV_LIST['show']]) ?>
</form>
<?php if ($byItem): ?>
<p class="muted"><?= $word('COUNTS', 'by_item') ?></p>
<?php endif; ?>

<?php if ($groups === []): ?>
<?php if ($narrowed): ?>
<?= $empty(\CW\Ui\Words::COUNTS['no_sheet_found'], \CW\Ui\Words::COUNTS['no_sheet_found_text'], '/ui/stock/counts/sheets', \CW\Ui\Words::COUNTS['clear_sheets']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::COUNTS['no_sheets'], \CW\Ui\Words::COUNTS['no_sheets_text'], '/ui/stock/counts', \CW\Ui\Words::SEGMENT['counts']) ?>
<?php endif; ?>
<?php else: ?>
<?php foreach ($groups as $g => $list): ?>
<?php $tone = $list[0]['tone']; ?>
<div class="grp-block doc-group <?= $e($tone) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $word('COUNT_SHEET_GROUP', $g) ?></button> <span class="grp-count"><?= $n(count($list)) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g) ?>">
  <table class="stack list board doc-list count-sheets">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('INV_LIST', 'col_sheet') ?></th>
        <th scope="col"><?= $word('INV_LIST', 'col_where') ?></th>
        <th scope="col" class="num"><?= $word('INV_LIST', 'col_lines') ?></th>
        <th scope="col"><?= $word('INV_LIST', 'col_opened_by') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['number_line']) ?></a><span class="o-sub"><?= $e($r['date_line']) ?></span><?php if ($r['open'] > 0): ?><span class="o-sub"><span class="tag needs"><?= $e($r['next']) ?></span></span><?php endif; ?></th>
        <td data-label="<?= $word('INV_LIST', 'col_where') ?>"><?= $e($r['where']) ?></td>
        <td class="num" data-label="<?= $word('INV_LIST', 'col_lines') ?>"><?php if ($r['isSheet']): ?><?= $say('COUNTS', 'counted_of', $r['counted'], $r['lines']) ?><?php if ($r['changed'] > 0): ?><span class="o-sub"><?= $say('INV_LIST', 'changed_units', $r['changed']) ?></span><?php endif; ?><?php endif; ?></td>
        <td data-label="<?= $word('INV_LIST', 'col_opened_by') ?>"><?= $e($r['made_by']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($older_link !== null || $newest_link !== null): ?>
<p class="pager"><?php if ($newest_link !== null): ?><a href="<?= $e($newest_link) ?>"><?= $word('COUNTS', 'newest') ?></a><?php endif; ?><?php if ($older_link !== null): ?><a href="<?= $e($older_link) ?>"><?= $word('COUNTS', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
