<?php
// "Before you make it final" (docs/decisions.md CT5, CT6; the approved design CT_Final of 29 Oct 2026: CV9, CV10): the strip (counted,
// the same as CW's figure, different, a reviewer checks), then the counted lines of an open sheet as a board (the shared .doc-group,
// .doc-board, td.doc-st): the ones that differ or need an answer, the biggest difference first, and the ones the same as CW's figure
// (app.js folds them at first). A line: the item (what moved after it was counted, what keeping the count does, the limits it is above),
// counted, what CW holds now, the difference, what making it final does (the solid status cell: booked as counted, a reviewer
// checks it, left out; red when it stops the making final: count again, stock moved), and "Count again" (every line) and "Keep my count"
// (stock moved: the module plan's decision 6, warn first). When the sheet needs more count times than a record holds, the lines to count
// again are marked and one button empties them all (review M-2). The bar at the bottom: back to the sheet, and ONE "Make the count final"
// (switched off, with the reason beside it, until each marked line is answered).
?>
<p class="crumbs"><a href="<?= $u($base) ?>"><?= $word('COUNTS', 'back_to_sheet') ?></a></p>
<?= $head((string) $title, intro: 'count_before', look: $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($lines === []): ?>
<?= $empty(\CW\Ui\Words::COUNTS['nothing_counted'], \CW\Ui\Words::COUNTS['nothing_counted_text'], $base, \CW\Ui\Words::COUNTS['back_to_sheet']) ?>
<?php else: ?>
<section class="doc-progress count-steps" aria-label="<?= $word('COUNTS', 'summary') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('COUNTS', 'step_counted') ?></p><p class="doc-step-big"><?= $n($counted_n) ?></p><p class="muted"><?= $say('COUNTS', 'step_counted_sub', $lines_n) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('COUNTS', 'step_same') ?></p><p class="doc-step-big done"><?= $n($steps['same']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('COUNTS', 'step_different') ?></p><p class="doc-step-big needs"><?= $n($steps['different']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('COUNTS', 'step_reviewer') ?></p><p class="doc-step-big"><?= $n($steps['reviewer']) ?></p></div>
</section>
<?php if ($retimeWords !== null): ?>
<p class="error" role="alert"><?= $e($retimeWords) ?></p>
<?php if ($mine && $retime_n > 0): ?>
<form class="inline" method="post" action="<?= $u($base . '/recount') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="times" value="1">
  <button type="submit" class="btn"><?= $word('COUNTS', 'retime_all') ?></button>
</form>
<?php endif; ?>
<?php endif; ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>" data-group="<?= $e($g['key']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"<?php if ($g['folded']): ?> data-folded="1"<?php endif; ?>><?= $word('COUNTS', 'group_' . $g['key']) ?></button> <span class="grp-count"><?= $e($g['count']) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list count-before">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('COUNTS', 'item') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'counted') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'col_system') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'difference') ?></th>
        <th scope="col" class="c-status"><?= $word('COUNTS', 'what_happens') ?></th>
        <th scope="col"><span class="visually-hidden"><?= $word('COUNTS', 'answer') ?></span></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $l): ?>
      <tr id="line-<?= $e($l['line_no']) ?>"<?php if ($l['retime']): ?> class="retime"<?php endif; ?>>
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><span class="o-sub"><?= $say('COUNTS', 'line_no', $l['line_no']) ?> · <?= $e($l['code']) ?> · <?= $say('COUNTS', 'counted_when', \CW\Ui\Html::when($l['counted_at'])) ?></span>
<?php if ($l['over'] !== []): ?>
          <span class="o-sub"><?= $e($l['why']) ?></span>
<?php endif; ?>
<?php if ($l['too_old']): ?>
          <span class="o-sub"><?= $say('COUNTS', 'too_old_text', $days) ?></span>
<?php endif; ?>
<?php if ($l['movedWords'] !== []): ?>
          <span class="o-sub"><?= $word('COUNTS', 'moved_title') ?></span>
<?php foreach ($l['movedWords'] as $m): ?>
          <span class="o-sub"><?= $e($m) ?></span>
<?php endforeach; ?>
<?php if ($l['movedMore'] > 0): ?>
          <span class="o-sub"><?= $say('COUNTS', 'moved_more', $l['movedMore']) ?></span>
<?php endif; ?>
<?php endif; ?>
<?php if ($l['does'] !== null): ?>
          <span class="o-sub"><?= $e($l['does']) ?></span>
<?php endif; ?>
        </th>
        <td class="num" data-label="<?= $word('COUNTS', 'counted') ?>"><?= $n($l['counted']) ?></td>
        <td class="num" data-label="<?= $word('COUNTS', 'col_system') ?>"><?= $n($l['held']) ?></td>
        <td class="num" data-label="<?= $word('COUNTS', 'difference') ?>"><?php if ($l['delta'] === null): ?><span class="muted"><?= $word('COUNTS', 'left_out') ?></span><?php elseif ($l['delta'] === 0): ?><?= $word('COUNTS', 'no_difference') ?><?php else: ?><?= $say('COUNTS', $l['delta'] > 0 ? 'plus' : 'minus', abs($l['delta'])) ?><?php endif; ?></td>
        <td class="c-status doc-st <?= $e($l['st_tone']) ?>"><?= $e($l['st_word']) ?><?php if ($l['acked']): ?><span class="o-sub"><?= $word('COUNTS', 'as_counted_chip') ?></span><?php endif; ?></td>
        <td class="c-next" data-label="">
<?php if ($mine): ?>
          <form class="inline" method="post" action="<?= $u($base . '/recount') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="line" value="<?= $e($l['line_no']) ?>">
            <button class="btn sm" type="submit"><?= $word('COUNTS', 'recount') ?></button>
          </form>
<?php if ($l['needs_choice']): ?>
          <form class="inline" method="post" action="<?= $u($base . '/as-counted') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="line" value="<?= $e($l['line_no']) ?>">
            <input type="hidden" name="seen" value="<?= $e($l['moved']['max_id']) ?>">
            <button class="btn ghost sm" type="submit"><?= $word('COUNTS', 'as_counted') ?></button>
          </form>
<?php endif; ?>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($canPost): ?>
<form class="doc-bar count-bar" method="post" action="<?= $u($base . '/post') ?>">
  <a class="btn ghost" href="<?= $u($base) ?>"><?= $word('COUNTS', 'back_to_sheet') ?></a>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($version) ?>">
  <button type="submit" class="btn primary"><?= $word('COUNTS', 'make_final_button') ?></button>
</form>
<?php else: ?>
<div class="doc-bar count-bar">
  <a class="btn ghost" href="<?= $u($base) ?>"><?= $word('COUNTS', 'back_to_sheet') ?></a>
<?php if ($mine && $blocked): ?>
  <span class="btn-why"><?= $word('COUNTS', 'make_final_blocked') ?></span>
  <button type="button" class="btn primary" disabled><?= $word('COUNTS', 'make_final_button') ?></button>
<?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
