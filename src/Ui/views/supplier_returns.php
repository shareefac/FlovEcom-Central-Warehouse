<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('INVOICES', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($filtered): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
      <label><?= $word('STOCK_OPS', 'state') ?>
        <select name="state">
          <option value=""><?= $word('STOCK_OPS', 'any_state') ?></option>
<?php foreach ($states as $code => $label): ?>
          <option value="<?= $e($code) ?>"<?php if ($f['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('INVOICES', 'supplier') ?>
        <select name="supplier">
          <option value=""><?= $word('INVOICES', 'any_supplier') ?></option>
<?php foreach ($suppliers as $s): ?>
          <option value="<?= $e($s['id']) ?>"<?php if ($f['supplier'] === $s['id']): ?> selected<?php endif; ?>><?= $e($s['label']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label class="choice"><input type="checkbox" name="cancelled" value="1"<?php if ($f['cancelled']): ?> checked<?php endif; ?>> <?= $word('STOCK_OPS', 'stopped') ?></label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="/ui/purchasing/returns"><?= $word('STOCK_OPS', 'clear') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
</form>

<h1><?= $word('MENU', 'returns') ?></h1>
<?= $intro('returns', $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<details class="fold how">
  <summary><?= $word('STOCK_OPS', 'how') ?></summary>
  <ol>
<?php foreach (\CW\Ui\Words::RETURNS['how'] as $step): ?>
    <li><?= $e($step) ?></li>
<?php endforeach; ?>
  </ol>
</details>

<?php if ($quickKey !== null && $deliveries !== []): ?>
<section class="card" aria-labelledby="quick-h">
  <h2 id="quick-h"><?= $word('RETURNS', 'quick_title') ?></h2>
  <p class="hint"><?= $word('RETURNS', 'quick_text') ?></p>
  <form class="inline" method="post" action="/ui/purchasing/returns/from-receipt">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($quickKey) ?>">
    <label><?= $word('INVOICES', 'delivery') ?>
      <select name="grn_id" required>
<?php foreach ($deliveries as $d): ?>
        <option value="<?= $e($d['id']) ?>"><?= $e($d['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="primary"><?= $word('RETURNS', 'quick_button') ?></button>
  </form>
</section>
<?php endif; ?>

<?php if ($formKey !== null): ?>
<details class="fold new-order" id="new"<?php if ($error !== null): ?> open<?php endif; ?>>
  <summary><?= $word('RETURNS', 'start') ?></summary>
  <p class="hint"><?= $word('RETURNS', 'start_text') ?></p>
  <form class="record" method="post" action="/ui/purchasing/returns">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <label><?= $word('INVOICES', 'supplier') ?>
      <select name="supplier_id" required>
        <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($suppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if (($typed['supplier_id'] ?? '') === (string) $s['id']): ?> selected<?php endif; ?>><?= $e($s['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('RETURNS', 'from') ?>
      <select name="warehouse_id">
<?php foreach ($warehouses as $w): ?>
        <option value="<?= $e($w['id']) ?>"<?php if (($typed['warehouse_id'] ?? '') === (string) $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('RETURNS', 'reason') ?>
      <select name="reason_code">
        <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($reasons as $r): ?>
        <option value="<?= $e($r['code']) ?>"<?php if (($typed['reason_code'] ?? '') === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('RETURNS', 'ref') ?> <input type="text" name="return_ref" value="<?= $e($typed['return_ref'] ?? '') ?>" maxlength="100"></label>
    <p class="actions"><button type="submit" class="primary"><?= $word('RETURNS', 'start_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::STOCK_OPS['none_filter'], \CW\Ui\Words::STOCK_OPS['none_filter_text'], '/ui/purchasing/returns', \CW\Ui\Words::STOCK_OPS['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::RETURNS['none'], \CW\Ui\Words::RETURNS['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list board">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('INVOICES', 'record') ?></th>
      <th scope="col"><?= $word('INVOICES', 'supplier') ?></th>
      <th scope="col"><?= $word('RETURNS', 'reason') ?></th>
      <th scope="col" class="num"><?= $word('STOCK_OPS', 'units') ?></th>
      <th scope="col" class="num"><?= $word('RETURNS', 'claim') ?></th>
      <th scope="col"><?= $word('RETURNS', 'credited') ?></th>
      <th scope="col" class="c-status"><?= $word('STOCK_OPS', 'status') ?></th>
      <th scope="col"><?= $word('STOCK_OPS', 'made_by') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?= $e($r['tone']) ?>">
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['number']) ?></a><span class="o-sub"><?= $e($r['sub']) ?><?php if ($r['delivery'] !== null): ?> · <?= $e($r['delivery']) ?><?php endif; ?></span></th>
      <td data-label="<?= $word('INVOICES', 'supplier') ?>"><?= $e($r['supplier']) ?></td>
      <td data-label="<?= $word('RETURNS', 'reason') ?>"><?= $e($r['reason'] ?? '') ?></td>
      <td class="num" data-label="<?= $word('STOCK_OPS', 'units') ?>"><?= $n($r['units']) ?></td>
      <td class="num" data-label="<?= $word('RETURNS', 'claim') ?>"><?php if ($r['claim'] !== null): ?><?= $money($r['claim']) ?><?php endif; ?></td>
      <td data-label="<?= $word('RETURNS', 'credited') ?>"><?php if ($r['credit'] !== null): ?><?= $chip($r['credit_tone'], $r['credit_word']) ?><?php endif; ?></td>
      <td class="c-status"><?= $chip($r['tone'], $r['state_word']) ?></td>
      <td data-label="<?= $word('STOCK_OPS', 'made_by') ?>"><?= $e($r['made_by']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('STOCK_OPS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
