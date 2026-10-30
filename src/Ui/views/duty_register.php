<?php
// Goods received › Duty register, a report (the approved Reports artboard of 30 Oct 2026, the standard report layout; docs/decisions.md RD-C11;
// before it W9.10): "‹ All reports" and the title, Export (the CSV) at the right; the alarm when unstamped stock is still held; the options CW
// has (find, the dates, the supplier, the stamp, the delivery's state) and Run report; then the report: its centred head (the company, the
// report's name, the dates, its figures), a plain table of the register's lines (the stamp a pill), then its two groups: the unstamped stock
// held and where, what was taken in on a supplier's declaration, each with its total; "All reports" under it.
$bar = $total > 0 ? $partial('report_bar', ['href' => $csv]) : '';
$line = implode(' · ', array_filter([$f['from'] !== null || $f['to'] !== null ? \CW\Ui\Words::say('RD_REPORTS', 'period_line', $f['from'] !== null ? \CW\Ui\Html::day($f['from']) : '…',
    $f['to'] !== null ? \CW\Ui\Html::day($f['to']) : \CW\Ui\Html::day($today)) : \CW\Ui\Words::say('RD_REPORTS', 'as_of', \CW\Ui\Html::day($today)),
    $allLines ? \CW\Ui\Words::DUTY['lines_all'] : \CW\Ui\Words::DUTY['lines_stamp']], static fn (string $v): bool => $v !== ''));
$more = array_values(array_filter([\CW\Ui\Words::say('RD_REPORTS', 'duty_line', \CW\Ui\Html::int($total), \CW\Ui\Html::int($unstamped['held_units']), \CW\Ui\Html::int($unstamped['accepted_units'])),
    $unstamped['held_units'] > 0 && $unstamped['from'] !== null ? \CW\Ui\Words::say('RD_REPORTS', 'empty_by', \CW\Ui\Html::day($unstamped['from'])) : null], static fn (?string $v): bool => $v !== null));
?>
<div class="doc-head rp-head">
<?= $head(\CW\Ui\Words::RD_REPORTS['duty'], intro: 'duty', side: $bar, back: ['#all-reports', \CW\Ui\Words::RD_REPORTS['all']]) ?>
</div>
<?php if ($unstamped['alarm']): ?>
<p class="error" role="alert"><?= $say('DUTY', 'alarm', $unstamped['held_units'], \CW\Ui\Html::day($unstamped['from'])) ?></p>
<?php elseif ($unstamped['held_units'] > 0 && $unstamped['from'] !== null): ?>
<p class="note"><?= $say('DUTY', 'must_empty', $unstamped['held_units'], \CW\Ui\Html::day($unstamped['from'])) ?></p>
<?php endif; ?>

<form class="card rp-options" method="get" aria-label="<?= $word('RD_REPORTS', 'options') ?>">
  <div class="rp-opt rp-find"><label for="rp-q"><?= $word('RD_REPORTS', 'find') ?></label><input id="rp-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('DUTY', 'search') ?>"></div>
  <div class="rp-opt"><label for="rp-from"><?= $word('DUTY', 'from') ?></label><input id="rp-from" type="date" name="from" value="<?= $e($f['from'] ?? '') ?>"></div>
  <div class="rp-opt"><label for="rp-to"><?= $word('DUTY', 'to') ?></label><input id="rp-to" type="date" name="to" value="<?= $e($f['to'] ?? '') ?>"></div>
  <div class="rp-opt"><label for="rp-supplier"><?= $word('DUTY', 'supplier') ?></label>
    <select id="rp-supplier" name="supplier">
      <option value=""><?= $word('DUTY', 'any_supplier') ?></option>
<?php foreach ($suppliers as $s): ?>
      <option value="<?= $e($s['id']) ?>"<?php if ($f['supplier'] === $s['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div class="rp-opt"><label for="rp-stamp"><?= $word('DUTY', 'stamp') ?></label>
    <select id="rp-stamp" name="stamp">
      <option value=""><?= $word('DUTY', 'any_stamp') ?></option>
<?php foreach ($stampStates as $st): ?>
      <option value="<?= $e($st) ?>"<?php if ($f['stamp'] === $st): ?> selected<?php endif; ?>><?= $word('DUTY_STAMP', $st) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div class="rp-opt"><label for="rp-state"><?= $word('DUTY', 'state') ?></label>
    <select id="rp-state" name="state">
      <option value=""><?= $word('DUTY', 'any_state') ?></option>
      <option value="posted"<?php if ($f['state'] === 'posted'): ?> selected<?php endif; ?>><?= $word('DUTY', 'state_posted') ?></option>
      <option value="reversed"<?php if ($f['state'] === 'reversed'): ?> selected<?php endif; ?>><?= $word('DUTY', 'state_reversed') ?></option>
    </select>
  </div>
  <button type="submit" class="btn primary"><?= $word('RD_REPORTS', 'run') ?></button>
<?php if ($filtered): ?>
  <a class="rp-clear" href="/ui/receiving/duty"><?= $word('RD_REPORTS', 'clear') ?></a>
<?php endif; ?>
</form>

<article class="card rp-paper" aria-label="<?= $word('RD_REPORTS', 'paper') ?>">
<?= $partial('report_paper_head', ['company' => $company, 'name' => \CW\Ui\Words::RD_REPORTS['duty'], 'line' => $line, 'more' => $more]) ?>
<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::DUTY['none_filter'], \CW\Ui\Words::DUTY['none_filter_text'], '/ui/receiving/duty', \CW\Ui\Words::RD_REPORTS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::DUTY['none'], \CW\Ui\Words::DUTY['none_text'], '/ui/receiving', \CW\Ui\Words::DUTY['none_link']) ?>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack rp-table duty-register">
    <thead>
      <tr>
        <th scope="col"><?= $word('DUTY', 'arrived') ?></th>
        <th scope="col"><?= $word('DUTY', 'stamp') ?></th>
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
        <th scope="row"><?= $when($r['received_at']) ?><span class="o-sub"><a href="<?= $u('/ui/receiving/' . $r['document_id']) ?>"><?= $e($r['number']) ?></a><?php if ($r['status'] === 'reversed'): ?> &middot; <?= $word('DUTY', 'state_reversed') ?><?php endif; ?></span></th>
        <td data-label="<?= $word('DUTY', 'stamp') ?>"><?= $chip(\CW\Ui\Words::tone('DUTY_STAMP', (string) $r['stamp_state']), \CW\Ui\Words::of('DUTY_STAMP', (string) $r['stamp_state'])) ?></td>
        <td data-label="<?= $word('DUTY', 'supplier') ?>"><?= $e($r['supplier']) ?><span class="o-sub"><?= $say('DUTY', 'invoice', (string) ($r['supplier_invoice'] ?? '')) ?><?php if ($r['delivery_note'] !== null): ?> &middot; <?= $say('DUTY', 'delivery_note', (string) $r['delivery_note']) ?><?php endif; ?></span></td>
        <td data-label="<?= $word('DUTY', 'item') ?>"><?= $e($r['sku_name']) ?><span class="o-sub"><?= $e($r['sku_code']) ?><?php if ($r['barcode'] !== null): ?> &middot; <?= $e($r['barcode']) ?><?php endif; ?><?php if ($r['duty_ml'] !== null): ?> &middot; <?= $say('DUTY', 'ml', \CW\Ui\Html::dec($r['duty_ml'])) ?><?php endif; ?></span></td>
        <td class="num" data-label="<?= $word('DUTY', 'units') ?>"><?= $n($r['units']) ?><span class="o-sub"><?= $say('DUTY', 'went', (int) ($r['accepted_units'] ?? 0), (int) ($r['quarantine_units'] ?? 0), (int) ($r['refused_units'] ?? 0)) ?></span></td>
        <td data-label="<?= $word('DUTY', 'check') ?>"><?php if ($r['stamp_on_pack'] === null): ?><span class="muted"><?= $word('DUTY', 'not_checked') ?></span><?php else: ?><?php if ($r['stamp_on_pack']): ?><?= $word('DUTY', 'on_pack_yes') ?><?php else: ?><?= $word('DUTY', 'on_pack_no') ?><?php endif; ?><?php if ($r['stamp_type'] !== null): ?> &middot; <?= $word('STAMP_TYPE', $r['stamp_type']) ?><?php endif; ?><?php if ($r['stamp_code'] !== null): ?><span class="o-sub"><?= $e($r['stamp_code']) ?></span><?php endif; ?><?php endif; ?><?php if ($r['checked_by'] !== null): ?><span class="o-sub"><?= $say('DUTY', 'checked_by', (string) $r['checked_by']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('DUTY', 'unstamped') ?>"><?php if ($r['unstamped_total'] > 0): ?><?= $n($r['unstamped_total']) ?><?php if ($r['unstamped_action'] !== null): ?> &middot; <?= $word('UNSTAMPED_ACTION', $r['unstamped_action']) ?><?php endif; ?><?php if ($r['incidents'] > 0): ?><span class="o-sub"><a href="/ui/receiving/incidents"><?= $say('DUTY', 'incidents', $r['incidents']) ?></a></span><?php endif; ?><?php else: ?><span class="muted"><?= $word('DUTY', 'none_unstamped') ?></span><?php endif; ?></td>
        <td data-label="<?= $word('DUTY', 'declaration') ?>"><?php if ($r['pre_october_evidence'] !== null): ?><?= $e($r['pre_october_evidence']) ?><?php if ($r['evidence_file']): ?><span class="o-sub"><?= $word('DUTY', 'evidence_file') ?></span><?php endif; ?><?php else: ?><span class="muted"><?= $word('DUTY', 'no_declaration') ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('DUTY', 'expected') ?>"><?php if ($r['expected_duty'] !== null): ?><?= $money($r['expected_duty']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
      <tr class="rp-total">
        <th scope="row" colspan="9"><?= $word('RD_REPORTS', 'total') ?>: <?= $say('DUTY', 'count', $total) ?><?php if ($pages > 1): ?> · <?= $say('DUTY', 'page', $page, $pages) ?><?php endif; ?></th>
      </tr>
    </tbody>
  </table>
  </div>
<?php endif; ?>
  <div class="table-wrap">
  <table class="stack rp-table duty-held">
    <thead>
      <tr>
        <th scope="col"><?= $word('DUTY', 'item') ?></th>
        <th scope="col"><?= $word('DUTY', 'where') ?></th>
        <th scope="col" class="num"><?= $word('DUTY', 'on_hand') ?></th>
      </tr>
    </thead>
    <tbody>
      <tr class="rp-group"><th scope="rowgroup" colspan="3"><?= $word('DUTY', 'held_title') ?></th></tr>
<?php if ($unstamped['warehouses'] === []): ?>
      <tr><td colspan="3" class="muted rp-in" data-label=""><?= $word('DUTY', 'no_unstamped_place') ?></td></tr>
<?php elseif ($unstamped['held'] === []): ?>
      <tr><td colspan="3" class="rp-in" data-label=""><?= $chip('done', \CW\Ui\Words::DUTY['held_none']) ?></td></tr>
<?php endif; ?>
<?php foreach ($unstamped['held'] as $h): ?>
      <tr>
        <th scope="row"><a href="<?= $u('/ui/items/' . $h['sku_id']) ?>"><?= $e($h['name']) ?></a><span class="o-sub"><?= $e($h['code']) ?></span></th>
        <td data-label="<?= $word('DUTY', 'where') ?>"><?= $e($h['warehouse']) ?></td>
        <td class="num" data-label="<?= $word('DUTY', 'on_hand') ?>"><?= $n($h['on_hand']) ?></td>
      </tr>
<?php endforeach; ?>
<?php if ($unstamped['held_more']): ?>
      <tr><td colspan="3" class="muted rp-in" data-label=""><?= $say('DUTY', 'list_more', \CW\Duty\DutyRegister::UNSTAMPED_LIMIT) ?></td></tr>
<?php endif; ?>
      <tr class="rp-gtotal"><th scope="row" colspan="2" class="rp-in"><?= $say('RD_REPORTS', 'total_for', \CW\Ui\Words::DUTY['held_title']) ?></th><td class="num" data-label="<?= $word('DUTY', 'on_hand') ?>"><?= $n($unstamped['held_units']) ?></td></tr>
    </tbody>
  </table>
  </div>
  <div class="table-wrap">
  <table class="stack rp-table duty-accepted">
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
      <tr class="rp-group"><th scope="rowgroup" colspan="5"><?= $word('DUTY', 'accepted_title') ?> <span class="muted rp-gnote"><?= $word('DUTY', 'accepted_text') ?></span></th></tr>
<?php if ($unstamped['accepted'] === []): ?>
      <tr><td colspan="5" class="muted rp-in" data-label=""><?= $word('DUTY', 'accepted_none') ?></td></tr>
<?php endif; ?>
<?php foreach ($unstamped['accepted'] as $a): ?>
      <tr>
        <th scope="row"><a href="<?= $u('/ui/items/' . $a['sku_id']) ?>"><?= $e($a['name']) ?></a><span class="o-sub"><?= $e($a['code']) ?></span></th>
        <td data-label="<?= $word('DUTY', 'where') ?>"><?= $e($a['warehouse']) ?></td>
        <td class="num" data-label="<?= $word('DUTY', 'accepted_units') ?>"><?= $n($a['units']) ?></td>
        <td data-label="<?= $word('DUTY', 'last_arrived') ?>"><?= $when($a['last_received']) ?></td>
        <td class="num" data-label="<?= $word('DUTY', 'on_hand_now') ?>"><?= $n($a['on_hand']) ?></td>
      </tr>
<?php endforeach; ?>
<?php if ($unstamped['accepted_more']): ?>
      <tr><td colspan="5" class="muted rp-in" data-label=""><?= $say('DUTY', 'list_more', \CW\Duty\DutyRegister::UNSTAMPED_LIMIT) ?></td></tr>
<?php endif; ?>
      <tr class="rp-gtotal"><th scope="row" colspan="2" class="rp-in"><?= $say('RD_REPORTS', 'total_for', \CW\Ui\Words::DUTY['accepted_title']) ?></th><td class="num" data-label="<?= $word('DUTY', 'accepted_units') ?>"><?= $n($unstamped['accepted_units']) ?></td><td colspan="2" data-label=""></td></tr>
    </tbody>
  </table>
  </div>
</article>
<?php if ($pages > 1): ?>
<p class="pager"><?php if ($prev !== null): ?><a href="<?= $e($prev) ?>"><?= $word('DUTY', 'prev') ?></a> <?php endif; ?><?= $say('DUTY', 'page', $page, $pages) ?><?php if ($next !== null): ?> <a href="<?= $e($next) ?>"><?= $word('DUTY', 'next') ?></a><?php endif; ?></p>
<?php endif; ?>
<?= $partial('report_all', ['groups' => $allReports]) ?>
