<?php $newActions = $newActions ?? []; ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <?= $partial('split', ['actions' => $newActions]) ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('STOCK_OPS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
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
      <label><?= $word('STOCK_OPS', 'warehouse') ?>
        <select name="warehouse">
          <option value=""><?= $word('STOCK_OPS', 'any_warehouse') ?></option>
<?php foreach ($warehouses as $w): ?>
          <option value="<?= $e($w['id']) ?>"<?php if ($f['warehouse'] === $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label class="choice"><input type="checkbox" name="cancelled" value="1"<?php if ($f['cancelled']): ?> checked<?php endif; ?>> <?= $word('STOCK_OPS', 'stopped') ?></label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="<?= $e($base) ?>"><?= $word('STOCK_OPS', 'clear') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
<?php if ($rows_n > 0): ?>
  <div class="tb-end"><a class="btn ghost sm" href="<?= $e($csv) ?>" title="<?= $word('STOCK_OPS', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a></div>
<?php endif; ?>
</form>

<div class="head-help">
  <h1><?php if ($kind === 'release'): ?><?= $word('SEGMENT', 'releases') ?><?php else: ?><?= $e($k['tab']) ?><?php endif; ?></h1>
  <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
</div>
<?= $intro($pageKey, $lookOnly) ?>
<?php if ($kind === 'in'): ?>
<p class="note"><?= $e($k['goods_in']) ?> <a href="/ui/receiving"><?= $e($k['goods_in_link']) ?></a></p>
<?php endif; ?>
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
<details class="fold new-order" id="new"<?php if ($error !== null || $rows_n === 0): ?> open<?php endif; ?>>
  <summary><?= $e($k['start']) ?></summary>
<?php if ($form['noOther']): ?>
  <?= $empty(\CW\Ui\Words::STOCK_OPS['no_other'], '', '/ui/reference/warehouses', \CW\Ui\Words::STOCK_OPS['no_other_link']) ?>
<?php else: ?>
  <p class="hint"><?= $e($k['start_text']) ?></p>
  <form class="record stock-new" method="post" action="<?= $e($base) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
<?= $partial('stock_op_fields', ['form' => $form, 'kind' => $kind]) ?>
    <p class="actions"><button type="submit" class="primary"><?= $e($k['start_button']) ?></button></p>
  </form>
<?php endif; ?>
</details>
<?php endif; ?>

<?php if ($groups === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::STOCK_OPS['none_filter'], \CW\Ui\Words::STOCK_OPS['none_filter_text'], $base, \CW\Ui\Words::STOCK_OPS['clear']) ?>
<?php elseif ($groups === []): ?>
<?= $empty($k['none'], $k['none_text']) ?>
<?php else: ?>
<div class="board-head"><h2 class="board-sub"><?= $word('STOCK_OPS', 'board') ?></h2><p class="board-note"><?php if ($rows_n === 1): ?><?= $word('STOCK_OPS', 'total_one') ?><?php else: ?><?= $say('STOCK_OPS', 'total_many', $rows_n) ?><?php endif; ?></p></div>
<?php foreach ($groups as $g => $list): ?>
<?php $tone = $list[0]['tone']; $name = $g === 'reversal' ? \CW\Ui\Words::STOCK_OPS['group_reversal'] : \CW\Ui\Words::of('DOC_STATUS', $g); ?>
<div class="grp-block">
  <h3 class="grp-title <?= $e($tone) ?>"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $e($name) ?></button><span class="grp-count"><?php if (count($list) === 1): ?><?= $word('STOCK_OPS', 'group_one') ?><?php else: ?><?= $say('STOCK_OPS', 'group_many', count($list)) ?><?php endif; ?></span></h3>
  <div class="table-wrap" id="g-<?= $e($g) ?>">
  <table class="stack list board stock-records <?= $e($tone) ?>">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('STOCK_OPS', 'record') ?></th>
        <th scope="col"><?= $word('STOCK_OPS', 'where') ?></th>
<?php if (in_array($kind, ['in', 'out', 'release'], true)): ?>
        <th scope="col"><?php if ($kind === 'release'): ?><?= $word('STOCK_OPS', 'account') ?><?php else: ?><?= $word('STOCK_OPS', 'why') ?><?php endif; ?></th>
<?php endif; ?>
        <th scope="col" class="c-status"><?= $word('STOCK_OPS', 'status') ?></th>
        <th scope="col" class="num"><?= $word('STOCK_OPS', 'products') ?></th>
        <th scope="col" class="num"><?= $word('STOCK_OPS', 'units') ?></th>
<?php if (in_array($kind, ['in', 'release'], true)): ?>
        <th scope="col" class="num"><?= $word('STOCK_OPS', 'value') ?></th>
<?php endif; ?>
        <th scope="col"><?= $word('STOCK_OPS', 'made_by') ?></th>
        <th scope="col"><?= $word('STOCK_OPS', 'next') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr class="<?= $e($r['tone']) ?>">
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['number_line']) ?></a><span class="o-sub"><?= $e($r['date_line']) ?></span></th>
        <td data-label="<?= $word('STOCK_OPS', 'where') ?>"><?= $e($r['where']) ?></td>
<?php if (in_array($kind, ['in', 'out', 'release'], true)): ?>
        <td data-label="<?php if ($kind === 'release'): ?><?= $word('STOCK_OPS', 'account') ?><?php else: ?><?= $word('STOCK_OPS', 'why') ?><?php endif; ?>"><?= $e($r['why']) ?><?php if ($r['given'] !== null): ?><span class="o-sub"><?= $e($r['given']) ?></span><?php endif; ?></td>
<?php endif; ?>
        <td class="c-status"><?= $chip($r['tone'], $r['state_word']) ?></td>
        <td class="num" data-label="<?= $word('STOCK_OPS', 'products') ?>"><?= $n($r['products']) ?></td>
        <td class="num" data-label="<?= $word('STOCK_OPS', 'units') ?>"><?= $n($r['units']) ?></td>
<?php if (in_array($kind, ['in', 'release'], true)): ?>
        <td class="num" data-label="<?= $word('STOCK_OPS', 'value') ?>"><?php if ($r['value'] !== null): ?><?= $money($r['value']) ?><?php endif; ?></td>
<?php endif; ?>
        <td data-label="<?= $word('STOCK_OPS', 'made_by') ?>"><?= $e($r['made_by']) ?></td>
        <td class="c-wide" data-label="<?= $word('STOCK_OPS', 'next') ?>"><?= $e($r['next']) ?></td>
      </tr>
<?php endforeach; ?>
<?php if ($g === 'draft' && $formKey !== null): ?>
      <tr class="grp-add"><td colspan="9" data-label=""><a class="add-item" href="#new"><?= $word('STOCK_OPS', 'add_row') ?></a></td></tr>
<?php endif; ?>
<?php if ($g !== 'reversal'): ?>
      <tr class="grp-foot">
        <th scope="row" class="c-head c-item"><?= $say('STOCK_OPS', 'group_total', $name) ?></th>
        <td data-label=""></td>
<?php if (in_array($kind, ['in', 'out', 'release'], true)): ?>
        <td data-label=""></td>
<?php endif; ?>
        <td data-label=""></td>
        <td class="num" data-label="<?= $word('STOCK_OPS', 'products') ?>"></td>
        <td class="num" data-label="<?= $word('STOCK_OPS', 'units') ?>"><?= $n(array_sum(array_column($list, 'units'))) ?></td>
<?php if (in_array($kind, ['in', 'release'], true)): ?>
        <td class="num" data-label="<?= $word('STOCK_OPS', 'value') ?>"><?= $money(array_sum(array_map(static fn (array $x): float => (float) ($x['value'] ?? 0), $list))) ?></td>
<?php endif; ?>
        <td data-label=""></td>
        <td data-label=""></td>
      </tr>
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
