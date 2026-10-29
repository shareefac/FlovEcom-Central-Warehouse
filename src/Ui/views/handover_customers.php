<?= $head(\CW\Ui\Words::SEGMENT['handover_customers'], intro: 'handover_customers', look: $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($import !== null): ?>
<section class="card import-result" aria-labelledby="import-h">
  <h2 id="import-h"><?= $word('CUSTOMER', 'import_done') ?></h2>
  <p><?= $say('CUSTOMER', 'import_counts', $import['rows'], $import['imported'], $import['kept_newer'], count($import['skipped'])) ?></p>
<?php if ($import['skipped'] !== []): ?>
  <p class="note"><?= $word('CUSTOMER', 'import_skipped') ?></p>
  <div class="table-wrap">
  <table class="stack list">
    <thead><tr><th scope="col"><?= $word('CUSTOMER', 'import_row') ?></th><th scope="col"><?= $word('CUSTOMER', 'import_value') ?></th><th scope="col"><?= $word('CUSTOMER', 'import_why') ?></th></tr></thead>
    <tbody>
<?php foreach ($import['skipped'] as $s): ?>
      <tr>
        <th scope="row" class="c-head"><?= $say('CUSTOMER', 'import_row_no', $s['row'] + 1) ?></th>
        <td data-label="<?= $word('CUSTOMER', 'import_value') ?>"><?= $e($s['value']) ?></td>
        <td data-label="<?= $word('CUSTOMER', 'import_why') ?>"><?= $word('PRICE_SKIP', $s['why']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
<?php endif; ?>

<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('CUSTOMER', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($filtered): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
      <label><?= $word('CUSTOMER', 'kind') ?>
        <select name="kind">
          <option value=""><?= $word('CUSTOMER', 'any_kind') ?></option>
<?php foreach ($kinds as $k): ?>
          <option value="<?= $e($k) ?>"<?php if ($f['kind'] === $k): ?> selected<?php endif; ?>><?= $word('CUSTOMER_KIND', $k) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label class="choice"><input type="checkbox" name="off" value="1"<?php if ($f['off']): ?> checked<?php endif; ?>> <?= $word('CUSTOMER', 'show_off') ?></label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="/ui/stock/handovers/customers"><?= $word('CUSTOMER', 'clear') ?></a><?php endif; ?><button type="submit" class="btn sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
</form>

<?php if ($formKey !== null): ?>
<details class="fold new-order drawer" id="new"<?php if (($error !== null && $import === null && $errorCode !== 'import_columns' && $errorCode !== 'bad_file') || $rows === []): ?> open<?php endif; ?>>
  <summary><?= $word('CUSTOMER', 'add') ?></summary>
  <form class="record customer" method="post" action="/ui/stock/handovers/customers">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
<?= $partial('handover_customer_fields', ['v' => $v, 'errors' => $errors, 'kinds' => $kinds, 'vatCodes' => $vatCodes]) ?>
    <p class="actions"><button type="submit" class="btn primary"><?= $word('CUSTOMER', 'add_button') ?></button></p>
  </form>
</details>
<details class="fold"<?php if ($errorCode === 'import_columns' || $errorCode === 'bad_file'): ?> open<?php endif; ?>>
  <summary><?= $word('CUSTOMER', 'import') ?></summary>
  <p class="muted"><?= $word('CUSTOMER', 'import_text') ?></p>
  <form class="record" method="post" enctype="multipart/form-data" action="/ui/stock/handovers/customers/prices">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('CUSTOMER', 'import_file') ?> <span class="hint"><?= $say('CUSTOMER', 'import_hint', $maxMb) ?></span><input type="file" name="file" accept=".csv,text/csv" required></label>
    <p class="actions"><button class="btn" type="submit"><?= $word('CUSTOMER', 'import_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::CUSTOMER['none_filter'], \CW\Ui\Words::CUSTOMER['none_filter_text'], '/ui/stock/handovers/customers', \CW\Ui\Words::CUSTOMER['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::CUSTOMER['none'], \CW\Ui\Words::CUSTOMER['none_text']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list customers">
  <thead>
    <tr>
      <th scope="col"><?= $word('CUSTOMER', 'name') ?></th>
      <th scope="col"><?= $word('CUSTOMER', 'kind') ?></th>
      <th scope="col"><?= $word('CUSTOMER', 'vat_code') ?></th>
      <th scope="col" class="num"><?= $word('CUSTOMER', 'handovers') ?></th>
      <th scope="col" class="num"><?= $word('CUSTOMER', 'balance') ?></th>
      <th scope="col"><?= $word('CUSTOMER', 'last') ?></th>
      <th scope="col" class="c-status"><?= $word('CUSTOMER', 'state') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr<?php if ((int) $r['is_active'] !== 1): ?> class="off"<?php endif; ?>>
      <th scope="row" class="c-head"><a href="<?= $u('/ui/stock/handovers/customers/' . $r['id']) ?>"><?= $e($r['name']) ?></a></th>
      <td data-label="<?= $word('CUSTOMER', 'kind') ?>"><?= $word('CUSTOMER_KIND', (string) $r['kind']) ?></td>
      <td data-label="<?= $word('CUSTOMER', 'vat_code') ?>"><?php if ($r['vat_code'] === null): ?><?= $word('CUSTOMER', 'vat_company') ?><?php else: ?><?= $e($r['vat_code']) ?><?php endif; ?></td>
      <td class="num" data-label="<?= $word('CUSTOMER', 'handovers') ?>"><?= $n($r['handovers']) ?></td>
      <td class="num" data-label="<?= $word('CUSTOMER', 'balance') ?>"><?= $money($r['balance']) ?></td>
      <td data-label="<?= $word('CUSTOMER', 'last') ?>"><?php if ($r['last_handover'] !== null): ?><?= $day($r['last_handover']) ?><?php endif; ?></td>
      <td class="c-status"><?php if ((int) $r['is_active'] === 1): ?><?= $chip('done', \CW\Ui\Words::CUSTOMER['on']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::CUSTOMER['off']) ?><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
