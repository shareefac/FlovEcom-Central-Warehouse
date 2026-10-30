<?php
// Purchases › Goods received, a simple list (the approved redesign of 30 Oct 2026, docs/decisions.md RD-B6; before it GI_List, GV1, GV13):
// one search (a scanned delivery note, the supplier, the order, the number, the invoice), one Show menu (today and open, all deliveries,
// cancelled; Clear under the toolbar's More) and "+ New delivery" at the head's right (the one way to start one: its start page,
// receipt_new; DC4); then the groups, each a heading with its coloured dot and its count (it folds, app.js) over a white table card:
// Arriving (the open orders still expecting goods, each with "Receive"), Being checked (how many lines are checked under the status pill),
// Ready to book in, Booked in today (Booked in under "all"; its incidents under the pill), Cancelled (under Show only). Five columns (and
// Arriving's Receive): the delivery or order, the supplier, the status, arrived or expected, the units. A row opens its delivery (or its
// order). The old links' state and supplier filters still narrow it.
?>
<?= $head(\CW\Ui\Words::MENU['receiving'], intro: 'receiving', look: $lookOnly, aside: $explain('posting', \CW\Ui\Words::RECEIVING['posting_label'])) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('GI', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('GI', 'search') ?>" autocomplete="off"></div>
  <?= $showStart() ?>
  <div class="tb-state">
    <label for="tb-show"><?= $word('GI', 'show') ?></label>
    <select id="tb-show" name="show">
      <option value=""<?php if ($filters['show'] === ''): ?> selected<?php endif; ?>><?= $word('GI', 'show_open') ?></option>
      <option value="all"<?php if ($filters['show'] === 'all'): ?> selected<?php endif; ?>><?= $word('GI', 'show_all') ?></option>
      <option value="cancelled"<?php if ($filters['show'] === 'cancelled'): ?> selected<?php endif; ?>><?= $word('GI', 'show_cancelled') ?></option>
    </select>
<?php if ($filters['state'] !== null): ?>
    <input type="hidden" name="state" value="<?= $e($filters['state']) ?>">
<?php endif; ?>
<?php if ($filters['supplier'] !== null): ?>
    <input type="hidden" name="supplier" value="<?= $e($filters['supplier']) ?>">
<?php endif; ?>
    <button type="submit" class="btn sm"><?= $word('GI', 'show_button') ?></button>
  </div>
  <?= $showEnd() ?>
<?php if ($filtered): ?>
  <div class="tb-end">
    <details class="more tb-more">
      <summary class="btn ghost sm"><?= $word('UI', 'more') ?></summary>
      <div class="more-menu">
        <a class="menu-item" href="/ui/receiving"><?= $word('GI', 'clear') ?></a>
      </div>
    </details>
  </div>
<?php endif; ?>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($groups === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::GI['none_filter'], \CW\Ui\Words::GI['none_filter_text'], '/ui/receiving', \CW\Ui\Words::GI['clear']) ?>
<?php elseif ($groups === []): ?>
<?= $empty(\CW\Ui\Words::GI['none'], \CW\Ui\Words::GI['none_text']) ?>
<?php endif; ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if ($g['key'] === 'arriving'): ?><?php if (count($g['rows']) === 1): ?><?= $word('GI', 'orders_one') ?><?php else: ?><?= $say('GI', 'orders_many', count($g['rows'])) ?><?php endif; ?><?php elseif (count($g['rows']) === 1): ?><?= $word('GI', 'deliveries_one') ?><?php else: ?><?= $say('GI', 'deliveries_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
<?php if ($g['key'] === 'arriving'): ?>
  <table class="stack list board doc-list gi-arriving">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('GI', 'col_order') ?></th>
        <th scope="col"><?= $word('GI', 'col_supplier') ?></th>
        <th scope="col" class="c-status"><?= $word('GI', 'col_status') ?></th>
        <th scope="col"><?= $word('GI', 'col_expected') ?></th>
        <th scope="col" class="num"><?= $word('GI', 'col_items') ?></th>
        <th scope="col" class="c-next"><span class="visually-hidden"><?= $word('GI', 'col_action') ?></span></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $o): ?>
      <tr>
        <th scope="row" class="c-head c-item"><?php if ($canSeeOrders): ?><a class="o-name" href="<?= $u('/ui/purchasing/orders/' . $o['id']) ?>"><?= $e($o['number']) ?></a><?php else: ?><span class="o-name"><?= $e($o['number']) ?></span><?php endif; ?></th>
        <td data-label="<?= $word('GI', 'col_supplier') ?>"><?= $e($o['supplier_name']) ?></td>
        <td class="c-status doc-st <?= $e($o['state_tone']) ?>"><span class="chip"><?= $e($o['state_word']) ?></span></td>
        <td data-label="<?= $word('GI', 'col_expected') ?>"<?php if ($o['late']): ?> class="late"<?php endif; ?>><?php if ($o['expected_date'] !== null): ?><?= $day($o['expected_date']) ?><?php if ($o['is_today']): ?> · <?= $word('GI', 'today') ?><?php endif; ?><?php else: ?><span class="muted"><?= $word('DOC_LIST', 'none') ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('GI', 'col_items') ?>"><?= $n($o['outstanding']) ?></td>
        <td class="c-next"><?php if ($o['formKey'] !== null): ?><form class="inline" method="post" action="/ui/receiving">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="form_key" value="<?= $e($o['formKey']) ?>">
          <input type="hidden" name="po_id" value="<?= $e($o['id']) ?>">
          <input type="hidden" name="copy" value="1">
          <button type="submit" class="btn sm"><?= $word('GI', 'receive') ?></button>
        </form><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php else: ?>
  <table class="stack list board doc-list receipts">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('GI', 'col_delivery') ?></th>
        <th scope="col"><?= $word('GI', 'col_supplier') ?></th>
        <th scope="col" class="c-status"><?= $word('GI', 'col_status') ?></th>
        <th scope="col"><?= $word('GI', 'col_arrived') ?></th>
        <th scope="col" class="num"><?= $word('GI', 'col_items') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/receiving/' . $r['id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['sub_line']) ?></span></th>
        <td data-label="<?= $word('GI', 'col_supplier') ?>"><?= $e($r['supplier_name']) ?></td>
<?php // The status pill (RD-B6): being checked says how many lines are checked under it (the old bar), a booked one its incidents. ?>
        <td class="c-status doc-st <?= $e($r['state_tone']) ?>"><span class="chip"><?= $e($r['board_word']) ?></span><?php if (in_array($g['key'], ['checking', 'ready'], true) && (int) $r['lines'] > 0): ?><span class="o-sub"><?= $say('GI', 'lines_of', (int) $r['checked_lines'], (int) $r['lines']) ?></span><?php elseif ($r['incidents_line'] !== null): ?><span class="o-sub"><?= $e($r['incidents_line']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('GI', 'col_arrived') ?>"><?= $e($r['arrived_line']) ?><?php if ((int) $r['paper_sheet'] === 1): ?> <span class="o-sub"><?= $word('RECEIVING', 'paper') ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('GI', 'col_items') ?>"><?= $n($r['units']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('GI', 'limit', $limit) ?></p>
<?php endif; ?>
