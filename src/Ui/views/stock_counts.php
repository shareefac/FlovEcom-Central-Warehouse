<?php
// Stock › Counts › Next to count (docs/decisions.md CT10-CT14; the approved design CT_Board of 29 Oct 2026: CV1-CV4). One search, the
// warehouse and the Show choice; then what to count next as a board (the shared one: .doc-group, .doc-board, td.doc-st): overdue (red),
// never counted (purple), inside the plan (green; app.js folds it at first, without the script it is open), each heading with the plan's
// figure for the warehouse. A row: its tick box, the item and its code, its status filled solid, when it was last counted ("—" never), what
// it sells. How many the list takes (the next N), the plan's days and Clear are under the toolbar's More (DC20). For the people who count
// the board sits inside the "open a count sheet" form, whose bar at the bottom (.doc-bar) holds "N ticked" (app.js), the one
// Count menu (the ticked items, the next N due, everything found, an empty sheet), the shelf, a note and the one button. The head's right
// links the warehouse's open sheets.
?>
<?= $head(\CW\Ui\Words::MENU['counts'], intro: 'counts', look: $lookOnly,
    side: $openSheets > 0 ? '<a class="btn" href="' . $e($openSheetsHref) . '">' . $say('COUNTS', 'open_sheets', $openSheets) . '</a>' : '') ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('COUNTS', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('COUNTS', 'find') ?>"></div>
  <div class="tb-state">
    <label for="tb-warehouse"><?= $word('COUNTS', 'warehouse') ?></label>
    <select id="tb-warehouse" name="warehouse">
<?php foreach ($warehouses as $w): ?>
      <option value="<?= $e($w['id']) ?>"<?php if ($f['warehouse'] === $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
    </select>
    <input type="hidden" name="show" value="<?= $e($f['show']) ?>">
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
    <details class="more tb-more">
      <summary class="btn ghost sm"><?= $word('UI', 'more') ?></summary>
      <div class="more-menu">
<?php foreach ($shows as $size): ?>
<?php if ($size !== $f['show']): ?>
        <a class="menu-item" href="<?= $u('/ui/stock/counts', ['warehouse' => $f['warehouse'], 'q' => $f['q'] === '' ? null : $f['q'], 'show' => $size]) ?>"><?= $say('COUNTS', 'show_n_link', $size) ?></a>
<?php endif; ?>
<?php endforeach; ?>
        <a class="menu-item" href="<?= $e($settingHref) ?>"><span class="menu-name"><?= $word('COUNTS', 'plan_link') ?></span><span class="menu-sub"><?= $say('COUNTS', 'plan', $days) ?></span></a>
<?php if ($filtered): ?>
        <a class="menu-item" href="<?= $e($clear) ?>"><?= $word('COUNTS', 'clear') ?></a>
<?php endif; ?>
      </div>
    </details>
  </div>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($canCount): ?>
<form class="count-open" id="new" method="post" action="/ui/stock/counts">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="warehouse" value="<?= $e($f['warehouse']) ?>">
  <input type="hidden" name="q" value="<?= $e($f['q']) ?>">
  <input type="hidden" name="n" value="<?= $e($f['show']) ?>">
<?php endif; ?>
<?php if ($groups === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::COUNTS['none_found'], \CW\Ui\Words::COUNTS['none_found_text'], $clear, \CW\Ui\Words::COUNTS['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::COUNTS['none'], \CW\Ui\Words::COUNTS['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php if ($filtered): ?>
<div class="board-head"><h2 class="board-sub"><?= $say('COUNTS', 'board_found', $f['q']) ?></h2><p class="board-note"><?= $say('COUNTS', 'board_found_note', $rows_n) ?></p></div>
<?php endif; ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>" data-group="<?= $e($g['key']) ?>">
  <h3 class="grp-title"><?php if ($g['rows'] !== []): ?><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"<?php if ($g['folded']): ?> data-folded="1"<?php endif; ?>><?= $word('COUNT_GROUP', $g['key']) ?></button><?php else: ?><span class="grp-name"><?= $word('COUNT_GROUP', $g['key']) ?></span><?php endif; ?> <span class="grp-count"><?= $e($g['count']) ?></span></h3>
<?php if ($g['rows'] !== []): ?>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list picks count-next">
    <thead>
      <tr>
<?php if ($canCount): ?>
        <th scope="col" class="c-pick"><span class="visually-hidden"><?= $word('COUNTS', 'tick') ?></span></th>
<?php endif; ?>
        <th scope="col" class="c-item"><?= $word('COUNTS', 'item') ?></th>
        <th scope="col" class="c-status"><?= $word('COUNTS', 'status') ?></th>
        <th scope="col"><?= $word('COUNTS', 'last_counted') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'sells') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr<?php if ($r['ticked']): ?> class="picked"<?php endif; ?>>
<?php if ($canCount): ?>
        <td class="c-pick" data-label=""><label class="pick"><input type="checkbox" name="pick_<?= $e($r['sku_id']) ?>" value="1" data-tick<?php if ($r['ticked']): ?> checked<?php endif; ?>><span class="visually-hidden"><?= $say('COUNTS', 'tick_one', $r['name']) ?></span></label></td>
<?php endif; ?>
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a> <span class="muted"><?= $e($r['code']) ?></span></th>
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><span class="chip"><?= $word('COUNT_GROUP', $r['state']) ?></span></td>
        <td data-label="<?= $word('COUNTS', 'last_counted') ?>"><?php if ($r['counted_at'] === null): ?><span class="muted"><?= $word('DOC_LIST', 'none') ?></span><span class="visually-hidden"> <?= $word('COUNTS', 'never') ?></span><?php else: ?><?= $day($r['counted_at']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('COUNTS', 'sells') ?>"><?php if ($r['sells'] === null): ?><span class="muted"><?= $word('COUNTS', 'no_sales') ?></span><?php else: ?><?= $e($r['sells']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($canCount): ?>
  <div class="doc-bar count-bar">
    <span class="count-ticked" data-ticked data-words="<?= $word('COUNTS', 'ticked') ?>" hidden><?= $say('COUNTS', 'ticked', $ticked_n) ?></span>
    <label class="count-field"><span><?= $word('COUNTS', 'count_menu') ?></span>
      <select name="mode">
<?php foreach ($modes as $value => $words): ?>
        <option value="<?= $e($value) ?>"<?php if ($value === $mode): ?> selected<?php endif; ?>><?= $e($words) ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php if ($places !== []): ?>
    <label class="count-field"><span><?= $word('COUNTS', 'shelf') ?></span>
      <select name="location">
        <option value=""><?= $word('COUNTS', 'no_place') ?></option>
<?php foreach ($places as $p): ?>
        <option value="<?= $e($p['id']) ?>"<?php if ($typed['location'] === (string) $p['id']): ?> selected<?php endif; ?>><?= $e($p['name']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php endif; ?>
    <label class="count-field count-note"><span><?= $word('COUNTS', 'note') ?></span><input type="text" name="note" value="<?= $e($typed['note']) ?>" maxlength="200"></label>
    <button type="submit" class="btn primary"><?= $word('COUNTS', 'open_button') ?></button>
  </div>
</form>
<?php endif; ?>
