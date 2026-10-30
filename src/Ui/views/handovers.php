<?php
// Sales › Trade sales (hand-overs), a simple list (the approved redesign of 30 Oct 2026, docs/decisions.md RD-B6; before it W8): one search
// (the number, the customer, their reference, a note) and one Show row (the state: every hand-over not stopped unless another is chosen),
// Clear and Export at the right; the head's "+ New …" opens the new form (its fields in two columns). The groups: draft, waiting for an OK,
// final, cancelled, the cancellation records, stopped; each a heading with its coloured dot and its count over a white table card; five
// columns: the record (it opens it), the customer (its kind under it), the status as a small pill, the units, the value (before VAT while it
// is not final). A customer's own link (counterparty=) and an old "stopped too" (cancelled=1) are kept by the search.
?>
<?= $head(\CW\Ui\Words::SEGMENT['handovers'], intro: 'handovers', look: $lookOnly) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('HANDOVER', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('HANDOVER', 'search') ?>"></div>
  <div class="tb-state">
    <label for="tb-show"><?= $word('DOC_LIST', 'show') ?></label>
    <select id="tb-show" name="state">
      <option value=""><?= $word('HANDOVER', 'any_state') ?></option>
<?php foreach ($states as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
<?php if ($f['counterparty'] !== null): ?>
    <input type="hidden" name="counterparty" value="<?= $e($f['counterparty']) ?>">
<?php endif; ?>
<?php if ($f['cancelled']): ?>
    <input type="hidden" name="cancelled" value="1">
<?php endif; ?>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="/ui/stock/handovers"><?= $word('HANDOVER', 'clear') ?></a>
<?php endif; ?>
<?php if ($rowsN > 0): ?>
    <a class="btn ghost sm" href="<?= $e($csv) ?>" title="<?= $word('HANDOVER', 'download') ?>" download><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
<?php endif; ?>
  </div>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($formKey !== null): ?>
<details class="fold new-order drawer" id="new"<?php if ($error !== null || $rowsN === 0): ?> open<?php endif; ?>>
  <summary><?= $word('HANDOVER', 'start') ?></summary>
<?php if ($form['noCustomers']): ?>
  <?= $empty(\CW\Ui\Words::HANDOVER['no_customers'], \CW\Ui\Words::HANDOVER['no_customers_text'], '/ui/stock/handovers/customers#new', \CW\Ui\Words::HANDOVER['no_customers_link']) ?>
<?php else: ?>
  <form class="record handover-new" method="post" action="/ui/stock/handovers" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <fieldset class="doc-grid">
      <legend class="visually-hidden"><?= $word('HANDOVER', 'start') ?></legend>
<?= $partial('handover_fields', ['form' => $form]) ?>
    </fieldset>
    <p class="actions"><button type="submit" class="btn primary"><?= $word('HANDOVER', 'start_button') ?></button></p>
  </form>
<?php endif; ?>
</details>
<?php endif; ?>

<?php if ($groups === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::HANDOVER['none_filter'], \CW\Ui\Words::HANDOVER['none_filter_text'], '/ui/stock/handovers', \CW\Ui\Words::HANDOVER['clear']) ?>
<?php elseif ($groups === []): ?>
<?= $empty(\CW\Ui\Words::HANDOVER['none'], \CW\Ui\Words::HANDOVER['none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g => $list): ?>
<?php $tone = $list[0]['tone']; $name = $g === 'reversal' ? \CW\Ui\Words::HANDOVER['group_reversal'] : \CW\Ui\Words::of('DOC_STATUS', $g); ?>
<div class="grp-block doc-group <?= $e($tone) ?> rd-list">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $e($name) ?></button> <span class="grp-count"><?= $say('HANDOVER', 'group_count', count($list)) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g) ?>">
  <table class="stack list board doc-list handover-records">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('HANDOVER', 'record') ?></th>
        <th scope="col"><?= $word('HANDOVER', 'customer') ?></th>
        <th scope="col" class="c-status"><?= $word('HANDOVER', 'status') ?></th>
        <th scope="col" class="num"><?= $word('HANDOVER', 'units') ?></th>
        <th scope="col" class="num"><?= $word('HANDOVER', 'value') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['number_line']) ?></a></th>
        <td data-label="<?= $word('HANDOVER', 'customer') ?>"><?= $e($r['customer']) ?><span class="o-sub"><?= $e($r['kind']) ?></span></td>
        <td class="c-status doc-st <?= $e($r['tone']) ?>"><span class="chip"><?= $e($r['state_word']) ?></span></td>
        <td class="num" data-label="<?= $word('HANDOVER', 'units') ?>"><?= $n($r['units']) ?></td>
        <td class="num" data-label="<?= $word('HANDOVER', 'value') ?>"><?php if ($r['value'] !== null): ?><?= $money($r['value']) ?><?php if (!$r['final']): ?><span class="o-sub"><?= $word('HANDOVER', 'before_vat') ?></span><?php endif; ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($rowsN >= $limit): ?>
<p class="muted"><?= $say('HANDOVER', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
