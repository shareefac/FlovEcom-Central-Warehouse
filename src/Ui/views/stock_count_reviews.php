<?php
// Stock › Counts › Differences (docs/decisions.md CT6-CT9): the count differences to check, as a board: the lines above the tolerance,
// then the two kinds of review the count engine opens by itself. Each row: the item, the sheet, what CW held, what was
// counted, the difference, who counted, why it waits, and the two answers (a note, then accept or reject).
?>
<div class="head-help">
  <h1><?= $word('PAGE_TITLE', 'count_differences') ?></h1>
</div>
<?= $intro('count_differences', $lookOnly) ?>
<p class="note"><?= $e($second) ?> <a href="<?= $e($rulesHref) ?>"><?= $word('COUNTS', 'rules_link') ?></a></p>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($groups === []): ?>
<?= $empty(\CW\Ui\Words::COUNTS['no_differences'], \CW\Ui\Words::COUNTS['no_differences_text'], '/ui/stock/counts/sheets', \CW\Ui\Words::SEGMENT['count_sheets']) ?>
<?php else: ?>
<div class="board-head"><h2 class="board-sub"><?= $word('COUNTS', 'differences_board') ?></h2><p class="board-note"><?php if ($more): ?><?= $say('COUNTS', 'differences_more', $page_size) ?><?php else: ?><?= $say('COUNTS', 'differences_note', $rows_n) ?><?php endif; ?></p></div>
<?php foreach ($groups as $g => $list): ?>
<div class="grp-block">
  <h3 class="grp-title <?= $e($tones[$g]) ?>"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $word('COUNT_REVIEW', $g) ?></button><span class="grp-count"><?php if (count($list) === 1): ?><?= $word('COUNTS', 'group_one') ?><?php else: ?><?= $say('COUNTS', 'group_many', count($list)) ?><?php endif; ?></span></h3>
  <p class="muted"><?= $word('COUNT_REVIEW_HELP', $g) ?></p>
  <div class="table-wrap" id="g-<?= $e($g) ?>">
  <table class="stack list board count-differences <?= $e($tones[$g]) ?>">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('COUNTS', 'item') ?></th>
        <th scope="col"><?= $word('COUNTS', 'sheet') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'held') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'counted') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'difference') ?></th>
        <th scope="col"><?= $word('COUNTS', 'counted_by') ?></th>
        <th scope="col"><?= $word('COUNTS', 'why') ?></th>
        <th scope="col"><?= $word('COUNTS', 'answer') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?> · <?= $e($r['warehouse']) ?></span></th>
        <td data-label="<?= $word('COUNTS', 'sheet') ?>"><?php if ($r['href'] !== null): ?><a href="<?= $u($r['href']) ?>"><?= $e($r['number']) ?></a><?php elseif ($r['number'] !== null): ?><?= $e($r['number']) ?><?php else: ?><span class="muted"><?= $word('COUNTS', 'no_sheet') ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('COUNTS', 'held') ?>"><?php if ($r['held'] !== null): ?><?= $n($r['held']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('COUNTS', 'counted') ?>"><?php if ($r['counted'] !== null): ?><?= $n($r['counted']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('COUNTS', 'difference') ?>"><?php if ($r['delta'] !== null): ?><?= $say('COUNTS', $r['delta'] > 0 ? 'plus' : 'minus', abs($r['delta'])) ?><?php endif; ?></td>
        <td data-label="<?= $word('COUNTS', 'counted_by') ?>"><?= $e($r['counted_by_name']) ?></td>
        <td class="c-wide" data-label="<?= $word('COUNTS', 'why') ?>"><?= $e($r['why']) ?></td>
        <td class="c-next">
<?php if ($r['refusal'] !== null): ?>
          <span class="muted"><?= $e($r['refusal']) ?></span>
<?php else: ?>
          <form class="inline count-answer" method="post" action="<?= $u($r['action']) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <label><?= $word('COUNTS', 'answer_note') ?> <input type="text" name="note" minlength="<?= $e($noteMin) ?>" maxlength="<?= $e($noteMax) ?>" required></label>
            <button type="submit" name="answer" value="accept"><?= $word('COUNTS', 'accept') ?></button>
<?php if ($r['canReject']): ?>
            <button type="submit" name="answer" value="reject" class="danger"><?= $word('COUNTS', 'reject') ?></button>
<?php endif; ?>
          </form>
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
