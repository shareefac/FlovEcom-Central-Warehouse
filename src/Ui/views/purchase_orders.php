<?php
// Purchasing › Purchase Orders, a clean list (docs/decisions.md PV, Zoho Inventory's list as the layout): one search box (the number, the
// reference, the supplier), a status filter, Export and "+ New purchase order" at the right of the toolbar (PX1: the order's one page), and
// one row an order: date, number, supplier, status, total, expected delivery. A row opens its order. Behaviour item 8 (F305) stays: goods
// in and the desk see no drafts unless they ask. The URL's other filters (supplier, sent back, with the cancellation records) still narrow
// the list (links from elsewhere use them) and a search keeps them (hidden fields).
$newActions = $newActions ?? [];
?>
<?= $head(\CW\Ui\Words::MENU['orders'], intro: 'orders', look: $lookOnly, aside: $explain('second_ok', \CW\Ui\Words::THING['second'])) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('ORDERS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('ORDERS', 'search') ?>"></div>
  <div class="tb-state">
    <label class="visually-hidden" for="tb-state"><?= $word('DOC_LIST', 'state_label') ?></label>
    <select id="tb-state" name="state">
      <option value=""><?= $word('DOC_LIST', 'any_state') ?></option>
<?php foreach ($states as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($filters['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
<?php if ($filters['supplier'] !== null): ?>
    <input type="hidden" name="supplier" value="<?= $e($filters['supplier']) ?>">
<?php endif; ?>
<?php if ($filters['rejected']): ?>
    <input type="hidden" name="rejected" value="1">
<?php endif; ?>
<?php if ($filters['all']): ?>
    <input type="hidden" name="show" value="all">
<?php endif; ?>
<?php if ($draftsChoice): ?>
    <label class="choice tb-check"><input type="checkbox" name="drafts" value="1"<?php if ($filters['drafts']): ?> checked<?php endif; ?>> <?= $word('ORDERS', 'drafts') ?></label>
<?php endif; ?>
    <button type="submit" class="btn secondary sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="/ui/purchasing/orders"><?= $word('ORDERS', 'clear') ?></a>
<?php endif; ?>
<?php if ($rows !== []): ?>
    <a class="btn ghost sm" href="<?= $u('/ui/purchasing/orders.csv', ['state' => $filters['state'], 'supplier' => $filters['supplier'], 'q' => $filters['q'], 'rejected' => $filters['rejected'] ? '1' : null, 'show' => $filters['all'] ? 'all' : null, 'drafts' => $filters['drafts'] ? null : '0']) ?>" title="<?= $word('ORDERS', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
<?php endif; ?>
    <?= $partial('split', ['actions' => $newActions]) ?>
  </div>
</form>

<?php if ($draftsHidden): ?>
<p class="note drafts-hidden"><?= $word('ORDERS', 'drafts_hidden') ?> <a href="<?= $u('/ui/purchasing/orders', ['supplier' => $filters['supplier'], 'q' => $filters['q'] === '' ? null : $filters['q'], 'rejected' => $filters['rejected'] ? '1' : null, 'show' => $filters['all'] ? 'all' : null, 'drafts' => '1']) ?>"><?= $word('ORDERS', 'drafts_show') ?></a></p>
<?php endif; ?>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::ORDERS['none_filter'], \CW\Ui\Words::ORDERS['none_filter_text'], '/ui/purchasing/orders', \CW\Ui\Words::ORDERS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::ORDERS['none'], \CW\Ui\Words::ORDERS[$canPost ? 'none_buyer' : 'none_text']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list doc-list orders">
  <thead>
    <tr>
      <th scope="col"><?= $word('DOC_LIST', 'date') ?></th>
      <th scope="col" class="c-item"><?= $word('DOC_LIST', 'number') ?></th>
      <th scope="col"><?= $word('DOC_LIST', 'supplier') ?></th>
      <th scope="col" class="c-status"><?= $word('DOC_LIST', 'status') ?></th>
      <th scope="col" class="num"><?= $word('DOC_LIST', 'total') ?></th>
      <th scope="col"><?= $word('DOC_LIST', 'expected') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?= $e($r['state_tone']) ?>">
      <td data-label="<?= $word('DOC_LIST', 'date') ?>"><?php if ($r['doc_date'] !== null): ?><?= $day($r['doc_date']) ?><?php endif; ?></td>
      <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['number_line']) ?></a><?php if ($r['sent_line'] !== null): ?><span class="o-sub"><?= $e($r['sent_line']) ?></span><?php elseif ($r['external_ref'] !== null && $r['external_ref'] !== ''): ?><span class="o-sub"><?= $e($r['external_ref']) ?></span><?php endif; ?></th>
      <td data-label="<?= $word('DOC_LIST', 'supplier') ?>"><?= $e($r['name']) ?></td>
      <td class="c-status"><?= $chip($r['state_tone'], $r['state_word']) ?><?php if ($r['check'] !== null && $r['review_state'] === 'rejected'): ?> <?= $chip($r['check_tone'], $r['check']) ?><?php endif; ?></td>
      <td class="num" data-label="<?= $word('DOC_LIST', 'total') ?>"><?php if ($r['gross_total'] !== null): ?><?= $money($r['gross_total']) ?><?php endif; ?></td>
      <td data-label="<?= $word('DOC_LIST', 'expected') ?>"><?php if ($r['expected_date'] !== null): ?><?= $day($r['expected_date']) ?><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('ORDERS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
