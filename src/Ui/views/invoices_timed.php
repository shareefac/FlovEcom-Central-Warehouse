<?php
// Purchasing › Invoices › Timed, a board (docs/decisions.md W9.8): the draft invoices waiting for their time, those that could not be made
// final first; each group a coloured heading with its count over a card with its colour on the left edge, the status cell filled (the
// reason under it). A row opens its invoice.
?>
<?= $head(\CW\Ui\Words::SEGMENT['timed'], intro: 'timed') ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::ARRIVALS['timed_none'], \CW\Ui\Words::ARRIVALS['timed_none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('ARRIVALS', 'group_one') ?><?php else: ?><?= $say('ARRIVALS', 'group_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('ARRIVALS', 'invoice') ?></th>
        <th scope="col" class="c-status"><?= $word('ARRIVALS', 'timed_state') ?></th>
        <th scope="col"><?= $word('ARRIVALS', 'supplier') ?></th>
        <th scope="col"><?= $word('ARRIVALS', 'timed_at') ?></th>
        <th scope="col"><?= $word('ARRIVALS', 'timed_by') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['external_ref'] ?? \CW\Ui\Words::STOCK_OPS['no_number']) ?></a><span class="o-sub"><?= $e(\CW\Ui\Words::RECEIVE_MODE[$r['receive_mode']] ?? '') ?></span></th>
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><span class="chip"><?= $e($g['name']) ?></span><?php if ($r['failed']): ?><span class="o-sub"><?= $e($r['submit_error'] ?? '') ?></span><?php endif; ?></td>
        <td data-label="<?= $word('ARRIVALS', 'supplier') ?>"><?= $e($r['supplier_name']) ?></td>
        <td data-label="<?= $word('ARRIVALS', 'timed_at') ?>"><?= $e($r['at']) ?></td>
        <td data-label="<?= $word('ARRIVALS', 'timed_by') ?>"><?= $e($r['submit_by_name'] ?? '') ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
