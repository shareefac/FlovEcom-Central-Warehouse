<?php
// Purchases › Purchase invoices › To match, a simple list (the approved redesign of 30 Oct 2026, docs/decisions.md RD-B6; before it W9.8):
// one Show menu (to check, or the ones whose differences were accepted), "Check these again" at the head's right; the tolerances in one
// line with their link; then the invoices grouped by their match, each group a heading with its coloured dot and its count over a white
// table card; five columns: the invoice (its supplier's number under it), the supplier, the match as a small pill (how many lines differ,
// its claims and, accepted, who accepted them under it), made final (by whom under it), the difference. A row opens its invoice.
$bar = $canRecheck ? $partial('recheck_bar', []) : '';
?>
<div class="doc-head">
<?= $head(\CW\Ui\Words::SEGMENT['to_match'], intro: 'to_match', side: $bar) ?>
</div>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-state">
    <label for="tb-show"><?= $word('ORDERS', 'show') ?></label>
    <select id="tb-show" name="show">
      <option value=""><?= $word('INVOICES', 'show_to_check_short') ?></option>
      <option value="accepted"<?php if ($show === 'accepted'): ?> selected<?php endif; ?>><?= $word('INVOICES', 'show_accepted_short') ?></option>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<p class="muted"><?= $e($rules) ?> <a href="/ui/reference/settings"><?= $word('INVOICES', 'rules_link') ?></a></p>

<?php if ($rows === []): ?>
<?php if ($show === 'accepted'): ?>
<?= $empty(\CW\Ui\Words::INVOICES['none_accepted'], '') ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::INVOICES['none_to_match'], \CW\Ui\Words::INVOICES['none_to_match_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?> rd-list">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('ARRIVALS', 'group_one') ?><?php else: ?><?= $say('ARRIVALS', 'group_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list to-match">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('INVOICES', 'record') ?></th>
        <th scope="col"><?= $word('INVOICES', 'supplier') ?></th>
        <th scope="col" class="c-status"><?= $word('INVOICES', 'match') ?></th>
        <th scope="col"><?= $word('INVOICES', 'posted') ?></th>
        <th scope="col" class="num"><?= $word('INVOICES', 'difference') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['number']) ?></a><span class="o-sub"><?= $e($r['external_ref'] ?? '') ?></span></th>
        <td data-label="<?= $word('INVOICES', 'supplier') ?>"><?= $e($r['supplier_name']) ?></td>
<?php // The match pill (RD-B6): the lines that differ under it (the old column), its claims, and who accepted the differences. ?>
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><span class="chip"><?= $word('MATCH_STATE', (string) $r['match_state']) ?></span><?php if ((int) $r['match_lines_off'] > 0): ?><span class="o-sub"><?php if ((int) $r['match_lines_off'] === 1): ?><?= $say('INVOICES', 'tag_diff_one', 1) ?><?php else: ?><?= $say('INVOICES', 'tag_diff_many', (int) $r['match_lines_off']) ?><?php endif; ?></span><?php endif; ?><?php foreach ($r['claims'] as $claim): ?><span class="o-sub"><?= $e($claim) ?></span><?php endforeach; ?><?php if ($show === 'accepted'): ?><span class="o-sub"><?= $word('INVOICES', 'accepted_by') ?>: <?= $e($r['accepted_by_name'] ?? '') ?><?php if (($r['accept_note'] ?? '') !== ''): ?> · <?= $e($r['accept_note']) ?><?php endif; ?></span><?php endif; ?></td>
        <td data-label="<?= $word('INVOICES', 'posted') ?>"><?= $when($r['posted_at']) ?><span class="o-sub"><?= $e($r['posted_by_name'] ?? '') ?></span></td>
        <td class="num" data-label="<?= $word('INVOICES', 'difference') ?>"><?= $money($r['match_difference']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('STOCK_OPS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
