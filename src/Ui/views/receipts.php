<?php $newActions = $newActions ?? []; ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <?= $partial('split', ['actions' => $newActions]) ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('RECEIVING', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($filtered): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
      <label><?= $word('RECEIVING', 'show') ?>
        <select name="state">
          <option value=""><?= $word('RECEIVING', 'all') ?></option>
<?php foreach ($states as $code => $label): ?>
          <option value="<?= $e($code) ?>"<?php if ($filters['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('RECEIPT', 'supplier') ?>
        <select name="supplier">
          <option value=""><?= $word('RECEIVING', 'any_supplier') ?></option>
<?php foreach ($suppliers as $s): ?>
          <option value="<?= $e($s['id']) ?>"<?php if ($filters['supplier'] === (int) $s['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="/ui/receiving"><?= $word('RECEIVING', 'clear') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
</form>
<div class="head-help">
  <h1><?= $word('MENU', 'receiving') ?></h1>
  <?= $explain('posting', \CW\Ui\Words::RECEIVING['posting_label']) ?>
</div>
<?= $intro('receiving', $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<details class="fold how">
  <summary><?= $word('RECEIVING', 'how') ?></summary>
  <ol>
    <li><?= $word('RECEIVING', 'how_1') ?></li>
    <li><?= $word('RECEIVING', 'how_2') ?></li>
    <li><?= $word('RECEIVING', 'how_3') ?></li>
    <li><?= $word('RECEIVING', 'how_4') ?></li>
  </ol>
</details>
<?php if ($formKey !== null): ?>
<section class="card box new-delivery" aria-labelledby="new-h" id="new">
  <h2 id="new-h"><?= $word('RECEIVING', 'new') ?></h2>
<?php if ($newSuppliers === []): ?>
  <?= $empty(\CW\Ui\Words::RECEIVING['no_supplier'], \CW\Ui\Words::RECEIVING['no_supplier_text']) ?>
<?php else: ?>
  <p class="hint"><?= $word('RECEIVING', 'new_text') ?></p>
  <form class="record receive-new" method="post" action="/ui/receiving">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <label><?= $word('RECEIVING', 'po') ?>
      <select name="po_id" data-ticks="copy">
        <option value=""><?= $word('RECEIVING', 'no_po') ?></option>
<?php foreach ($orders as $o): ?>
        <option value="<?= $e($o['id']) ?>"<?php if (($typed['po_id'] ?? '') === (string) $o['id']): ?> selected<?php endif; ?>><?= $say('RECEIVING', 'po_option', (string) $o['number'], (string) $o['supplier_name'], (int) $o['outstanding'], mb_strtolower(\CW\Ui\Words::of('PO_STATE', (string) $o['state']))) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('RECEIVING', 'supplier') ?>
      <select name="supplier_id">
        <option value=""><?= $word('RECEIVING', 'po_supplier') ?></option>
<?php foreach ($newSuppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if (($typed['supplier_id'] ?? '') === (string) $s['id']): ?> selected<?php endif; ?>><?php if ($s['status'] === 'active'): ?><?= $e($s['name']) ?><?php else: ?><?= $say('RECEIVING', 'not_ready', (string) $s['name'], mb_strtolower(\CW\Ui\Words::of('SUPPLIER_STATUS', (string) $s['status']))) ?><?php endif; ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('RECEIVING', 'invoice') ?> <input type="text" name="invoice_number" maxlength="64" value="<?= $e($typed['invoice_number'] ?? '') ?>" autocomplete="off"></label>
    <label class="choice"><input type="checkbox" name="copy" value="1" id="copy"<?php if (($typed['copy'] ?? (($typed['po_id'] ?? '') !== '' ? '1' : '')) === '1'): ?> checked<?php endif; ?>> <?= $word('RECEIVING', 'copy') ?></label>
    <p class="actions"><button type="submit" class="primary"><?= $word('RECEIVING', 'start') ?></button></p>
  </form>
  <p class="hint"><a href="/ui/receiving/template.csv"><?= $word('RECEIVING', 'sample') ?></a></p>
<?php endif; ?>
</section>
<?php endif; ?>


<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::RECEIVING['none_filter'], \CW\Ui\Words::RECEIVING['none_filter_text'], '/ui/receiving', \CW\Ui\Words::RECEIVING['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::RECEIVING['none'], \CW\Ui\Words::RECEIVING[$canPost ? 'none_desk' : 'none_text']) ?>
<?php else: ?>
<div class="board-head"><h2 class="board-title"><?= $word('MENU', 'receiving') ?></h2><p class="board-note"><?php if (count($rows) === 1): ?><?= $word('RECEIVING', 'total_one') ?><?php else: ?><?= $say('RECEIVING', 'total_many', count($rows)) ?><?php endif; ?></p></div>
<div class="table-wrap">
<table class="stack list receipts board">
  <thead>
    <tr>
      <th scope="col"><?= $word('RECEIVING', 'delivery') ?></th>
      <th scope="col"><?= $word('RECEIVING', 'status') ?></th>
      <th scope="col"><?= $word('RECEIVING', 'arrived') ?></th>
      <th scope="col"><?= $word('RECEIVING', 'size') ?></th>
      <th scope="col"><?= $word('RECEIVING', 'bench') ?></th>
      <th scope="col"><?= $word('RECEIVING', 'check') ?></th>
      <th scope="col"><?= $word('RECEIVING', 'problems') ?></th>
      <th scope="col"><span class="visually-hidden"><?= $word('RECEIVING', 'open') ?></span></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?= $e($r['state_tone']) ?>">
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/receiving/' . $r['id']) ?>"><?= $e($r['supplier_name']) ?></a><span class="o-no"><?= $e($r['number_line']) ?></span></th>
      <td class="c-status"><?= $chip($r['state_tone'], $r['state_word']) ?></td>
      <td data-label="<?= $word('RECEIVING', 'arrived') ?>"><?= $when($r['received_at']) ?><?php if ((int) $r['paper_sheet'] === 1): ?> <span class="o-sub"><?= $word('RECEIVING', 'paper') ?></span><?php endif; ?></td>
      <td data-label="<?= $word('RECEIVING', 'size') ?>"><?= $e($r['size_line']) ?></td>
      <td data-label="<?= $word('RECEIVING', 'bench') ?>"><?php if ($r['bench_state'] !== null): ?><?= $stateChip('BENCH_STATE', $r['bench_state']) ?><?php endif; ?></td>
      <td data-label="<?= $word('RECEIVING', 'check') ?>"><?php if ($r['review_state'] !== null && $r['review_state'] !== 'not_required'): ?><?= $stateChip('REVIEW_STATE', $r['review_state']) ?><?php endif; ?></td>
      <td data-label="<?= $word('RECEIVING', 'problems') ?>"><?php if ($r['incidents_line'] !== null): ?><?= $chip('needs', $r['incidents_line']) ?><?php endif; ?></td>
      <td class="c-next"><a class="btn secondary" href="<?= $u('/ui/receiving/' . $r['id']) ?>"><?= $word('RECEIVING', 'open') ?></a></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('RECEIVING', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
