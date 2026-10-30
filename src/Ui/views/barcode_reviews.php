<?php
// Items › Barcodes, an inbox (the approved design of 29 Oct 2026, AP_Inbox; docs/decisions.md W11.8; the redesign's simple list head, RD-A7):
// the barcode search and ONE Show choice (waiting, decided), Clear under the head's More; then the barcodes grouped by why they wait (a coloured dot, the reason and its count), each row a link
// that opens it; the open one on the right (first on a phone), with its facts and its one decision: the answers, the items per scan where they
// matter, the note, and ONE main button naming the form, with the one its save opens named beside it (W11.14). The open row is the page shown:
// no link. A decided barcode shows what was decided and has no form. A refusal is said in the record it concerns (W11.12), or over the list
// when that one no longer waits.
?>
<?= $head(\CW\Ui\Words::MENU['barcodes'], intro: 'barcodes', side: $partial('rda_bar', $bar)) ?>
<form class="toolbar" method="get" action="/ui/items/barcodes" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('BARCODE', 'find') ?></label><input id="tb-q" type="search" name="barcode" value="<?= $e($barcode) ?>" inputmode="numeric" maxlength="40" placeholder="<?= $word('BARCODE', 'find') ?>"></div>
<?php if ($show === 'decided'): ?>
  <input type="hidden" name="show" value="decided">
<?php endif; ?>
  <?= $partial('rda_show', ['choices' => $views, 'label' => \CW\Ui\Words::INV_LIST['show']]) ?>
</form>
<?php if ($error !== null && ($record === null || $errorId !== (int) $record['id'])): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($rows === [] && $show === 'open'): ?>
<?= $empty(\CW\Ui\Words::BARCODE['nothing'], \CW\Ui\Words::BARCODE['nothing_text']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::BARCODE['nothing_decided']) ?>
<?php else: ?>
<div class="ap-inbox">
  <section class="card ap-list" aria-label="<?= $word('BARCODE', 'list') ?>">
<?php foreach ($groups as $g): ?>
    <div class="ap-group <?= $e($g['tone']) ?>">
      <h2 class="ap-gh"><span class="ap-dot" aria-hidden="true"></span><span class="ap-gname"><?= $e($g['name']) ?></span> <span class="ap-gcount"><?= $n(count($g['rows'])) ?></span></h2>
      <ul class="ap-rows">
<?php foreach ($g['rows'] as $r): ?>
<?php $sel = (int) $r['id'] === (int) $record['id']; ?>
        <li><a class="ap-row<?php if ($sel): ?> sel<?php endif; ?>"<?php if ($sel): ?> aria-current="true"<?php else: ?> href="<?= $e($r['href']) ?>"<?php endif; ?>><span class="ap-t"><?= $e($r['barcode']) ?></span><span class="ap-m"><?php if ($r['reason'] === 'removed'): ?><?= $say('BARCODE', 'row_removed', $r['claimant_code'] . ' ' . $r['claimant_name']) ?><?php else: ?><?= $say('BARCODE', 'row_carried', $r['claimant_code'] . ' ' . $r['claimant_name']) ?><?php endif; ?><?php if ($r['decisionLabel'] !== null): ?> · <?= $e($r['decisionLabel']) ?><?php endif; ?></span></a></li>
<?php endforeach; ?>
      </ul>
    </div>
<?php endforeach; ?>
  </section>
  <section class="ap-record <?= $e($record['tone']) ?>" aria-labelledby="ap-h">
  <div class="card ap-card" id="review-<?= $e($record['id']) ?>">
    <div class="ap-rhead">
      <div class="ap-rtitle">
        <p class="ap-kind"><?= $e($record['reasonLabel']) ?> · <?= $e($record['place']) ?></p>
        <h2 id="ap-h"><?= $e($record['barcode']) ?></h2>
      </div>
<?php if ($record['status'] === 'open'): ?>
      <?= $chip($record['tone'], \CW\Ui\Words::BARCODE['state_open']) ?>
<?php else: ?>
      <?= $chip('done', \CW\Ui\Words::BARCODE['state_decided']) ?>
<?php endif; ?>
    </div>
<?php if ($errorId === (int) $record['id'] && $error !== null): ?>
    <p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
    <dl class="doc-facts">
<?php if ($record['holder_sku_id'] !== null && $record['reason'] !== 'removed'): ?>
      <dt><?= $word('BARCODE', 'on_product') ?></dt>
      <dd><a href="<?= $u('/ui/items/' . $record['holder_sku_id']) ?>"><?= $e($record['holder_code']) ?></a> <?= $e($record['holder_name']) ?> <span class="muted"><?= $say('BARCODE', 'in_stock', (int) $record['holder_stock']) ?></span><?php if ($record['holder_merged'] !== null): ?> <span class="tag needs"><?= $say('BARCODE', 'joined', (string) $record['holder_merged_code']) ?></span><?php endif; ?></dd>
<?php endif; ?>
      <dt><?php if ($record['reason'] === 'removed'): ?><?= $word('BARCODE', 'removed_from') ?><?php else: ?><?= $word('BARCODE', 'carried_by') ?><?php endif; ?></dt>
      <dd><a href="<?= $u('/ui/items/' . $record['claimant_sku_id']) ?>"><?= $e($record['claimant_code']) ?></a> <?= $e($record['claimant_name']) ?> <span class="muted"><?= $say('BARCODE', 'in_stock', (int) $record['claimant_stock']) ?></span></dd>
<?php if ($record['listing_id'] !== null): ?>
      <dt><?= $word('BARCODE', 'website_product') ?></dt>
      <dd><?= $e($record['variant_title'] ?? $record['product_title']) ?> <span class="muted"><?= $say('BARCODE', 'listing_line', (string) $record['site'], \CW\Ui\Words::say('SALES', 'option', (string) $record['external_variant_id']), (string) $record['units_per_item'], (string) $record['listing_state']) ?></span></dd>
<?php endif; ?>
      <dt><?= $word('BARCODE', 'now') ?></dt>
      <dd><?php if ($record['now_sku_id'] === null): ?><?= $word('BARCODE', 'now_none') ?><?php else: ?><?= $say('BARCODE', 'now_line', (string) $record['now_code'], (string) $record['now_units'], (int) $record['now_usable'] === 1 ? \CW\Ui\Words::BARCODE['can_use'] : \CW\Ui\Words::BARCODE['cannot_use']) ?><?php endif; ?></dd>
      <dt><?= $word('BARCODE', 'found') ?></dt>
      <dd><?php if ($record['opened_by_name'] === null): ?><?= $say('BARCODE', 'found_cw', \CW\Ui\Html::when((string) $record['created_at'])) ?><?php else: ?><?= $say('BARCODE', 'found_line', \CW\Ui\Html::when((string) $record['created_at']), (string) $record['opened_by_name']) ?><?php endif; ?></dd>
<?php if ($record['status'] === 'decided'): ?>
      <dt><?= $word('BARCODE', 'decision') ?></dt>
      <dd><?= $e($record['decisionLabel']) ?><?php if ($record['decided_units'] !== null): ?> (<?= $say('BARCODE', 'per_scan', (string) $record['decided_units']) ?>)<?php endif; ?> · <?= $when($record['decided_at']) ?> · <?= $e($record['decided_by_name'] ?? \CW\Ui\Words::BARCODE['set_up']) ?><?php if ($record['note'] !== null): ?> · <?= $e($record['note']) ?><?php endif; ?></dd>
<?php endif; ?>
    </dl>
<?php if ($record['holder_merged_into_claimant'] && $record['status'] === 'open'): ?>
    <p class="ap-line"><?= $word('BARCODE', 'joined_note') ?></p>
<?php endif; ?>
<?php if ($record['status'] === 'open'): ?>
    <form class="ap-answer" id="ap-answer" method="post" action="<?= $u('/ui/items/barcodes/' . $record['id'] . '/decide') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1"<?php if ($errorId === (int) $record['id'] && $error !== null): ?> data-dirty="1"<?php endif; ?>>
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="form_key" value="<?= $e($record['formKey']) ?>">
      <fieldset>
        <legend><?= $word('BARCODE', 'decide') ?></legend>
<?php foreach ($record['choices'] as $code => $label): ?>
        <label class="choice"><input type="radio" name="decision" value="<?= $e($code) ?>"<?php if ($code === 'move' && $record['holder_merged_into_claimant']): ?> checked<?php endif; ?>> <?= $e($label) ?></label>
<?php endforeach; ?>
      </fieldset>
<?php if ($record['reason'] === 'multipack_listing' || $record['reason'] === 'on_another_item'): ?>
      <label for="units-<?= $e($record['id']) ?>"><?= $word('BARCODE', 'units_label') ?> <span class="hint"><?php if ($record['reason'] === 'multipack_listing'): ?><?= $say('BARCODE', 'units_hint_listing', (string) $record['units_per_item']) ?><?php else: ?><?= $word('BARCODE', 'units_hint_now') ?><?php endif; ?></span></label>
      <input id="units-<?= $e($record['id']) ?>" class="units" type="number" name="units" min="1" max="<?= $e($maxUnits) ?>" step="1" inputmode="numeric">
<?php endif; ?>
      <label for="note-<?= $e($record['id']) ?>"><?= $word('BARCODE', 'note') ?></label>
      <input id="note-<?= $e($record['id']) ?>" type="text" name="note" maxlength="500">
    </form>
    <div class="ap-actions">
      <button type="submit" form="ap-answer" class="btn primary"><?= $word('BARCODE', 'save_decision') ?></button>
      <p class="ap-next"><?php if ($record['next'] !== null): ?><?= $say('BARCODE', 'next_one', $record['next']) ?><?php else: ?><?= $word('BARCODE', 'last_one') ?><?php endif; ?></p>
    </div>
<?php endif; ?>
  </div>
  </section>
</div>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('BARCODE', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
