<?php
// Purchasing › Suppliers, a simple list (the approved redesign of 30 Oct 2026, docs/decisions.md RD-B6; before it W9.2): one search box
// (name, code, VAT number), one Show menu (every supplier or one status, with the two ticks: background check due soon, approved by one
// person), Export and "+ New supplier" at the right; then the suppliers grouped by whether we can order from them, each group a heading with
// its coloured dot and its count over a white table card, the status a small pill. A row opens its supplier. Home's links (?status=draft,
// ?due=1) still narrow the list.
?>
<?= $head(\CW\Ui\Words::MENU['suppliers'], intro: 'suppliers', look: $lookOnly) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('SUPPLIERS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('SUPPLIERS', 'search') ?>"></div>
  <div class="tb-state">
    <label for="tb-show"><?= $word('SUPPLIERS', 'filter') ?></label>
    <select id="tb-show" name="status">
      <option value=""><?= $word('SUPPLIERS', 'show_all') ?></option>
<?php foreach ($statuses as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($filters['status'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
    <label class="choice tb-check"><input type="checkbox" name="due" value="1"<?php if ($filters['due']): ?> checked<?php endif; ?>> <?= $say('SUPPLIERS', 'due', $dueDays) ?></label>
    <label class="choice tb-check"><input type="checkbox" name="alone" value="1"<?php if ($filters['alone']): ?> checked<?php endif; ?>> <?= $word('SUPPLIERS', 'alone_filter') ?></label>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="/ui/purchasing/suppliers"><?= $word('SUPPLIERS', 'clear') ?></a>
<?php endif; ?>
    <a class="btn ghost sm" href="<?= $u('/ui/purchasing/suppliers.csv', ['status' => $filters['status'], 'q' => $filters['q'], 'due' => $filters['due'] ? '1' : null, 'alone' => $filters['alone'] ? '1' : null]) ?>" title="<?= $word('SUPPLIERS', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
  </div>
</form>
<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::SUPPLIERS['none_filter'], \CW\Ui\Words::SUPPLIERS['none_filter_text'], '/ui/purchasing/suppliers', \CW\Ui\Words::SUPPLIERS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::SUPPLIERS['none'], \CW\Ui\Words::SUPPLIERS[$canManage ? 'none_text' : 'none_look']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('SUPPLIERS', 'group_one') ?><?php else: ?><?= $say('SUPPLIERS', 'group_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list suppliers">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('SUPPLIERS', 'supplier') ?></th>
        <th scope="col" class="c-status"><?= $word('SUPPLIERS', 'status') ?></th>
        <th scope="col"><?= $word('SUPPLIERS', 'next_check') ?></th>
        <th scope="col" class="num"><?= $word('SUPPLIERS', 'products') ?></th>
        <th scope="col"><?= $word('SUPPLIERS', 'approved') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/purchasing/suppliers/' . $r['id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?><?php if ((int) $r['is_overseas'] === 1): ?> · <?= $word('SUPPLIERS', 'abroad') ?><?php endif; ?></span></th>
        <td class="c-status doc-st <?= $e($r['tone']) ?>"><span class="chip"><?= $word('SUPPLIER_STATUS', (string) $r['status']) ?></span><?php if ($r['alone_open']): ?><span class="o-sub"><?= $word('SUPPLIERS', 'alone_chip') ?></span><?php endif; ?></td>
        <td data-label="<?= $word('SUPPLIERS', 'next_check') ?>"<?php if ($r['dd_overdue']): ?> class="late"<?php endif; ?>><?php if ($r['dd_next_review_on'] !== null): ?><?= $day($r['dd_next_review_on']) ?><?php endif; ?><?php if ($r['dd_overdue']): ?> · <?= $word('SUPPLIERS', 'overdue') ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('SUPPLIERS', 'products') ?>"><?= $n($r['items']) ?></td>
        <td data-label="<?= $word('SUPPLIERS', 'approved') ?>"><?php if ($r['approved_at'] !== null): ?><?= $say('SUPPLIERS', 'approved_line', \CW\Ui\Html::when($r['approved_at']), (string) ($r['approved_by_name'] ?? \CW\Ui\Words::ANOMALIES['set_up'])) ?><?php else: ?><span class="muted"><?= $word('SUPPLIERS', 'not_yet') ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('SUPPLIERS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
