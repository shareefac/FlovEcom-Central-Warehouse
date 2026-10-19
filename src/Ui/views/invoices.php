<?= $head((string) $k['tab'], intro: $kind === 'invoice' ? 'invoices' : 'credit_notes', look: $lookOnly) ?>
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
<?php if ($kind === 'invoice'): ?>
      <label><?= $word('INVOICES', 'match') ?>
        <select name="match">
          <option value=""><?= $word('INVOICES', 'any_match') ?></option>
<?php foreach ($matchStates as $code => $label): ?>
          <option value="<?= $e($code) ?>"<?php if ($f['match'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
<?php endif; ?>
      <label class="choice"><input type="checkbox" name="cancelled" value="1"<?php if ($f['cancelled']): ?> checked<?php endif; ?>> <?= $word('STOCK_OPS', 'stopped') ?></label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="<?= $e($base) ?>"><?= $word('STOCK_OPS', 'clear') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<details class="fold how">
  <summary><?= $word('STOCK_OPS', 'how') ?></summary>
  <ol>
<?php foreach ($k['how'] as $step): ?>
    <li><?= $e($step) ?></li>
<?php endforeach; ?>
  </ol>
</details>

<?php if ($formKey !== null): ?>
<details class="fold new-order drawer" id="new"<?php if ($error !== null): ?> open<?php endif; ?>>
  <summary><?= $e($k['start']) ?></summary>
  <p class="hint"><?= $e($k['start_text']) ?></p>
  <form class="record" method="post" action="<?= $e($base) ?>">
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
    <label><?= $e($k['number']) ?> <input type="text" name="invoice_number" value="<?= $e($typed['invoice_number'] ?? '') ?>" maxlength="64"></label>
    <label><?= $e($k['date']) ?> <input type="date" name="invoice_date" value="<?= $e($typed['invoice_date'] ?? '') ?>"></label>
    <p class="actions"><button type="submit" class="primary"><?= $e($k['start_button']) ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::STOCK_OPS['none_filter'], \CW\Ui\Words::STOCK_OPS['none_filter_text'], $base, \CW\Ui\Words::STOCK_OPS['clear']) ?>
<?php else: ?>
<?= $empty($k['none'], $k['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list board">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('INVOICES', 'record') ?></th>
      <th scope="col"><?= $word('INVOICES', 'supplier') ?></th>
      <th scope="col"><?= $e($k['date']) ?></th>
      <th scope="col" class="num"><?= $e($k['gross']) ?></th>
<?php if ($kind === 'invoice'): ?>
      <th scope="col"><?= $word('INVOICES', 'match') ?></th>
<?php else: ?>
      <th scope="col"><?= $word('INVOICES', 'credits') ?></th>
<?php endif; ?>
      <th scope="col" class="c-status"><?= $word('STOCK_OPS', 'status') ?></th>
      <th scope="col"><?= $word('STOCK_OPS', 'made_by') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?= $e($r['tone']) ?>">
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['number']) ?></a><span class="o-sub"><?= $e($r['sub']) ?></span></th>
      <td data-label="<?= $word('INVOICES', 'supplier') ?>"><?= $e($r['supplier']) ?></td>
      <td data-label="<?= $e($k['date']) ?>"><?= $e($r['date']) ?></td>
      <td class="num" data-label="<?= $e($k['gross']) ?>"><?php if ($r['gross'] !== null): ?><?= $money($r['gross']) ?><?php endif; ?></td>
<?php if ($kind === 'invoice'): ?>
      <td data-label="<?= $word('INVOICES', 'match') ?>"><?php if ($r['match'] !== null): ?><?= $stateChip('MATCH_STATE', $r['match']) ?><?php endif; ?></td>
<?php else: ?>
      <td data-label="<?= $word('INVOICES', 'credits') ?>"><?= $e($r['credits'] ?? '') ?></td>
<?php endif; ?>
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
