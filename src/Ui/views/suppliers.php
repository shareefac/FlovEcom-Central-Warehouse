<h1><?= $word('MENU', 'suppliers') ?></h1>
<?= $intro('suppliers', $lookOnly) ?>
<p class="hint"><?= $word('SUPPLIERS', 'no_bank') ?></p>
<div class="crumbs">
  <form class="filters" method="get" action="/ui/purchasing/suppliers">
    <label><?= $word('SUPPLIERS', 'status') ?>
      <select name="status">
        <option value=""><?= $word('SUPPLIERS', 'any_status') ?></option>
<?php foreach ($statuses as $code => $label): ?>
        <option value="<?= $e($code) ?>"<?php if ($filters['status'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('SUPPLIERS', 'search') ?> <input type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100"></label>
    <label class="choice"><input type="checkbox" name="due" value="1"<?php if ($filters['due']): ?> checked<?php endif; ?>> <?= $say('SUPPLIERS', 'due', $dueDays) ?></label>
    <button type="submit"><?= $word('SUPPLIERS', 'filter') ?></button>
  </form>
  <p class="actions">
<?php if ($canManage): ?>
    <a class="btn primary" href="/ui/purchasing/suppliers/new"><?= $word('SUPPLIERS', 'new') ?></a>
<?php endif; ?>
    <a href="<?= $u('/ui/purchasing/suppliers.csv', ['status' => $filters['status'], 'q' => $filters['q'], 'due' => $filters['due'] ? '1' : null]) ?>"><?= $word('SUPPLIERS', 'download') ?></a>
  </p>
</div>
<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::SUPPLIERS['none_filter'], \CW\Ui\Words::SUPPLIERS['none_filter_text'], '/ui/purchasing/suppliers', \CW\Ui\Words::SUPPLIERS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::SUPPLIERS['none'], \CW\Ui\Words::SUPPLIERS[$canManage ? 'none_text' : 'none_look']) ?>
<?php else: ?>
<p class="muted"><?php if (count($rows) === 1): ?><?= $word('SUPPLIERS', 'total_one') ?><?php else: ?><?= $say('SUPPLIERS', 'total_many', count($rows)) ?><?php endif; ?></p>
<div class="table-wrap">
<table class="stack list suppliers">
  <thead>
    <tr>
      <th scope="col"><?= $word('SUPPLIERS', 'supplier') ?></th>
      <th scope="col"><?= $word('SUPPLIERS', 'status') ?></th>
      <th scope="col"><?= $word('SUPPLIERS', 'next_check') ?></th>
      <th scope="col" class="num"><?= $word('SUPPLIERS', 'products') ?></th>
      <th scope="col"><?= $word('SUPPLIERS', 'approved') ?></th>
      <th scope="col"><span class="visually-hidden"><?= $word('SUPPLIERS', 'open') ?></span></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?= $e(\CW\Ui\Words::tone('SUPPLIER_STATUS', (string) $r['status'])) ?>">
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/purchasing/suppliers/' . $r['id']) ?>"><?= $e($r['name']) ?></a><span class="o-no"><?= $e($r['code']) ?><?php if ((int) $r['is_overseas'] === 1): ?> · <?= $word('SUPPLIERS', 'abroad') ?><?php endif; ?></span></th>
      <td class="c-status"><?= $stateChip('SUPPLIER_STATUS', (string) $r['status']) ?></td>
      <td data-label="<?= $word('SUPPLIERS', 'next_check') ?>"><?php if ($r['dd_next_review_on'] !== null): ?><?= $day($r['dd_next_review_on']) ?><?php endif; ?><?php if ($r['dd_overdue']): ?> <?= $chip('blocked', \CW\Ui\Words::SUPPLIERS['overdue']) ?><?php endif; ?></td>
      <td class="num" data-label="<?= $word('SUPPLIERS', 'products') ?>"><?= $n($r['items']) ?></td>
      <td data-label="<?= $word('SUPPLIERS', 'approved') ?>"><?php if ($r['approved_at'] !== null): ?><?= $say('SUPPLIERS', 'approved_line', \CW\Ui\Html::when($r['approved_at']), (string) ($r['approved_by_name'] ?? \CW\Ui\Words::ANOMALIES['set_up'])) ?><?php else: ?><span class="muted"><?= $word('SUPPLIERS', 'not_yet') ?></span><?php endif; ?></td>
      <td class="c-next"><a class="btn secondary" href="<?= $u('/ui/purchasing/suppliers/' . $r['id']) ?>"><?= $word('SUPPLIERS', 'open') ?></a></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('SUPPLIERS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
