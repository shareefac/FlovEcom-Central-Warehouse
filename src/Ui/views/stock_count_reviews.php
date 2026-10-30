<?php
// Inventory › Stock counts › Differences (docs/decisions.md CT6-CT9; the approved redesign of 30 Oct 2026, artboard Inventory: Stock counts,
// RD-A6): the count differences to check: the lines above the tolerance, then the two kinds of review the count engine opens by itself,
// then the flags about a count's time (accept only); the ONE Show choice of Stock counts; the approval rules under the head's More. Five
// columns: the item (its CW number, warehouse and sheet under it), the difference (what CW held and what was counted under it), who
// counted, why it waits, and the two answers (a note, then accept or reject). A difference of an item counted again since says so instead
// of the reject button (review C-1).
?>
<?= $head(\CW\Ui\Words::INV_LIST['counts'], intro: 'count_differences', look: $lookOnly, side: $partial('rda_bar', $bar)) ?>
<div class="toolbar">
  <?= $partial('rda_show', ['choices' => $show, 'label' => \CW\Ui\Words::INV_LIST['show']]) ?>
</div>
<p class="note"><?= $e($second) ?></p>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($groups === []): ?>
<?= $empty(\CW\Ui\Words::COUNTS['no_differences'], \CW\Ui\Words::COUNTS['no_differences_text'], '/ui/stock/counts/sheets', \CW\Ui\Words::SEGMENT['count_sheets']) ?>
<?php else: ?>
<?php if ($more): ?>
<p class="muted"><?= $say('COUNTS', 'differences_more', $page_size) ?></p>
<?php endif; ?>
<?php foreach ($groups as $g => $list): ?>
<div class="grp-block doc-group <?= $e($tones[$g]) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $word('COUNT_REVIEW', $g) ?></button> <span class="grp-count"><?= $n(count($list)) ?></span></h3>
  <p class="muted"><?= $word('COUNT_REVIEW_HELP', $g) ?></p>
  <div class="table-wrap doc-board" id="g-<?= $e($g) ?>">
  <table class="stack list board doc-list count-differences">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('COUNTS', 'item') ?></th>
        <th scope="col" class="num"><?= $word('INV_LIST', 'col_difference') ?></th>
        <th scope="col"><?= $word('INV_LIST', 'col_counted_by') ?></th>
        <th scope="col"><?= $word('INV_LIST', 'col_why') ?></th>
        <th scope="col"><?= $word('INV_LIST', 'col_answer') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?> · <?= $e($r['warehouse']) ?></span><span class="o-sub"><?php if ($r['href'] !== null): ?><a href="<?= $u($r['href']) ?>"><?= $e($r['number']) ?></a><?php elseif ($r['number'] !== null): ?><?= $e($r['number']) ?><?php else: ?><?= $word('COUNTS', 'no_sheet') ?><?php endif; ?></span></th>
        <td class="num" data-label="<?= $word('INV_LIST', 'col_difference') ?>"><?php if ($r['delta'] !== null): ?><?= $say('COUNTS', $r['delta'] > 0 ? 'plus' : 'minus', abs($r['delta'])) ?><?php endif; ?><?php if ($r['held'] !== null && $r['counted'] !== null): ?><span class="o-sub"><?= $say('INV_LIST', 'held_counted', $r['held'], $r['counted']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('INV_LIST', 'col_counted_by') ?>"><?= $e($r['counted_by_name']) ?></td>
        <td class="c-wide" data-label="<?= $word('INV_LIST', 'col_why') ?>"><?= $e($r['why']) ?></td>
        <td class="c-next">
<?php if ($r['refusal'] !== null): ?>
          <span class="muted"><?= $e($r['refusal']) ?></span>
<?php else: ?>
          <form class="inline count-answer" method="post" action="<?= $u($r['action']) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <label><?= $word('COUNTS', 'answer_note') ?> <input type="text" name="note" minlength="<?= $e($noteMin) ?>" maxlength="<?= $e($noteMax) ?>" required></label>
            <button class="btn" type="submit" name="answer" value="accept"><?= $word('COUNTS', 'accept') ?></button>
<?php if ($r['canReject']): ?>
            <button type="submit" name="answer" value="reject" class="btn risky"><?= $word('COUNTS', 'reject') ?></button>
<?php endif; ?>
          </form>
<?php if ($r['counted_again']): ?>
          <span class="o-sub"><?= $word('COUNTS', 'counted_again') ?></span>
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
<p class="foot"><?= $word('COUNTS', 'differences_foot') ?></p>
<?php endif; ?>
