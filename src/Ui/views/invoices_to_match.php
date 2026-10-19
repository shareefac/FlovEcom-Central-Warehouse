<?= $head(\CW\Ui\Words::SEGMENT['to_match'], intro: 'to_match') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<p class="muted"><?= $e($rules) ?> <a href="/ui/reference/settings"><?= $word('INVOICES', 'rules_link') ?></a></p>
<p>
<?php if ($show === 'accepted'): ?>
  <a href="<?= $u('/ui/purchasing/invoices/to-match') ?>"><?= $word('INVOICES', 'show_to_check') ?></a>
<?php else: ?>
  <a href="<?= $u('/ui/purchasing/invoices/to-match', ['show' => 'accepted']) ?>"><?= $word('INVOICES', 'show_accepted') ?></a>
<?php endif; ?>
</p>
<?php if ($canRecheck): ?>
<form class="inline" method="post" action="/ui/purchasing/invoices/to-match/recheck">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <button type="submit"><?= $word('INVOICES', 'recheck') ?></button> <span class="hint"><?= $word('INVOICES', 'recheck_hint') ?></span>
</form>
<?php endif; ?>

<?php if ($rows === []): ?>
<?php if ($show === 'accepted'): ?>
<?= $empty(\CW\Ui\Words::INVOICES['none_accepted'], '') ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::INVOICES['none_to_match'], \CW\Ui\Words::INVOICES['none_to_match_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list board">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('INVOICES', 'record') ?></th>
      <th scope="col"><?= $word('INVOICES', 'supplier') ?></th>
      <th scope="col"><?= $word('INVOICES', 'posted') ?></th>
      <th scope="col" class="num"><?= $word('INVOICES', 'lines_off') ?></th>
      <th scope="col" class="num"><?= $word('INVOICES', 'difference') ?></th>
<?php if ($show === 'accepted'): ?>
      <th scope="col"><?= $word('INVOICES', 'accepted_by') ?></th>
<?php endif; ?>
      <th scope="col" class="c-status"><?= $word('INVOICES', 'match') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['number']) ?></a><span class="o-sub"><?= $e($r['external_ref'] ?? '') ?></span></th>
      <td data-label="<?= $word('INVOICES', 'supplier') ?>"><?= $e($r['supplier_name']) ?></td>
      <td data-label="<?= $word('INVOICES', 'posted') ?>"><?= $when($r['posted_at']) ?><span class="o-sub"><?= $e($r['posted_by_name'] ?? '') ?></span></td>
      <td class="num" data-label="<?= $word('INVOICES', 'lines_off') ?>"><?= $n($r['match_lines_off']) ?></td>
      <td class="num" data-label="<?= $word('INVOICES', 'difference') ?>"><?= $money($r['match_difference']) ?></td>
<?php if ($show === 'accepted'): ?>
      <td data-label="<?= $word('INVOICES', 'accepted_by') ?>"><?= $e($r['accepted_by_name'] ?? '') ?><span class="o-sub"><?= $e($r['accept_note'] ?? '') ?></span></td>
<?php endif; ?>
      <td class="c-status"><?= $stateChip('MATCH_STATE', $r['match_state']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('STOCK_OPS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
