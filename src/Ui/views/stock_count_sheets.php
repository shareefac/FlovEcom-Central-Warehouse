<?php
// Stock › Counts › Count sheets (docs/decisions.md CT14): every count record, newest first, as a board grouped by status: open
// sheets, final ones, cancelled ones, the records that put a count back, stopped ones. Search, Filter, Export; older pages by id.
?>
<?= $head(\CW\Ui\Words::SEGMENT['count_sheets'], intro: 'count_sheets') ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('COUNTS', 'find_sheet') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>" title="<?= $word('COUNTS', 'find_sheet') ?>"></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($filtered): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
      <label><?= $word('COUNTS', 'state') ?>
        <select name="state">
          <option value=""><?= $word('COUNTS', 'any_state') ?></option>
<?php foreach ($states as $code => $label): ?>
          <option value="<?= $e($code) ?>"<?php if ($f['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('COUNTS', 'warehouse') ?>
        <select name="warehouse">
          <option value=""><?= $word('COUNTS', 'any_warehouse') ?></option>
<?php foreach ($warehouses as $w): ?>
          <option value="<?= $e($w['id']) ?>"<?php if ($f['warehouse'] === $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="/ui/stock/counts/sheets"><?= $word('COUNTS', 'clear_sheets') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
<?php if ($rows_n > 0): ?>
  <div class="tb-end"><a class="btn ghost sm" href="<?= $e($csv) ?>" title="<?= $word('COUNTS', 'download_list') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a></div>
<?php endif; ?>
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
<div class="board-head"><h2 class="board-sub"><?= $word('COUNTS', 'sheets_board') ?></h2><p class="board-note"><?= $say('COUNTS', 'sheets_note', $page_size) ?></p></div>
<?php foreach ($groups as $g => $list): ?>
<?php $tone = $list[0]['tone']; ?>
<div class="grp-block">
  <h3 class="grp-title <?= $e($tone) ?>"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $word('COUNT_SHEET_GROUP', $g) ?></button><span class="grp-count"><?php if (count($list) === 1): ?><?= $word('COUNTS', 'sheets_one') ?><?php else: ?><?= $say('COUNTS', 'sheets_many', count($list)) ?><?php endif; ?></span></h3>
  <div class="table-wrap" id="g-<?= $e($g) ?>">
  <table class="stack list board count-sheets <?= $e($tone) ?>">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('COUNTS', 'sheet') ?></th>
        <th scope="col"><?= $word('COUNTS', 'where') ?></th>
        <th scope="col" class="c-status"><?= $word('COUNTS', 'status') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'lines') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'changed') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'waiting') ?></th>
        <th scope="col"><?= $word('COUNTS', 'opened_by') ?></th>
        <th scope="col"><?= $word('COUNTS', 'next') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr class="<?= $e($r['tone']) ?>">
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['number_line']) ?></a><span class="o-sub"><?= $e($r['date_line']) ?></span></th>
        <td data-label="<?= $word('COUNTS', 'where') ?>"><?= $e($r['where']) ?></td>
        <td class="c-status"><?= $chip($r['tone'], $r['state_word']) ?></td>
        <td class="num" data-label="<?= $word('COUNTS', 'lines') ?>"><?php if ($r['isSheet']): ?><?= $say('COUNTS', 'counted_of', $r['counted'], $r['lines']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('COUNTS', 'changed') ?>"><?php if ($r['isSheet']): ?><?= $n($r['changed']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('COUNTS', 'waiting') ?>"><?php if ($r['open'] > 0): ?><?= $n($r['open']) ?><?php endif; ?></td>
        <td data-label="<?= $word('COUNTS', 'opened_by') ?>"><?= $e($r['made_by']) ?></td>
        <td class="c-wide" data-label="<?= $word('COUNTS', 'next') ?>"><?= $e($r['next']) ?></td>
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
