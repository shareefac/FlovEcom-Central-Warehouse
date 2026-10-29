<?php
// "Before you make it final" (docs/decisions.md CT5, CT6): every counted line of an open sheet with what making it final would do
// now, the biggest difference first. A line whose stock moved after it was counted says what moved and asks for one of two answers
// (the module plan's decision 6: warn first); a line a later count leaves out says so. When the sheet needs more count times than a
// record holds, the lines to count again are marked, each with "Count it again", and one button empties them all (review M-2). The
// button at the bottom makes the sheet final.
?>
<p class="crumbs"><a href="<?= $e($base) ?>"><?= $word('COUNTS', 'back_to_sheet') ?></a></p>
<?= $head((string) $title, intro: 'count_before', look: $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<section class="home-tiles" aria-label="<?= $word('COUNTS', 'figures') ?>">
  <div class="kpis-wrap"><ul class="kpis four">
    <li class="kpi"><p class="kpi-label"><?= $word('COUNTS', 'tile_counted') ?></p><p class="kpi-value"><?= $n($counted_n) ?></p><p class="kpi-sub"><?= $say('COUNTS', 'tile_counted_sub', $lines_n) ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('COUNTS', 'tile_choice') ?></p><p class="kpi-value"><?= $n($diff['needs_choice']) ?></p><p class="kpi-sub"><?= $word('COUNTS', 'tile_choice_sub') ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('COUNTS', 'tile_over') ?></p><p class="kpi-value"><?= $n($diff['over']) ?></p><p class="kpi-sub"><?= $word('COUNTS', 'tile_over_sub') ?></p></li>
    <li class="kpi"><p class="kpi-label"><?= $word('COUNTS', 'tile_stale') ?></p><p class="kpi-value"><?= $n($diff['stale']) ?></p><p class="kpi-sub"><?= $word('COUNTS', 'tile_stale_sub') ?></p></li>
  </ul></div>
</section>
<?php if ($lines === []): ?>
<?= $empty(\CW\Ui\Words::COUNTS['nothing_counted'], \CW\Ui\Words::COUNTS['nothing_counted_text'], $base, \CW\Ui\Words::COUNTS['back_to_sheet']) ?>
<?php else: ?>
<?php if ($retimeWords !== null): ?>
<p class="error" role="alert"><?= $e($retimeWords) ?></p>
<?php if ($mine && $retime_n > 0): ?>
<form class="inline" method="post" action="<?= $u($base . '/recount') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="times" value="1">
  <button type="submit" class="btn"><?= $word('COUNTS', 'retime_all') ?></button> <span class="muted"><?= $word('COUNTS', 'retime_all_text') ?></span>
</form>
<?php endif; ?>
<?php endif; ?>
<div class="table-wrap">
<table class="stack list count-before">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('COUNTS', 'item') ?></th>
      <th scope="col" class="num"><?= $word('COUNTS', 'counted') ?></th>
      <th scope="col" class="num"><?= $word('COUNTS', 'holds_now') ?></th>
      <th scope="col" class="num"><?= $word('COUNTS', 'difference') ?></th>
      <th scope="col"><?= $word('COUNTS', 'what_happens') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($lines as $l): ?>
    <tr id="line-<?= $e($l['line_no']) ?>"<?php if ($l['needs_choice'] || $l['retime']): ?> class="needs<?php if ($l['retime']): ?> retime<?php endif; ?>"<?php endif; ?>>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><span class="o-sub"><?= $say('COUNTS', 'line_no', $l['line_no']) ?> · <?= $e($l['code']) ?> · <?= $say('COUNTS', 'counted_when', \CW\Ui\Html::when($l['counted_at'])) ?></span></th>
      <td class="num" data-label="<?= $word('COUNTS', 'counted') ?>"><?= $n($l['counted']) ?></td>
      <td class="num" data-label="<?= $word('COUNTS', 'holds_now') ?>"><?= $n($l['held']) ?></td>
      <td class="num" data-label="<?= $word('COUNTS', 'difference') ?>"><?php if ($l['delta'] === null): ?><span class="muted"><?= $word('COUNTS', 'left_out') ?></span><?php elseif ($l['delta'] === 0): ?><?= $word('COUNTS', 'no_difference') ?><?php else: ?><?= $say('COUNTS', $l['delta'] > 0 ? 'plus' : 'minus', abs($l['delta'])) ?><?php endif; ?></td>
      <td class="c-wide" data-label="<?= $word('COUNTS', 'what_happens') ?>">
<?php if ($l['over'] !== []): ?>
        <?= $chip('needs', \CW\Ui\Words::COUNTS['over_chip']) ?> <span class="o-sub"><?= $e($l['why']) ?></span>
<?php endif; ?>
<?php if ($l['too_old']): ?>
        <?= $chip('blocked', \CW\Ui\Words::COUNTS['too_old_chip']) ?> <span class="o-sub"><?= $say('COUNTS', 'too_old_text', $days) ?></span>
<?php endif; ?>
<?php if ($l['retime']): ?>
        <?= $chip('blocked', \CW\Ui\Words::COUNTS['retime_chip']) ?>
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
<?php if ($l['acked']): ?>
        <?= $chip('done', \CW\Ui\Words::COUNTS['as_counted_chip']) ?>
<?php endif; ?>
<?php if ($mine && ($l['needs_choice'] || $l['acked'] || $l['stale'] || $l['too_old'] || $l['retime'])): ?>
        <form class="inline" method="post" action="<?= $u($base . '/recount') ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="line" value="<?= $e($l['line_no']) ?>">
          <button class="btn" type="submit"><?= $word('COUNTS', 'recount') ?></button>
        </form>
<?php endif; ?>
<?php if ($mine && $l['needs_choice']): ?>
        <form class="inline" method="post" action="<?= $u($base . '/as-counted') ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="line" value="<?= $e($l['line_no']) ?>">
          <input type="hidden" name="seen" value="<?= $e($l['moved']['max_id']) ?>">
          <button class="btn" type="submit"><?= $word('COUNTS', 'as_counted') ?></button>
        </form>
<?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<section class="card final-box" aria-labelledby="final-h">
  <h2 id="final-h"><?= $word('COUNTS', 'make_final_title') ?></h2>
  <p><?= $word('COUNTS', 'make_final_text') ?></p>
<?php if ($canPost): ?>
  <form class="inline" method="post" action="<?= $u($base . '/post') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <button type="submit" class="btn primary"><?= $word('COUNTS', 'make_final_button') ?></button>
  </form>
<?php elseif ($mine && $blocked): ?>
  <p class="note"><?= $word('COUNTS', 'make_final_blocked') ?></p>
<?php endif; ?>
</section>
<?php endif; ?>
