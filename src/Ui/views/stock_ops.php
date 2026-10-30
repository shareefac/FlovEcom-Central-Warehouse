<?php
// Inventory › Adjustments (Stock in, Stock out, Corrections, Releases) and Transfers, a simple list (the approved redesign of 30 Oct 2026,
// artboard Inventory: Adjustments; docs/decisions.md RD-A5): the title, and at its right More (the state and warehouse filters of before,
// Clear, the download; Goods received on Stock in) and the ONE main button, the kind's "+ New …" (it opens the create fold, its fields in
// two columns); one search and ONE Show choice (on Adjustments the kind, each its own page; on Transfers the state); then the records grouped
// Waiting for an OK, Draft, Done this week (and, when the list holds them, done before this week, cancelled, the cancellation records,
// stopped), four columns: the record (its number opens it, its date under it), the kind, the reason, the units. An old link's
// "stopped too" (cancelled=1) is kept.
?>
<?php $transfer = $kind === 'transfer'; ?>
<?= $head($transfer ? (string) $k['tab'] : \CW\Ui\Words::INV_LIST['adjustments'], intro: $pageKey, look: $lookOnly, aside: $explain('second_ok', \CW\Ui\Words::THING['second']),
    menu: $bar) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('STOCK_OPS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('STOCK_OPS', 'search') ?>"></div>
<?php if ($f['state'] !== null): ?>
  <input type="hidden" name="state" value="<?= $e($f['state']) ?>">
<?php endif; ?>
<?php if ($f['warehouse'] !== null): ?>
  <input type="hidden" name="warehouse" value="<?= $e($f['warehouse']) ?>">
<?php endif; ?>
<?php if ($f['cancelled']): ?>
  <input type="hidden" name="cancelled" value="1">
<?php endif; ?>
  <?= $showMine($show) ?>
</form>

<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($formKey !== null): ?>
<details class="fold new-order drawer" id="new"<?php if ($error !== null || $rows_n === 0 || $item !== null): ?> open<?php endif; ?>>
  <summary><?= $e($k['start']) ?></summary>
<?php if ($form['noOther']): ?>
  <?= $empty(\CW\Ui\Words::STOCK_OPS['no_other'], '', '/ui/reference/warehouses', \CW\Ui\Words::STOCK_OPS['no_other_link']) ?>
<?php else: ?>
  <form class="record stock-new" method="post" action="<?= $e($base) ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
<?php if ($item !== null): ?>
    <input type="hidden" name="item" value="<?= $e($item['id']) ?>">
    <p class="note"><?= $say('STOCK_OPS', 'for_item', $item['code'], $item['name']) ?></p>
<?php endif; ?>
    <fieldset class="doc-grid">
      <legend class="visually-hidden"><?= $e($k['start']) ?></legend>
<?= $partial('stock_op_fields', ['form' => $form, 'kind' => $kind]) ?>
    </fieldset>
    <p class="actions"><button type="submit" class="btn primary"><?= $e($k['start_button']) ?></button></p>
  </form>
<?php endif; ?>
</details>
<?php endif; ?>

<?php if ($groups === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::STOCK_OPS['none_filter'], \CW\Ui\Words::STOCK_OPS['none_filter_text'], $base, \CW\Ui\Words::STOCK_OPS['clear']) ?>
<?php elseif ($groups === []): ?>
<?= $empty($k['none'], $k['none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g => $list): ?>
<?php $tone = $list[0]['tone']; ?>
<div class="grp-block doc-group <?= $e($tone) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $e($groupNames[$g]) ?></button> <span class="grp-count"><?= $n(count($list)) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g) ?>">
  <table class="stack list board doc-list stock-records">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?php if ($transfer): ?><?= $word('INV_LIST', 'col_transfer') ?><?php else: ?><?= $word('INV_LIST', 'col_adjustment') ?><?php endif; ?></th>
<?php if ($transfer): ?>
        <th scope="col"><?= $word('INV_LIST', 'col_from') ?></th>
        <th scope="col"><?= $word('INV_LIST', 'col_to') ?></th>
<?php else: ?>
        <th scope="col"><?= $word('INV_LIST', 'col_kind') ?></th>
        <th scope="col"><?= $word('INV_LIST', 'col_reason') ?></th>
<?php endif; ?>
        <th scope="col" class="num"><?= $word('INV_LIST', 'col_units') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['number_line']) ?></a><span class="o-sub"><?= $e($r['date_line']) ?></span></th>
<?php if ($transfer): ?>
        <td data-label="<?= $word('INV_LIST', 'col_from') ?>"><?= $e($r['from']) ?></td>
        <td data-label="<?= $word('INV_LIST', 'col_to') ?>"><?= $e($r['to'] ?? '') ?></td>
<?php else: ?>
        <td data-label="<?= $word('INV_LIST', 'col_kind') ?>"><?= $e($r['kind_word']) ?></td>
        <td data-label="<?= $word('INV_LIST', 'col_reason') ?>"><?php if ($kind === 'adjust'): ?><span class="muted"><?= $word('INV_LIST', 'reason_lines') ?></span><?php else: ?><?= $e($r['why']) ?><?php endif; ?><?php if ($r['given'] !== null): ?><span class="o-sub"><?= $e($r['given']) ?></span><?php endif; ?></td>
<?php endif; ?>
        <td data-label="<?= $word('INV_LIST', 'col_units') ?>" class="num chg<?php if ($r['units_dir'] !== ''): ?> <?= $e($r['units_dir']) ?><?php endif; ?>"><?= $e($r['units_shown']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($rows_n >= $limit): ?>
<p class="muted"><?= $say('STOCK_OPS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
