<?php
// Purchasing › Purchase Orders, a board (the owner's approved design of 29 Oct 2026, monday.com's style; docs/decisions.md PV18): one
// search box (the number, the reference, the supplier), one Show menu (open orders, all, closed and cancelled), Export and "+ New purchase
// order" at the right of the toolbar (PX1: the order's one page); then the orders grouped by status, each group a coloured heading with its
// count (it folds, app.js) over a white card with the group's colour on its left edge: waiting for approval, on the way (confirmed, sent,
// part delivered), draft (its last row "+ New purchase order"), received or closed lately (po.board_recent_days), cancelled (under Show).
// A row: the order (number and date), its status filled with its colour, the supplier, expected, received of ordered, the total. A row
// opens its order. Behaviour item 8 (F305) stays: goods in and the desk see no drafts unless they ask. The URL's other filters (status,
// supplier, sent back) still narrow the list (links from elsewhere use them) and a search keeps them (hidden fields).
$newActions = $newActions ?? [];
?>
<?= $head(\CW\Ui\Words::MENU['orders'], intro: 'orders', look: $lookOnly, aside: $explain('second_ok', \CW\Ui\Words::THING['second'])) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('ORDERS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('ORDERS', 'search') ?>"></div>
  <div class="tb-state">
    <label for="tb-show"><?= $word('ORDERS', 'show') ?></label>
    <select id="tb-show" name="show">
      <option value=""<?php if ($filters['view'] === 'open'): ?> selected<?php endif; ?>><?= $word('ORDERS', 'show_open') ?></option>
      <option value="all"<?php if ($filters['view'] === 'all'): ?> selected<?php endif; ?>><?= $word('ORDERS', 'show_all') ?></option>
      <option value="closed"<?php if ($filters['view'] === 'closed'): ?> selected<?php endif; ?>><?= $word('ORDERS', 'show_closed') ?></option>
    </select>
<?php if ($filters['state'] !== null): ?>
    <input type="hidden" name="state" value="<?= $e($filters['state']) ?>">
<?php endif; ?>
<?php if ($filters['supplier'] !== null): ?>
    <input type="hidden" name="supplier" value="<?= $e($filters['supplier']) ?>">
<?php endif; ?>
<?php if ($filters['rejected']): ?>
    <input type="hidden" name="rejected" value="1">
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
    <a class="btn ghost sm" href="<?= $u('/ui/purchasing/orders.csv', ['state' => $filters['state'], 'supplier' => $filters['supplier'], 'q' => $filters['q'], 'rejected' => $filters['rejected'] ? '1' : null, 'show' => $filters['view'] === 'open' ? ($filters['all'] ? 'all' : null) : $filters['view'], 'drafts' => $filters['drafts'] ? null : '0']) ?>" title="<?= $word('ORDERS', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
<?php endif; ?>
    <?= $partial('split', ['actions' => $newActions]) ?>
  </div>
</form>

<?php if ($draftsHidden): ?>
<p class="note drafts-hidden"><?= $word('ORDERS', 'drafts_hidden') ?> <a href="<?= $u('/ui/purchasing/orders', ['supplier' => $filters['supplier'], 'q' => $filters['q'] === '' ? null : $filters['q'], 'rejected' => $filters['rejected'] ? '1' : null, 'show' => $filters['view'] === 'open' ? null : $filters['view'], 'drafts' => '1']) ?>"><?= $word('ORDERS', 'drafts_show') ?></a></p>
<?php endif; ?>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::ORDERS['none_filter'], \CW\Ui\Words::ORDERS['none_filter_text'], '/ui/purchasing/orders', \CW\Ui\Words::ORDERS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::ORDERS['none'], \CW\Ui\Words::ORDERS[$canPost ? 'none_buyer' : 'none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('ORDERS', 'group_one') ?><?php else: ?><?= $say('ORDERS', 'group_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list orders">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('DOC_LIST', 'order') ?></th>
        <th scope="col" class="c-status"><?= $word('DOC_LIST', 'status') ?></th>
        <th scope="col"><?= $word('DOC_LIST', 'supplier') ?></th>
        <th scope="col"><?= $word('DOC_LIST', 'expected') ?></th>
        <th scope="col"><?= $word('DOC_LIST', 'received') ?></th>
        <th scope="col" class="num"><?= $word('DOC_LIST', 'total') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['number_line']) ?></a><span class="o-sub"><?php if ($r['doc_date'] !== null): ?><?= $day($r['doc_date']) ?><?php endif; ?><?php if ($r['external_ref'] !== null && $r['external_ref'] !== ''): ?> · <?= $e($r['external_ref']) ?><?php endif; ?></span></th>
        <td class="c-status doc-st <?= $e($r['state_tone']) ?>"><?= $e($r['state_word']) ?><?php if ($r['check'] !== null && $r['review_state'] === 'rejected'): ?><span class="o-sub"><?= $e($r['check']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('DOC_LIST', 'supplier') ?>"><?= $e($r['name']) ?></td>
        <td data-label="<?= $word('DOC_LIST', 'expected') ?>"><?php if ($r['expected_date'] !== null): ?><?= $day($r['expected_date']) ?><?php endif; ?></td>
        <td data-label="<?= $word('DOC_LIST', 'received') ?>"><?php if ((int) $r['units'] > 0 && $r['reverses_id'] === null): ?><span class="doc-meter"><progress max="<?= $e((int) $r['units']) ?>" value="<?= $e(min((int) $r['received'], (int) $r['units'])) ?>"></progress><span class="o-sub"><?= $say('DOC_LIST', 'received_of', (int) $r['received'], (int) $r['units']) ?></span></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('DOC_LIST', 'total') ?>"><?php if ($r['gross'] !== null): ?><?= $e($r['gross']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
<?php if ($g['key'] === 'draft' && $canPost): ?>
      <tr class="grp-add"><td colspan="6" data-label=""><a class="add-item" href="/ui/purchasing/orders/new"><?= $word('ORDERS', 'add_row') ?></a></td></tr>
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
