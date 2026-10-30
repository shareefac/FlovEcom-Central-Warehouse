<?php
// Inventory › Stock counts › Items to count (the approved redesign of 30 Oct 2026, artboard Inventory: Stock counts; docs/decisions.md
// RD-A6): the title, and at its right More (the warehouse and how many to list, the plan's days, Clear) and the warehouse's "Open sheets
// (N)"; one search and ONE Show choice (Items to count, Count sheets, Differences); then what to count next grouped Overdue (red), Never
// counted (grey) and, when the list reaches it, inside the plan (green; app.js folds it at first), each heading with the plan's figure for the
// warehouse; four columns: the tick, the item (its name opens it, its CW number under it), when it was last counted ("—" never), what it
// sells a week. For the people who count the board sits inside the "open a count sheet" form, whose bar at the bottom holds "N ticked"
// (app.js), More (the other ways to count: the next N due, everything found, an empty sheet; the shelf; a note) and the ONE main button
// "Count the ticked items". Enter in a field counts the ticked items (the hidden first button).
// The head's right is rd1's (split: "Open sheets (N)", then ONE More with the list's items) without a create button: the page's ONE main
// action is the bar's (Counts.dc.html; docs/decisions.md RD-I8).
$side = $partial('split', ['actions' => [], 'pages' => [], 'menu' => $bar,
    'extra' => $openSheets > 0 ? ['href' => $openSheetsHref, 'label' => \CW\Ui\Words::say('COUNTS', 'open_sheets', $openSheets)] : null]);
?>
<?= $head(\CW\Ui\Words::INV_LIST['counts'], intro: 'counts', look: $lookOnly, side: $side) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('COUNTS', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('COUNTS', 'find') ?>"></div>
<?php if ($f['warehouse'] !== null): ?>
  <input type="hidden" name="warehouse" value="<?= $e($f['warehouse']) ?>">
<?php endif; ?>
  <input type="hidden" name="show" value="<?= $e($f['show']) ?>">
  <?= $showMine($show) ?>
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
  <button type="submit" class="po-default" name="mode" value="<?= $e(isset($modes['ticked']) ? 'ticked' : (string) array_key_first($modes)) ?>" tabindex="-1" aria-hidden="true"><?= $word('INV_LIST', 'count_ticked') ?></button>
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
  <h3 class="grp-title"><?php if ($g['rows'] !== []): ?><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"<?php if ($g['folded']): ?> data-folded="1"<?php endif; ?>><?= $word('COUNT_GROUP', $g['key']) ?></button><?php else: ?><span class="grp-name"><?= $word('COUNT_GROUP', $g['key']) ?></span><?php endif; ?> <span class="grp-count" title="<?= $e($g['count']) ?>"><?= $n($g['n']) ?></span></h3>
<?php if ($g['rows'] !== []): ?>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list picks count-next">
    <thead>
      <tr>
<?php if ($canCount): ?>
        <th scope="col" class="c-pick"><span class="visually-hidden"><?= $word('COUNTS', 'tick') ?></span></th>
<?php endif; ?>
        <th scope="col" class="c-item"><?= $word('COUNTS', 'item') ?></th>
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
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?></span></th>
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
<?php $main = isset($modes['ticked']) ? 'ticked' : (string) array_key_first($modes); $others = array_diff_key($modes, [$main => true]); ?>
    <details class="more count-more">
      <summary class="btn"><?= $word('INV_LIST', 'more') ?></summary>
      <div class="more-menu">
<?php foreach ($others as $value => $words): ?>
        <button type="submit" class="btn" name="mode" value="<?= $e($value) ?>"><?= $e($words) ?></button>
<?php endforeach; ?>
        <div class="count-fields">
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
        </div>
      </div>
    </details>
    <button type="submit" class="btn primary" name="mode" value="<?= $e($main) ?>"><?php if ($main === 'ticked'): ?><?= $word('INV_LIST', 'count_ticked') ?><?php else: ?><?= $e($modes[$main]) ?><?php endif; ?></button>
  </div>
</form>
<?php endif; ?>
