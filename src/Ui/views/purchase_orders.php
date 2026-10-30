<?php
// Purchasing › Purchase orders, a simple list (the approved Purchases.dc.html of 30 Oct 2026; docs/decisions.md RD-B1, before PV18, DC16): the
// heading with "+ New purchase order" at its right (the layout's create button); one search (the number, the reference, the supplier) and one
// Show menu (open orders, all, closed and cancelled) with its Show button (no script needed); the rest of the old toolbar (download, show
// drafts too, clear) under the one More. Then the orders grouped by status, each group a heading with its coloured dot and its count over a
// white table card: waiting for approval, on the way, draft, received or closed lately (po.board_recent_days), cancelled (under Show). Five
// columns: the order (its number; it opens the order), the supplier, the status as a small pill (a part delivery says "28 of 30 received"),
// expected, the total; an empty value is "—". No progress bar, no initials (who made it and when are on the order). Behaviour item 8 (F305)
// stays: goods in and the desk see no drafts unless they ask (under More). The URL's other filters (status, supplier, sent back) still
// narrow the list (links from elsewhere use them) and a search keeps them (hidden fields); every filter on travels through More's links (DC30).
$keep = ['state' => $filters['state'], 'supplier' => $filters['supplier'], 'q' => $filters['q'] === '' ? null : $filters['q'], 'rejected' => $filters['rejected'] ? '1' : null,
    'show' => $filters['view'] === 'open' ? null : $filters['view']];
$csv = \CW\Ui\Html::url('/ui/purchasing/orders.csv', ['state' => $filters['state'], 'supplier' => $filters['supplier'], 'q' => $filters['q'], 'rejected' => $filters['rejected'] ? '1' : null,
    'show' => $filters['view'] === 'open' ? ($filters['all'] ? 'all' : null) : $filters['view'], 'drafts' => $filters['drafts'] ? null : '0']);
$moreItems = $draftsHidden || ($draftsChoice && $filters['drafts']) || $rows !== [] || $filtered;
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
<?php if ($filters['drafts'] === $draftsChoice): ?>
    <input type="hidden" name="drafts" value="<?php if ($filters['drafts']): ?>1<?php else: ?>0<?php endif; ?>">
<?php endif; ?>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
<?php if ($moreItems): ?>
  <div class="tb-end">
    <details class="more tb-more">
      <summary class="btn ghost sm"><?= $word('UI', 'more') ?></summary>
      <div class="more-menu">
<?php if ($draftsHidden): ?>
        <a class="menu-item" href="<?= $u('/ui/purchasing/orders', $keep + ['drafts' => '1']) ?>"><?= $word('ORDERS', 'drafts') ?></a>
<?php elseif ($draftsChoice && $filters['drafts']): ?>
        <a class="menu-item" href="<?= $u('/ui/purchasing/orders', $keep) ?>"><?= $word('ORDERS', 'drafts_off') ?></a>
<?php endif; ?>
<?php if ($rows !== []): ?>
        <a class="menu-item" href="<?= $e($csv) ?>" download><?= $word('ORDERS', 'download') ?></a>
<?php endif; ?>
<?php if ($filtered): ?>
        <a class="menu-item" href="/ui/purchasing/orders"><?= $word('ORDERS', 'clear') ?></a>
<?php endif; ?>
      </div>
    </details>
  </div>
<?php endif; ?>
</form>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::ORDERS['none_filter'], \CW\Ui\Words::ORDERS['none_filter_text'], '/ui/purchasing/orders', \CW\Ui\Words::ORDERS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::ORDERS['none'], \CW\Ui\Words::ORDERS[$canPost ? 'none_buyer' : 'none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?> rd-list">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('ORDERS', 'group_one') ?><?php else: ?><?= $say('ORDERS', 'group_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list orders">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('DOC_LIST', 'order') ?></th>
        <th scope="col"><?= $word('DOC_LIST', 'supplier') ?></th>
        <th scope="col" class="c-status"><?= $word('DOC_LIST', 'status') ?></th>
        <th scope="col"><?= $word('DOC_LIST', 'expected') ?></th>
        <th scope="col" class="num"><?= $word('DOC_LIST', 'total') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['number_line']) ?></a></th>
        <td data-label="<?= $word('DOC_LIST', 'supplier') ?>"><?= $e($r['name']) ?></td>
        <td class="c-status doc-st <?= $e($r['state_tone']) ?>"><span class="chip"><?= $e($r['pill_word']) ?></span><?php if ($r['check'] !== null && $r['review_state'] === 'rejected'): ?><span class="o-sub"><?= $e($r['check']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('DOC_LIST', 'expected') ?>"><?php if ($r['expected_date'] !== null): ?><?= $day($r['expected_date']) ?><?php else: ?><span class="muted"><?= $word('DOC_LIST', 'none') ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('DOC_LIST', 'total') ?>"><?php if ($r['gross'] !== null): ?><?= $e($r['gross']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('ORDERS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
