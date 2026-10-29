<?php
// Goods In › Duty register, a report (the owner's approved TP_Report; docs/decisions.md W9.10): Export at the head's right, the alarm when
// unstamped stock is still held, the figures strip (delivery lines, held unstamped, taken in on a declaration, the day the held place must
// be empty), one search box and the Show row (the dates, the supplier, the stamp, the delivery's state); then the register's lines as a
// board with the stamp filled with its colour, the unstamped stock and where it is, and what was taken in on a supplier's declaration.
$bar = $total > 0 ? $partial('export_bar', ['href' => $csv, 'title' => \CW\Ui\Words::say('DUTY', 'download', $max)]) : '';
?>
<div class="doc-head">
<?= $head(\CW\Ui\Words::SEGMENT['duty'], intro: 'duty', side: $bar) ?>
</div>
<?php if ($unstamped['alarm']): ?>
<p class="error" role="alert"><?= $say('DUTY', 'alarm', $unstamped['held_units'], \CW\Ui\Html::day($unstamped['from'])) ?></p>
<?php elseif ($unstamped['held_units'] > 0 && $unstamped['from'] !== null): ?>
<p class="note"><?= $say('DUTY', 'must_empty', $unstamped['held_units'], \CW\Ui\Html::day($unstamped['from'])) ?></p>
<?php endif; ?>

<section class="doc-progress rp-figs" aria-label="<?= $word('DUTY', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('DUTY', 'fig_lines') ?></p><p class="doc-step-big"><?= $n($total) ?></p><p class="step-sub"><?php if ($allLines): ?><?= $word('DUTY', 'lines_all') ?><?php else: ?><?= $word('DUTY', 'lines_stamp') ?><?php endif; ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('DUTY', 'fig_held') ?></p><p class="doc-step-big"><?= $n($unstamped['held_units']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('DUTY', 'fig_accepted') ?></p><p class="doc-step-big"><?= $n($unstamped['accepted_units']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('DUTY', 'fig_empty_by') ?></p><p class="doc-step-big"><?php if ($unstamped['held_units'] > 0 && $unstamped['from'] !== null): ?><?= $day($unstamped['from']) ?><?php else: ?><?= $word('DUTY', 'fig_none') ?><?php endif; ?></p></div>
</section>

<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('DUTY', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('DUTY', 'search') ?>"></div>
  <div class="tb-state">
    <label for="tb-from"><?= $word('DUTY', 'from') ?></label>
    <input id="tb-from" type="date" name="from" value="<?= $e($f['from'] ?? '') ?>">
    <label for="tb-to"><?= $word('DUTY', 'to') ?></label>
    <input id="tb-to" type="date" name="to" value="<?= $e($f['to'] ?? '') ?>">
  </div>
  <div class="tb-state">
    <label class="visually-hidden" for="tb-supplier"><?= $word('DUTY', 'supplier') ?></label>
    <select id="tb-supplier" name="supplier">
      <option value=""><?= $word('DUTY', 'any_supplier') ?></option>
<?php foreach ($suppliers as $s): ?>
      <option value="<?= $e($s['id']) ?>"<?php if ($f['supplier'] === $s['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
    </select>
    <label for="tb-stamp"><?= $word('DUTY', 'stamp') ?></label>
    <select id="tb-stamp" name="stamp">
      <option value=""><?= $word('DUTY', 'any_stamp') ?></option>
<?php foreach ($stampStates as $st): ?>
      <option value="<?= $e($st) ?>"<?php if ($f['stamp'] === $st): ?> selected<?php endif; ?>><?= $word('DUTY_STAMP', $st) ?></option>
<?php endforeach; ?>
    </select>
    <label for="tb-state"><?= $word('DUTY', 'state') ?></label>
    <select id="tb-state" name="state">
      <option value=""><?= $word('DUTY', 'any_state') ?></option>
      <option value="posted"<?php if ($f['state'] === 'posted'): ?> selected<?php endif; ?>><?= $word('DUTY', 'state_posted') ?></option>
      <option value="reversed"<?php if ($f['state'] === 'reversed'): ?> selected<?php endif; ?>><?= $word('DUTY', 'state_reversed') ?></option>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
<?php if ($filtered): ?>
  <div class="tb-end"><a class="btn ghost sm" href="/ui/receiving/duty"><?= $word('DUTY', 'clear') ?></a></div>
<?php endif; ?>
</form>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::DUTY['none_filter'], \CW\Ui\Words::DUTY['none_filter_text'], '/ui/receiving/duty', \CW\Ui\Words::DUTY['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::DUTY['none'], \CW\Ui\Words::DUTY['none_text'], '/ui/receiving', \CW\Ui\Words::DUTY['none_link']) ?>
<?php else: ?>
<div class="grp-block doc-group info">
  <h2 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-register"><?= $word('DUTY', 'register') ?></button> <span class="grp-count"><?= $say('DUTY', 'count', $total) ?><?php if ($pages > 1): ?> · <?= $say('DUTY', 'page', $page, $pages) ?><?php endif; ?></span></h2>
  <div class="table-wrap doc-board" id="g-register">
  <table class="stack list board doc-list duty-register">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('DUTY', 'arrived') ?></th>
        <th scope="col" class="c-status"><?= $word('DUTY', 'stamp') ?></th>
        <th scope="col"><?= $word('DUTY', 'supplier') ?></th>
        <th scope="col"><?= $word('DUTY', 'item') ?></th>
        <th scope="col" class="num"><?= $word('DUTY', 'units') ?></th>
        <th scope="col"><?= $word('DUTY', 'check') ?></th>
        <th scope="col"><?= $word('DUTY', 'unstamped') ?></th>
        <th scope="col"><?= $word('DUTY', 'declaration') ?></th>
        <th scope="col" class="num"><?= $word('DUTY', 'expected') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr<?php if ($r['status'] === 'reversed'): ?> class="off"<?php endif; ?>>
        <th scope="row" class="c-head c-item"><?= $when($r['received_at']) ?><span class="o-sub"><a href="<?= $u('/ui/receiving/' . $r['document_id']) ?>"><?= $e($r['number']) ?></a><?php if ($r['status'] === 'reversed'): ?> &middot; <?= $word('DUTY', 'state_reversed') ?><?php endif; ?></span></th>
        <td class="c-status doc-st <?= $e(\CW\Ui\Words::tone('DUTY_STAMP', (string) $r['stamp_state'])) ?>"><?= $word('DUTY_STAMP', (string) $r['stamp_state']) ?></td>
        <td data-label="<?= $word('DUTY', 'supplier') ?>"><?= $e($r['supplier']) ?><span class="o-sub"><?= $say('DUTY', 'invoice', (string) ($r['supplier_invoice'] ?? '')) ?><?php if ($r['delivery_note'] !== null): ?> &middot; <?= $say('DUTY', 'delivery_note', (string) $r['delivery_note']) ?><?php endif; ?></span></td>
        <td data-label="<?= $word('DUTY', 'item') ?>"><?= $e($r['sku_name']) ?><span class="o-sub"><?= $e($r['sku_code']) ?><?php if ($r['barcode'] !== null): ?> &middot; <?= $e($r['barcode']) ?><?php endif; ?><?php if ($r['duty_ml'] !== null): ?> &middot; <?= $say('DUTY', 'ml', \CW\Ui\Html::dec($r['duty_ml'])) ?><?php endif; ?></span></td>
        <td class="num" data-label="<?= $word('DUTY', 'units') ?>"><?= $n($r['units']) ?><span class="o-sub"><?= $say('DUTY', 'went', (int) ($r['accepted_units'] ?? 0), (int) ($r['quarantine_units'] ?? 0), (int) ($r['refused_units'] ?? 0)) ?></span></td>
        <td data-label="<?= $word('DUTY', 'check') ?>"><?php if ($r['stamp_on_pack'] === null): ?><span class="muted"><?= $word('DUTY', 'not_checked') ?></span><?php else: ?><?php if ($r['stamp_on_pack']): ?><?= $word('DUTY', 'on_pack_yes') ?><?php else: ?><?= $word('DUTY', 'on_pack_no') ?><?php endif; ?><?php if ($r['stamp_type'] !== null): ?> &middot; <?= $word('STAMP_TYPE', $r['stamp_type']) ?><?php endif; ?><?php if ($r['stamp_code'] !== null): ?><span class="o-sub"><?= $e($r['stamp_code']) ?></span><?php endif; ?><?php endif; ?><?php if ($r['checked_by'] !== null): ?><span class="o-sub"><?= $say('DUTY', 'checked_by', (string) $r['checked_by']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('DUTY', 'unstamped') ?>"><?php if ($r['unstamped_total'] > 0): ?><?= $n($r['unstamped_total']) ?><?php if ($r['unstamped_action'] !== null): ?> &middot; <?= $word('UNSTAMPED_ACTION', $r['unstamped_action']) ?><?php endif; ?><?php if ($r['incidents'] > 0): ?><span class="o-sub"><a href="/ui/receiving/incidents"><?= $say('DUTY', 'incidents', $r['incidents']) ?></a></span><?php endif; ?><?php else: ?><span class="muted"><?= $word('DUTY', 'none_unstamped') ?></span><?php endif; ?></td>
        <td data-label="<?= $word('DUTY', 'declaration') ?>"><?php if ($r['pre_october_evidence'] !== null): ?><?= $e($r['pre_october_evidence']) ?><?php if ($r['evidence_file']): ?><span class="o-sub"><?= $word('DUTY', 'evidence_file') ?></span><?php endif; ?><?php else: ?><span class="muted"><?= $word('DUTY', 'no_declaration') ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('DUTY', 'expected') ?>"><?php if ($r['expected_duty'] !== null): ?><?= $money($r['expected_duty']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php if ($pages > 1): ?>
<p class="pager"><?php if ($prev !== null): ?><a href="<?= $e($prev) ?>"><?= $word('DUTY', 'prev') ?></a> <?php endif; ?><?= $say('DUTY', 'page', $page, $pages) ?><?php if ($next !== null): ?> <a href="<?= $e($next) ?>"><?= $word('DUTY', 'next') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>

<div class="grp-block doc-group <?php if ($unstamped['held'] === []): ?>done<?php else: ?>needs<?php endif; ?>">
  <h2 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-held"><?= $word('DUTY', 'held_title') ?></button> <span class="grp-count"><?= $say('DUTY', 'held_total', $unstamped['held_units']) ?></span></h2>
  <div id="g-held">
<?php if ($unstamped['warehouses'] === []): ?>
  <p class="muted"><?= $word('DUTY', 'no_unstamped_place') ?></p>
<?php elseif ($unstamped['held'] === []): ?>
  <p><?= $chip('done', \CW\Ui\Words::DUTY['held_none']) ?></p>
<?php else: ?>
  <div class="table-wrap doc-board">
  <table class="stack list board duty-held">
    <thead>
      <tr>
        <th scope="col"><?= $word('DUTY', 'item') ?></th>
        <th scope="col"><?= $word('DUTY', 'where') ?></th>
        <th scope="col" class="num"><?= $word('DUTY', 'on_hand') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($unstamped['held'] as $h): ?>
      <tr>
        <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $h['sku_id']) ?>"><?= $e($h['name']) ?></a><span class="o-sub"><?= $e($h['code']) ?></span></th>
        <td data-label="<?= $word('DUTY', 'where') ?>"><?= $e($h['warehouse']) ?></td>
        <td class="num" data-label="<?= $word('DUTY', 'on_hand') ?>"><?= $n($h['on_hand']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php if ($unstamped['held_more']): ?>
  <p class="muted"><?= $say('DUTY', 'list_more', \CW\Duty\DutyRegister::UNSTAMPED_LIMIT) ?></p>
<?php endif; ?>
<?php endif; ?>
  </div>
</div>

<div class="grp-block doc-group info">
  <h2 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-accepted"><?= $word('DUTY', 'accepted_title') ?></button> <span class="grp-count"><?= $say('DUTY', 'accepted_total', $unstamped['accepted_units']) ?></span></h2>
  <div id="g-accepted">
  <p class="muted"><?= $word('DUTY', 'accepted_text') ?></p>
<?php if ($unstamped['accepted'] === []): ?>
  <p class="muted"><?= $word('DUTY', 'accepted_none') ?></p>
<?php else: ?>
  <div class="table-wrap doc-board">
  <table class="stack list board duty-accepted">
    <thead>
      <tr>
        <th scope="col"><?= $word('DUTY', 'item') ?></th>
        <th scope="col"><?= $word('DUTY', 'where') ?></th>
        <th scope="col" class="num"><?= $word('DUTY', 'accepted_units') ?></th>
        <th scope="col"><?= $word('DUTY', 'last_arrived') ?></th>
        <th scope="col" class="num"><?= $word('DUTY', 'on_hand_now') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($unstamped['accepted'] as $a): ?>
      <tr>
        <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $a['sku_id']) ?>"><?= $e($a['name']) ?></a><span class="o-sub"><?= $e($a['code']) ?></span></th>
        <td data-label="<?= $word('DUTY', 'where') ?>"><?= $e($a['warehouse']) ?></td>
        <td class="num" data-label="<?= $word('DUTY', 'accepted_units') ?>"><?= $n($a['units']) ?></td>
        <td data-label="<?= $word('DUTY', 'last_arrived') ?>"><?= $when($a['last_received']) ?></td>
        <td class="num" data-label="<?= $word('DUTY', 'on_hand_now') ?>"><?= $n($a['on_hand']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php if ($unstamped['accepted_more']): ?>
  <p class="muted"><?= $say('DUTY', 'list_more', \CW\Duty\DutyRegister::UNSTAMPED_LIMIT) ?></p>
<?php endif; ?>
<?php endif; ?>
  </div>
</div>
