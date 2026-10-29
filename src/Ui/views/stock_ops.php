<?php
// Stock › Stock In, Stock Out, Adjustments, Transfers and Releases, a board (the approved design of 29 Oct 2026, pattern 1; docs/decisions.md
// W8): one search (the number, a reference, a note, who it was given to, the other account) and one Show row (the state: every record not
// stopped unless another is chosen; the warehouse, which the search does not find), Clear and Export at the right; the head's "+ New …" and
// the last row of the Draft group open the new form (its fields in two columns). The groups in order: draft, waiting for an OK, final,
// cancelled, the cancellation records, stopped; each a coloured heading with its count over a card with its colour on the left edge, the
// status cell filled with it. A row opens its record. An old link's "stopped too" (cancelled=1) is kept.
?>
<?= $head(($kind === 'release' ? \CW\Ui\Words::of('SEGMENT', 'releases') : (string) $k['tab']), intro: $pageKey, look: $lookOnly, aside: $explain('second_ok', \CW\Ui\Words::THING['second'])) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('STOCK_OPS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('STOCK_OPS', 'search') ?>"></div>
  <div class="tb-state">
    <label for="tb-show"><?= $word('STOCK_OPS', 'state') ?></label>
    <select id="tb-show" name="state">
      <option value=""><?= $word('STOCK_OPS', 'any_state') ?></option>
<?php foreach ($states as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
    <label class="visually-hidden" for="tb-wh"><?= $word('STOCK_OPS', 'warehouse') ?></label>
    <select id="tb-wh" name="warehouse">
      <option value=""><?= $word('STOCK_OPS', 'any_warehouse') ?></option>
<?php foreach ($warehouses as $w): ?>
      <option value="<?= $e($w['id']) ?>"<?php if ($f['warehouse'] === $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
    </select>
<?php if ($f['cancelled']): ?>
    <input type="hidden" name="cancelled" value="1">
<?php endif; ?>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="<?= $e($base) ?>"><?= $word('STOCK_OPS', 'clear') ?></a>
<?php endif; ?>
<?php if ($rows_n > 0): ?>
    <a class="btn ghost sm" href="<?= $e($csv) ?>" title="<?= $word('STOCK_OPS', 'download') ?>" download><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
<?php endif; ?>
  </div>
</form>

<?php if ($kind === 'in'): ?>
<p class="note"><?= $e($k['goods_in']) ?> <a href="/ui/receiving"><?= $e($k['goods_in_link']) ?></a></p>
<?php endif; ?>
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
<?php $tone = $list[0]['tone']; $name = $g === 'reversal' ? \CW\Ui\Words::STOCK_OPS['group_reversal'] : \CW\Ui\Words::of('DOC_STATUS', $g); ?>
<div class="grp-block doc-group <?= $e($tone) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $e($name) ?></button> <span class="grp-count"><?php if (count($list) === 1): ?><?= $word('STOCK_OPS', 'group_one') ?><?php else: ?><?= $say('STOCK_OPS', 'group_many', count($list)) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g) ?>">
  <table class="stack list board doc-list stock-records">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('STOCK_OPS', 'record') ?></th>
        <th scope="col" class="c-status"><?= $word('STOCK_OPS', 'status') ?></th>
        <th scope="col"><?= $word('STOCK_OPS', 'where') ?></th>
<?php if (in_array($kind, ['in', 'out', 'release'], true)): ?>
        <th scope="col"><?php if ($kind === 'release'): ?><?= $word('STOCK_OPS', 'account') ?><?php else: ?><?= $word('STOCK_OPS', 'why') ?><?php endif; ?></th>
<?php endif; ?>
        <th scope="col" class="num"><?= $word('STOCK_OPS', 'products') ?></th>
        <th scope="col" class="num"><?= $word('STOCK_OPS', 'units') ?></th>
<?php if (in_array($kind, ['in', 'release'], true)): ?>
        <th scope="col" class="num"><?= $word('STOCK_OPS', 'value') ?></th>
<?php endif; ?>
        <th scope="col"><?= $word('STOCK_OPS', 'made_by') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['number_line']) ?></a><span class="o-sub"><?= $e($r['date_line']) ?></span></th>
        <td class="c-status doc-st <?= $e($r['tone']) ?>"><?= $e($r['state_word']) ?></td>
        <td data-label="<?= $word('STOCK_OPS', 'where') ?>"><?= $e($r['where']) ?></td>
<?php if (in_array($kind, ['in', 'out', 'release'], true)): ?>
        <td data-label="<?php if ($kind === 'release'): ?><?= $word('STOCK_OPS', 'account') ?><?php else: ?><?= $word('STOCK_OPS', 'why') ?><?php endif; ?>"><?= $e($r['why']) ?><?php if ($r['given'] !== null): ?><span class="o-sub"><?= $e($r['given']) ?></span><?php endif; ?></td>
<?php endif; ?>
        <td class="num" data-label="<?= $word('STOCK_OPS', 'products') ?>"><?= $n($r['products']) ?></td>
        <td class="num" data-label="<?= $word('STOCK_OPS', 'units') ?>"><?= $n($r['units']) ?></td>
<?php if (in_array($kind, ['in', 'release'], true)): ?>
        <td class="num" data-label="<?= $word('STOCK_OPS', 'value') ?>"><?php if ($r['value'] !== null): ?><?= $money($r['value']) ?><?php endif; ?></td>
<?php endif; ?>
        <td data-label="<?= $word('STOCK_OPS', 'made_by') ?>"><?= $e($r['made_by']) ?></td>
      </tr>
<?php endforeach; ?>
<?php if ($g === 'draft' && $formKey !== null): ?>
      <tr class="grp-add"><td colspan="8" data-label=""><a class="add-item" href="#new"><?= $word('STOCK_OPS', 'add_row') ?></a></td></tr>
<?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($rows_n >= $limit): ?>
<p class="muted"><?= $say('STOCK_OPS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
