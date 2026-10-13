<?php
// Stock › Counts › Next to count (docs/decisions.md CT10-CT14): the toolbar (Search, Filter: warehouse and how many rows), the count
// plan's four figures for the warehouse, then what to count next as a board (never counted, overdue, inside the plan). For the people
// who count the board sits inside the "open a count sheet" form: its tick boxes, the place and note, and the four ways to open one.
$newActions = $newActions ?? [];
?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <?= $partial('split', ['actions' => $newActions]) ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('COUNTS', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>" title="<?= $word('COUNTS', 'find') ?>"></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span></summary>
    <div class="pop pop-form">
      <label><?= $word('COUNTS', 'warehouse') ?>
        <select name="warehouse">
<?php foreach ($warehouses as $w): ?>
          <option value="<?= $e($w['id']) ?>"<?php if ($f['warehouse'] === $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('COUNTS', 'show') ?>
        <select name="show">
<?php foreach ($shows as $size): ?>
          <option value="<?= $e($size) ?>"<?php if ($f['show'] === $size): ?> selected<?php endif; ?>><?= $say('COUNTS', 'show_n', $size) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="<?= $e($clear) ?>"><?= $word('COUNTS', 'clear') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
</form>
<div class="head-help">
  <h1><?= $word('MENU', 'counts') ?></h1>
</div>
<?= $intro('counts', $lookOnly) ?>

<section class="home-tiles" aria-label="<?= $word('COUNTS', 'figures') ?>">
  <div class="kpis-wrap"><ul class="kpis four">
    <li class="kpi"><p class="kpi-label"><?= $word('COUNTS', 'tile_items') ?></p><p class="kpi-value"><?= $n($tiles['items']) ?></p><p class="kpi-sub"><?= $say('COUNTS', 'tile_items_sub', $warehouseName) ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('COUNTS', 'tile_inside') ?></p><p class="kpi-value"><?= $n($tiles['inside']) ?></p><p class="kpi-sub"><?= $say('COUNTS', 'tile_inside_sub', $days) ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('COUNTS', 'tile_overdue') ?></p><p class="kpi-value"><?= $n($tiles['overdue']) ?></p><p class="kpi-sub"><?= $say('COUNTS', 'tile_overdue_sub', $days) ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('COUNTS', 'tile_never') ?></p><p class="kpi-value"><?= $n($tiles['never']) ?></p><p class="kpi-sub"><?= $word('COUNTS', 'tile_never_sub') ?></p></li>
  </ul></div>
</section>
<p class="note"><?= $say('COUNTS', 'plan', $days) ?> <a href="<?= $e($settingHref) ?>"><?= $word('COUNTS', 'plan_link') ?></a></p>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<details class="fold how">
  <summary><?= $word('COUNTS', 'how') ?></summary>
  <ol>
<?php foreach (\CW\Ui\Words::COUNT_HOW as $step): ?>
    <li><?= $e($step) ?></li>
<?php endforeach; ?>
  </ol>
</details>

<?php if ($canCount): ?>
<form class="record count-open" id="new" method="post" action="/ui/stock/counts">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <input type="hidden" name="warehouse" value="<?= $e($f['warehouse']) ?>">
  <input type="hidden" name="q" value="<?= $e($f['q']) ?>">
<?php endif; ?>
<?php if ($groups === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::COUNTS['none_found'], \CW\Ui\Words::COUNTS['none_found_text'], $clear, \CW\Ui\Words::COUNTS['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::COUNTS['none'], \CW\Ui\Words::COUNTS['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="board-head"><h2 class="board-title"><?php if ($filtered): ?><?= $say('COUNTS', 'board_found', $f['q']) ?><?php else: ?><?= $word('COUNTS', 'board') ?><?php endif; ?></h2><p class="board-note"><?php if ($filtered): ?><?= $say('COUNTS', 'board_found_note', $rows_n) ?><?php else: ?><?= $say('COUNTS', 'board_note', $rows_n) ?><?php endif; ?></p></div>
<?php foreach ($groups as $g => $list): ?>
<div class="grp-block">
  <h3 class="grp-title <?= $e($tones[$g]) ?>"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $word('COUNT_GROUP', $g) ?></button><span class="grp-count"><?php if (count($list) === 1): ?><?= $word('COUNTS', 'group_one') ?><?php else: ?><?= $say('COUNTS', 'group_many', count($list)) ?><?php endif; ?></span></h3>
  <div class="table-wrap" id="g-<?= $e($g) ?>">
  <table class="stack list board count-next <?= $e($tones[$g]) ?>">
    <thead>
      <tr>
<?php if ($canCount): ?>
        <th scope="col" class="c-status"><span class="visually-hidden"><?= $word('COUNTS', 'tick') ?></span></th>
<?php endif; ?>
        <th scope="col" class="c-item"><?= $word('COUNTS', 'item') ?></th>
        <th scope="col" class="c-status"><?= $word('COUNTS', 'status') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'stock_now') ?></th>
        <th scope="col"><?= $word('COUNTS', 'last_counted') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'sells') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr>
<?php if ($canCount): ?>
        <td class="c-status"><label class="choice"><input type="checkbox" name="pick_<?= $e($r['sku_id']) ?>" value="1"><span class="visually-hidden"><?= $say('COUNTS', 'tick_one', $r['name']) ?></span></label></td>
<?php endif; ?>
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?><?php if ($r['brand'] !== null): ?> · <?= $e($r['brand']) ?><?php endif; ?></span></th>
        <td class="c-status"><?= $chip($tones[$g], \CW\Ui\Words::COUNT_GROUP[$g]) ?></td>
        <td class="num" data-label="<?= $word('COUNTS', 'stock_now') ?>"><?= $n($r['on_hand']) ?></td>
        <td data-label="<?= $word('COUNTS', 'last_counted') ?>"><?php if ($r['counted_at'] === null): ?><span class="muted"><?= $word('COUNTS', 'never') ?></span><?php else: ?><?= $day($r['counted_at']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('COUNTS', 'sells') ?>"><?php if ($r['sells'] === null): ?><span class="muted"><?= $word('COUNTS', 'no_sales') ?></span><?php else: ?><?= $e($r['sells']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<p class="foot"><?= $say('COUNTS', 'foot', $f['show']) ?></p>
<?php endif; ?>
<?php if ($canCount): ?>
  <fieldset class="count-open-how">
    <legend><?= $word('COUNTS', 'open_title') ?></legend>
    <p class="hint"><?= $say('COUNTS', 'open_text', $warehouseName, $maxLines) ?></p>
<?php if ($places !== []): ?>
    <label><?= $word('COUNTS', 'place') ?>
      <select name="location">
        <option value=""><?= $word('COUNTS', 'no_place') ?></option>
<?php foreach ($places as $p): ?>
        <option value="<?= $e($p['id']) ?>"<?php if ($typed['location'] === (string) $p['id']): ?> selected<?php endif; ?>><?= $e($p['name']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php endif; ?>
    <label><?= $word('COUNTS', 'note') ?> <input type="text" name="note" value="<?= $e($typed['note']) ?>" maxlength="200"></label>
    <p class="actions">
<?php if ($groups !== []): ?>
      <button type="submit" name="mode" value="ticked" class="primary"><?= $word('COUNTS', 'open_ticked') ?></button>
<?php if ($filtered): ?>
      <button type="submit" name="mode" value="found"><?= $say('COUNTS', 'open_found', $rows_n) ?></button>
<?php else: ?>
      <label class="inline-field"><?= $word('COUNTS', 'open_next_n') ?> <input type="text" name="n" value="<?= $e($typed['n']) ?>" inputmode="numeric" maxlength="3" size="4"></label>
      <button type="submit" name="mode" value="next"><?= $word('COUNTS', 'open_next') ?></button>
<?php endif; ?>
<?php endif; ?>
      <button type="submit" name="mode" value="empty"><?= $word('COUNTS', 'open_empty') ?></button>
    </p>
  </fieldset>
</form>
<?php endif; ?>
