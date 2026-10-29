<?php
// Purchasing › Goods in, a board (the owner's approved design of 29 Oct 2026, GI_List; docs/decisions.md GV1, GV13): one search (a scanned
// delivery note, the supplier, the order, the number, the invoice), one Show menu (today and open, all deliveries, cancelled) and "+ New
// delivery" at the right of the toolbar (the one way to start one: its start page, receipt_new); then the groups, each a coloured
// heading with its count (it folds, app.js) over a white card with its colour on the left edge: Arriving (the open orders still expecting
// goods, each with "Receive"), Being checked (the bench's progress as a bar), Ready to book in, Booked in today (Booked in under "all"),
// Cancelled (under Show only). A row opens its delivery (or its order). The old links' state and supplier filters still narrow it.
$newActions = $newActions ?? [];
?>
<?= $head(\CW\Ui\Words::MENU['receiving'], intro: 'receiving', look: $lookOnly, aside: $explain('posting', \CW\Ui\Words::RECEIVING['posting_label'])) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('GI', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('GI', 'search') ?>" autocomplete="off"></div>
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
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="/ui/receiving"><?= $word('GI', 'clear') ?></a>
<?php endif; ?>
    <?= $partial('split', ['actions' => $newActions]) ?>
  </div>
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
        <th scope="col" class="c-status"><?= $word('GI', 'col_status') ?></th>
        <th scope="col"><?= $word('GI', 'col_supplier') ?></th>
        <th scope="col"><?= $word('GI', 'col_expected') ?></th>
        <th scope="col" class="num"><?= $word('GI', 'col_items') ?></th>
        <th scope="col" class="c-next"><span class="visually-hidden"><?= $word('GI', 'col_action') ?></span></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $o): ?>
      <tr>
        <th scope="row" class="c-head c-item"><?php if ($canSeeOrders): ?><a class="o-name" href="<?= $u('/ui/purchasing/orders/' . $o['id']) ?>"><?= $e($o['number']) ?></a><?php else: ?><span class="o-name"><?= $e($o['number']) ?></span><?php endif; ?> <span class="o-sub"><?= $say('GI', 'to_come', (int) $o['outstanding']) ?></span></th>
        <td class="c-status doc-st <?= $e($o['state_tone']) ?>"><?= $e($o['state_word']) ?></td>
        <td data-label="<?= $word('GI', 'col_supplier') ?>"><?= $e($o['supplier_name']) ?></td>
        <td data-label="<?= $word('GI', 'col_expected') ?>"<?php if ($o['late']): ?> class="late"<?php endif; ?>><?php if ($o['expected_date'] !== null): ?><?= $day($o['expected_date']) ?><?php if ($o['is_today']): ?> · <?= $word('GI', 'today') ?><?php endif; ?><?php endif; ?></td>
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
        <th scope="col" class="c-status"><?= $word('GI', 'col_status') ?></th>
        <th scope="col"><?= $word('GI', 'col_supplier') ?></th>
        <th scope="col"><?= $word('GI', 'col_arrived') ?></th>
<?php if (in_array($g['key'], ['checking', 'ready'], true)): ?>
        <th scope="col"><?= $word('GI', 'col_checked') ?></th>
<?php else: ?>
        <th scope="col"><?= $word('GI', 'col_booked_by') ?></th>
<?php endif; ?>
        <th scope="col" class="num"><?= $word('GI', 'col_items') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/receiving/' . $r['id']) ?>"><?= $e($r['name']) ?></a> <span class="o-sub"><?= $e($r['sub_line']) ?></span></th>
        <td class="c-status doc-st <?= $e($r['state_tone']) ?>"><?= $e($r['state_word']) ?><?php if ($r['problems_line'] !== null): ?><span class="o-sub"><?= $e($r['problems_line']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('GI', 'col_supplier') ?>"><?= $e($r['supplier_name']) ?></td>
        <td data-label="<?= $word('GI', 'col_arrived') ?>"><?= $when($r['received_at']) ?><?php if ((int) $r['paper_sheet'] === 1): ?> <span class="o-sub"><?= $word('RECEIVING', 'paper') ?></span><?php endif; ?></td>
<?php if (in_array($g['key'], ['checking', 'ready'], true)): ?>
        <td data-label="<?= $word('GI', 'col_checked') ?>"><?php if ((int) $r['lines'] > 0): ?><span class="doc-meter"><progress class="review" max="<?= $e((int) $r['lines']) ?>" value="<?= $e(min((int) $r['checked_lines'], (int) $r['lines'])) ?>"></progress><span class="o-sub"><?= $say('GI', 'lines_of', (int) $r['checked_lines'], (int) $r['lines']) ?></span></span><?php endif; ?></td>
<?php else: ?>
        <td data-label="<?= $word('GI', 'col_booked_by') ?>"><?= $e($r['posted_by_name'] ?? '') ?><?php if ($r['incidents_line'] !== null): ?> <span class="tag needs"><?= $e($r['incidents_line']) ?></span><?php endif; ?></td>
<?php endif; ?>
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
