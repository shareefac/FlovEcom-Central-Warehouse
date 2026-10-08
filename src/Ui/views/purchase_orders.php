<?php $newActions = $newActions ?? []; ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <?= $partial('split', ['actions' => $newActions]) ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('ORDERS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($filtered): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
      <label><?= $word('ORDERS', 'show') ?>
        <select name="state">
          <option value=""><?= $word('ORDERS', 'all_orders') ?></option>
<?php foreach ($states as $code => $label): ?>
          <option value="<?= $e($code) ?>"<?php if ($filters['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('ORDERS', 'supplier') ?>
        <select name="supplier">
          <option value=""><?= $word('ORDERS', 'any_supplier') ?></option>
<?php foreach ($suppliers as $s): ?>
          <option value="<?= $e($s['id']) ?>"<?php if ($filters['supplier'] === (int) $s['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label class="choice"><input type="checkbox" name="rejected" value="1"<?php if ($filters['rejected']): ?> checked<?php endif; ?>> <?= $word('ORDERS', 'rejected') ?></label>
      <label class="choice"><input type="checkbox" name="show" value="all"<?php if ($filters['all']): ?> checked<?php endif; ?>> <?= $word('ORDERS', 'all') ?></label>
<?php if ($draftsChoice): ?>
      <label class="choice"><input type="checkbox" name="drafts" value="1"<?php if ($filters['drafts']): ?> checked<?php endif; ?>> <?= $word('ORDERS', 'drafts') ?></label>
<?php endif; ?>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="/ui/purchasing/orders"><?= $word('ORDERS', 'clear') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
<?php if ($rows !== []): ?>
  <div class="tb-end"><a class="btn ghost sm" href="<?= $u('/ui/purchasing/orders.csv', ['state' => $filters['state'], 'supplier' => $filters['supplier'], 'q' => $filters['q'], 'rejected' => $filters['rejected'] ? '1' : null, 'show' => $filters['all'] ? 'all' : null, 'drafts' => $filters['drafts'] ? null : '0']) ?>" title=""<?= $word('ORDERS', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a></div>
<?php endif; ?>
</form>

<div class="head-help">
  <h1><?= $word('MENU', 'orders') ?></h1>
  <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
</div>
<?= $intro('orders', $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<details class="fold how">
  <summary><?= $word('ORDERS', 'how') ?></summary>
  <ol>
    <li><?= $word('ORDERS', 'how_1') ?></li>
    <li><?= $word('ORDERS', 'how_2') ?></li>
    <li><?= $word('ORDERS', 'how_3') ?></li>
<?php if ($approvalLimit !== null): ?>
    <li><?= $say('ORDERS', 'how_4', $approvalLimit) ?></li>
<?php else: ?>
    <li><?= $word('ORDERS', 'how_4_off') ?></li>
<?php endif; ?>
  </ol>
  <p><?= $word('ORDERS', 'how_fix') ?></p>
</details>

<?php if ($formKey !== null): ?>
<details class="fold new-order" id="new"<?php if ($error !== null || $rows === []): ?> open<?php endif; ?>>
  <summary><?= $word('ORDERS', 'new') ?></summary>
<?php if ($newSuppliers === []): ?>
  <?= $empty(\CW\Ui\Words::ORDERS['no_supplier'], \CW\Ui\Words::ORDERS['no_supplier_text'], '/ui/purchasing/suppliers/new', \CW\Ui\Words::ORDERS['add_supplier']) ?>
<?php else: ?>
  <p class="hint"><?= $word('ORDERS', 'new_text') ?></p>
  <form class="inline" method="post" action="/ui/purchasing/orders">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <label><?= $word('ORDERS', 'supplier') ?>
      <select name="supplier_id" required>
        <option value=""><?= $word('ORDERS', 'choose_supplier') ?></option>
<?php foreach ($newSuppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if ($filters['supplier'] === (int) $s['id']): ?> selected<?php endif; ?>><?php if ($s['status'] === 'active'): ?><?= $e($s['name']) ?><?php else: ?><?= $say('ORDERS', 'not_ready', (string) $s['name'], $s['status'] === 'draft' ? \CW\Ui\Words::ORDERS['not_approved'] : \CW\Ui\Words::ORDERS['waiting_ok']) ?><?php endif; ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="primary"><?= $word('ORDERS', 'new') ?></button>
  </form>
<?php endif; ?>
</details>
<?php endif; ?>
<?php if ($draftsHidden): ?>
<p class="note drafts-hidden"><?= $word('ORDERS', 'drafts_hidden') ?> <a href="<?= $u('/ui/purchasing/orders', ['supplier' => $filters['supplier'], 'q' => $filters['q'] === '' ? null : $filters['q'], 'rejected' => $filters['rejected'] ? '1' : null, 'show' => $filters['all'] ? 'all' : null, 'drafts' => '1']) ?>"><?= $word('ORDERS', 'drafts_show') ?></a></p>
<?php endif; ?>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::ORDERS['none_filter'], \CW\Ui\Words::ORDERS['none_filter_text'], '/ui/purchasing/orders', \CW\Ui\Words::ORDERS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::ORDERS['none'], \CW\Ui\Words::ORDERS[$canPost ? 'none_buyer' : 'none_text']) ?>
<?php else: ?>
<?php
$groups = [];
foreach ($rows as $r) {
    $groups[$r['reverses_id'] !== null ? 'cancellation' : $r['state_code']][] = $r;
}
$order = array_values(array_filter(['draft', 'awaiting_approval', 'approved', 'sent', 'part_received', 'received', 'closed', 'cancelled', 'cancellation'],
    static fn (string $g): bool => isset($groups[$g])));
?>
<div class="board-head"><h3 class="board-sub"><?= $word('ORDERS', 'board') ?></h3><p class="board-note"><?php if (count($rows) === 1): ?><?= $word('ORDERS', 'total_one') ?><?php else: ?><?= $say('ORDERS', 'total_many', count($rows)) ?><?php endif; ?></p></div>
<?php foreach ($order as $g): ?>
<?php $list = $groups[$g]; $tone = $list[0]['state_tone']; $name = $g === 'cancellation' ? \CW\Ui\Words::ORDERS['cancellation'] : \CW\Ui\Words::of('PO_STATE', $g); ?>
<div class="grp-block">
  <h3 class="grp-title <?= $e($tone) ?>"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $e($name) ?></button><span class="grp-count"><?php if (count($list) === 1): ?><?= $word('ORDERS', 'group_one') ?><?php else: ?><?= $say('ORDERS', 'group_many', count($list)) ?><?php endif; ?></span></h3>
  <div class="table-wrap" id="g-<?= $e($g) ?>">
  <table class="stack list board orders <?= $e($tone) ?>">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('ORDERS', 'order') ?></th>
        <th scope="col"><?= $word('ORDERS', 'col_supplier') ?></th>
        <th scope="col" class="c-status"><?= $word('ORDERS', 'col_status') ?></th>
        <th scope="col" class="num"><?= $word('ORDERS', 'col_value') ?></th>
        <th scope="col" class="num"><?= $word('ORDERS', 'col_units') ?></th>
        <th scope="col"><?= $word('ORDERS', 'col_expected') ?></th>
        <th scope="col"><?= $word('ORDERS', 'check') ?></th>
        <th scope="col"><?= $word('ORDERS', 'col_next') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr class="<?= $e($r['state_tone']) ?>">
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['number_line']) ?></a><?php if ($r['date_line'] !== null): ?><span class="o-sub"><?= $e($r['date_line']) ?><?php if ($r['sent_line'] !== null): ?> · <?= $e($r['sent_line']) ?><?php endif; ?></span><?php endif; ?></th>
        <td data-label="<?= $word('ORDERS', 'col_supplier') ?>"><?= $e($r['name']) ?></td>
        <td class="c-status"><?= $chip($r['state_tone'], $r['state_word']) ?></td>
        <td class="num" data-label="<?= $word('ORDERS', 'col_value') ?>"><?= $e($r['total']) ?></td>
        <td class="num" data-label="<?= $word('ORDERS', 'col_units') ?>"><?= $n($r['units']) ?><span class="o-sub"><?= $e($r['size_line']) ?></span></td>
        <td data-label="<?= $word('ORDERS', 'col_expected') ?>"><?php if ($r['expected_date'] !== null): ?><?= $day($r['expected_date']) ?><?php endif; ?></td>
        <td data-label="<?= $word('ORDERS', 'check') ?>"><?php if ($r['check'] !== null): ?><?= $chip($r['check_tone'], $r['check']) ?><?php endif; ?></td>
        <td class="c-wide" data-label="<?= $word('ORDERS', 'col_next') ?>"><?= $e($r['next']) ?></td>
      </tr>
<?php endforeach; ?>
<?php if ($g === 'draft' && $formKey !== null): ?>
      <tr class="grp-add"><td colspan="8" data-label=""><a class="add-item" href="#new"><?= $word('ORDERS', 'add_row') ?></a></td></tr>
<?php endif; ?>
<?php if ($g !== 'cancellation'): ?>
      <tr class="grp-foot">
        <th scope="row" class="c-head c-item"><?= $say('ORDERS', 'group_total', $name) ?></th>
        <td data-label=""></td>
        <td data-label=""></td>
        <td class="num" data-label="<?= $word('ORDERS', 'col_value') ?>"><?= $money(array_sum(array_map(static fn (array $x): float => (float) ($x['net_total'] ?? 0), $list))) ?></td>
        <td class="num" data-label="<?= $word('ORDERS', 'col_units') ?>"><?= $n(array_sum(array_map(static fn (array $x): int => (int) $x['units'], $list))) ?></td>
        <td data-label=""></td>
        <td data-label=""></td>
        <td data-label=""></td>
      </tr>
<?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('ORDERS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
