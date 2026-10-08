<p class="crumbs"><a href="/ui/receiving/bench"><?= $word('MENU', 'bench') ?></a> <a href="<?= $u('/ui/receiving/' . $doc->id) ?>"><?= $word('BENCH', 'open_delivery') ?></a></p>
<h1><?= $e($title) ?></h1>
<p class="eyebrow"><?= $stateChip('RECEIPT_STATE', 'draft') ?> <?php if ($doc->externalRef !== null): ?><?= $say('BENCH', 'eyebrow_invoice', (string) $doc->externalRef) ?><?php else: ?><?= $word('BENCH', 'eyebrow_no_invoice') ?><?php endif; ?> · <?php if ($count === 1): ?><?= $word('BENCH', 'eyebrow_lines_one') ?><?php else: ?><?= $say('BENCH', 'eyebrow_lines', $count) ?><?php endif; ?><?php if ($count > $pageSize): ?>, <?= $say('BENCH', 'eyebrow_page', $pageSize) ?><?php endif; ?><?php if ($checkedBy !== null): ?> · <?= $say('BENCH', 'last_by', (string) $checkedBy) ?><?php endif; ?></p>
<?= $intro('receipt_bench') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<div class="note duty-rule">
  <p><strong><?= $say('BENCH', 'arrived_rule', $arrivedWhen) ?></strong></p>
  <p><?php if ($refusal): ?><?= $say('BENCH', 'rule_now', (string) ($cutoffDay ?? \CW\Ui\Words::BENCH['rule_date_unset'])) ?><?php else: ?><?= $say('BENCH', 'rule_before', (string) $lastDay, (string) $cutoffDay) ?><?php endif; ?></p>
  <?= $explain('unstamped_rule', \CW\Ui\Words::BENCH['rule_label']) ?>
</div>

<form class="bench" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/bench') ?>" data-unsaved="1" data-unsaved-text="<?= $word('RECEIPT', 'unsaved') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
  <input type="hidden" name="from" value="<?= $e($from) ?>">
  <input type="hidden" name="bench_was" value="<?= $e($stamps['checklist']) ?>">
  <fieldset class="bench-header">
    <legend><?= $word('BENCH', 'delivery_box') ?></legend>
    <label><?= $word('BENCH', 'paperwork') ?> <span class="hint"><?= $word('BENCH', 'paperwork_hint') ?></span>
      <select name="paperwork_ok">
        <option value=""><?= $word('BENCH', 'not_checked') ?></option>
        <option value="1"<?php if ((string) ($typed['paperwork_ok'] ?? ($gr['paperwork_ok'] ?? '')) === '1'): ?> selected<?php endif; ?>><?= $word('BENCH', 'paperwork_yes') ?></option>
        <option value="0"<?php if ((string) ($typed['paperwork_ok'] ?? ($gr['paperwork_ok'] ?? '')) === '0'): ?> selected<?php endif; ?>><?= $word('BENCH', 'paperwork_no') ?></option>
      </select>
    </label>
    <label><?= $word('BENCH', 'note') ?> <input type="text" name="bench_note" maxlength="500" value="<?= $e($typed['bench_note'] ?? ($gr['bench_note'] ?? '')) ?>"></label>
<?php if (!$receivedToday): ?>
    <label class="choice"><input type="checkbox" name="arrived_now" value="1"<?php if (($typed['arrived_now'] ?? '') === '1'): ?> checked<?php endif; ?>> <?= $say('BENCH', 'arrived_now', (string) $receivedDay) ?></label>
<?php endif; ?>
  </fieldset>

  <fieldset class="bench-tools">
    <legend><?= $word('BENCH', 'find') ?></legend>
    <label><?= $word('BENCH', 'find_label') ?> <input type="search" id="bench-find" maxlength="64" autocomplete="off" autocapitalize="characters" spellcheck="false" data-find-in=".bench-line" data-not-here="<?= $word('BENCH', 'not_here') ?>"></label>
    <span class="hint" id="bench-find-result" role="status"></span>
    <p class="actions"><button type="button" data-fill-stamps="1"><?= $word('BENCH', 'fill_stamps') ?></button>
      <button type="button" data-tick-all="1"><?= $word('BENCH', 'tick_all') ?></button></p>
    <p class="hint"><?= $word('BENCH', 'tools_hint') ?></p>
  </fieldset>

<?php foreach ($lines as $no => $l): ?>
<?php $over = (int) ($typed['b_' . $no . '_over'] ?? $l['over_units']); ?>
  <article class="bench-line card<?php if ($l['checked_at'] === null): ?> unchecked<?php endif; ?>" id="line-<?= $e($no) ?>" data-codes="<?= $e(implode(' ', array_filter([$l['sku_code'], $l['supplier_code'], ...($barcodes[(int) $l['sku_id']]['codes'] ?? [])]))) ?>">
    <input type="hidden" name="b_<?= $e($no) ?>_present" value="<?= $e($stamps['identity'][$no] ?? '') ?>">
    <input type="hidden" name="b_<?= $e($no) ?>_was" value="<?= $e($stamps['lines'][$no] ?? '') ?>">
    <div class="bench-head">
      <h2><?= $say('BENCH', 'line', $no, (string) $l['sku_name']) ?> <span class="o-sub"><?= $e($l['sku_code']) ?></span></h2>
      <p class="bench-chips"><?php if ($l['stamp_req']): ?><?= $chip('info', \CW\Ui\Words::BENCH['stamp_needed']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::BENCH['stamp_not_needed']) ?><?php endif; ?>
        <?php if ($l['checked_at'] !== null): ?><?= $chip('done', \CW\Ui\Words::BENCH['checked']) ?><?php else: ?><?= $chip('needs', \CW\Ui\Words::BENCH['not_yet']) ?><?php endif; ?></p>
    </div>
    <p class="expect"><strong><?= $say('BENCH', 'expect', (string) $l['packs_text'], (int) $l['units']) ?></strong> <?= $word('BENCH', 'on_paperwork') ?><?php if ($l['supplier_code'] !== null): ?> · <?= $say('BENCH', 'their_code', (string) $l['supplier_code']) ?><?php endif; ?><?php if (($barcodes[(int) $l['sku_id']]['labels'] ?? []) !== []): ?> · <?= $say('BENCH', 'barcodes', implode(', ', $barcodes[(int) $l['sku_id']]['labels'])) ?><?php endif; ?></p>
<?php foreach ($l['problems'] as $p): ?>
    <p class="field-error"><?= $e($p) ?></p>
<?php endforeach; ?>
    <label class="choice counted"><input type="checkbox" name="b_<?= $e($no) ?>_ok" value="1"<?php if (isset($typed['b_' . $no . '_present']) ? ($typed['b_' . $no . '_ok'] ?? '') === '1' : $l['checked_at'] !== null): ?> checked<?php endif; ?>> <?= $say('BENCH', 'counted', (int) $l['units']) ?></label>
<?php if ($l['stamp_req']): ?>
    <fieldset class="stamp">
      <legend><?= $word('BENCH', 'stamp') ?></legend>
      <?= $explain('duty_stamp', \CW\Ui\Words::BENCH['stamp_label']) ?>
      <label><?= $word('BENCH', 'stamp_on') ?>
        <select name="b_<?= $e($no) ?>_stamp" data-stamp="1">
          <option value=""><?= $word('BENCH', 'not_checked') ?></option>
          <option value="1"<?php if ((string) ($typed['b_' . $no . '_stamp'] ?? ($l['stamp_on_pack'] ?? '')) === '1'): ?> selected<?php endif; ?>><?= $word('BENCH', 'stamp_yes') ?></option>
          <option value="0"<?php if ((string) ($typed['b_' . $no . '_stamp'] ?? ($l['stamp_on_pack'] ?? '')) === '0'): ?> selected<?php endif; ?>><?= $word('BENCH', 'stamp_no') ?></option>
        </select>
      </label>
      <label><?= $word('BENCH', 'stamp_type') ?>
        <select name="b_<?= $e($no) ?>_type" data-stamp-type="1">
          <option value=""><?= $word('BENCH', 'choose') ?></option>
<?php foreach ($stampTypes as $code => $label): ?>
          <option value="<?= $e($code) ?>"<?php if (($typed['b_' . $no . '_type'] ?? ($l['stamp_type'] ?? '')) === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('BENCH', 'stamp_code') ?> <input type="text" name="b_<?= $e($no) ?>_code" maxlength="128" autocomplete="off" data-enter="next" value="<?= $e($typed['b_' . $no . '_code'] ?? ($l['stamp_code'] ?? '')) ?>"></label>
    </fieldset>
<?php endif; ?>
    <fieldset class="exceptions">
      <legend><?= $word('BENCH', 'differ') ?></legend>
      <label><?= $word('BENCH', 'short') ?> <input type="number" name="b_<?= $e($no) ?>_short" min="0" inputmode="numeric" value="<?= $e($typed['b_' . $no . '_short'] ?? $l['short_units']) ?>"></label>
      <label><?= $word('BENCH', 'over') ?> <input type="number" name="b_<?= $e($no) ?>_over" min="0" inputmode="numeric" value="<?= $e($typed['b_' . $no . '_over'] ?? $l['over_units']) ?>"></label>
<?php if ($over > $l['units']): ?>
      <label class="choice wide"><input type="checkbox" name="b_<?= $e($no) ?>_overok" value="1"<?php if (($typed['b_' . $no . '_overok'] ?? ($over === (int) $l['over_units'] ? '1' : '')) === '1'): ?> checked<?php endif; ?>> <?= $word('BENCH', 'over_ok') ?></label>
<?php endif; ?>
      <label><?= $word('BENCH', 'damaged') ?> <input type="number" name="b_<?= $e($no) ?>_damaged" min="0" inputmode="numeric" value="<?= $e($typed['b_' . $no . '_damaged'] ?? $l['damaged_units']) ?>"></label>
      <label><?= $word('BENCH', 'wrong') ?> <input type="number" name="b_<?= $e($no) ?>_wrong" min="0" inputmode="numeric" value="<?= $e($typed['b_' . $no . '_wrong'] ?? $l['wrong_item_units']) ?>"> <span class="hint"><?= $word('BENCH', 'wrong_hint') ?></span></label>
<?php if ($l['stamp_req']): ?>
      <label><?= $word('BENCH', 'unstamped') ?> <input type="number" name="b_<?= $e($no) ?>_unstamped" min="0" inputmode="numeric" data-unstamped="1" value="<?= $e($typed['b_' . $no . '_unstamped'] ?? $l['unstamped_units']) ?>"></label>
      <div class="unstamped-only" data-unstamped-only="1">
      <label class="wide"><?= $word('BENCH', 'unstamped_what') ?>
        <select name="b_<?= $e($no) ?>_action">
          <option value=""><?= $word('BENCH', 'unstamped_choose') ?></option>
<?php foreach ($actions as $code => $label): ?>
<?php if ($code !== 'accept_pre_october' || !$refusal): ?>
          <option value="<?= $e($code) ?>"<?php if (($typed['b_' . $no . '_action'] ?? ($l['unstamped_action'] ?? '')) === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endif; ?>
<?php endforeach; ?>
        </select>
      </label>
<?php if (!$refusal): ?>
      <label class="wide"><?= $word('BENCH', 'evidence') ?>
        <textarea name="b_<?= $e($no) ?>_evidence" maxlength="500" rows="2"><?= $e($typed['b_' . $no . '_evidence'] ?? ($l['pre_october_evidence'] ?? '')) ?></textarea></label>
<?php endif; ?>
      <p class="hint wide"><?= $word('BENCH', 'extras_hint') ?></p>
      </div>
<?php endif; ?>
    </fieldset>
  </article>
<?php endforeach; ?>
  <p class="actions sticky">
    <button type="submit" name="next" value="0" class="primary"><?= $word('BENCH', 'save') ?></button>
<?php if ($from + $pageSize <= $count): ?>
    <button type="submit" name="next" value="1"><?= $say('BENCH', 'save_next', $pageSize) ?></button>
<?php endif; ?>
  </p>
</form>
<?php if ($count > $pageSize): ?>
<p class="pager"><?php if ($from > 1): ?><a href="<?= $u('/ui/receiving/' . $doc->id . '/bench', ['from' => max(1, $from - $pageSize)]) ?>"><?= $word('BENCH', 'earlier') ?></a><?php endif; ?>
<?php if ($from + $pageSize <= $count): ?><a href="<?= $u('/ui/receiving/' . $doc->id . '/bench', ['from' => $from + $pageSize]) ?>"><?= $word('BENCH', 'next') ?></a><?php endif; ?></p>
<?php endif; ?>

<section class="card photos" aria-labelledby="photos-h">
  <h2 id="photos-h"><?= $word('BENCH', 'photos') ?></h2>
<?php if ($photos === []): ?>
  <p class="hint"><?= $word('BENCH', 'photos_none') ?></p>
<?php else: ?>
  <ul class="plain files">
<?php foreach ($photos as $ph): ?>
    <li><a href="<?= $u('/ui/files/' . $ph['id']) ?>"><?= $e($ph['original_name']) ?></a> <?= $chip('info', (string) $ph['role_word']) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
  <form class="upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/receiving/' . $doc->id . '/files') ?>" data-leaves="1" data-busy-text="<?= $word('RECEIPT', 'busy') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="back" value="bench">
    <label><?= $word('BENCH', 'photo_what') ?>
      <select name="role">
<?php foreach ($photoRoles as $role => $label): ?>
        <option value="<?= $e($role) ?>"><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $say('BENCH', 'photo_file', $maxMb) ?> <input type="file" name="file" accept="image/jpeg,image/png,application/pdf" capture="environment" data-max-bytes="2097152" data-too-big="<?= $say('RECEIPT', 'too_big', $maxMb) ?>" required></label>
    <label><?= $word('BENCH', 'photo_note') ?> <input type="text" name="note" maxlength="255"></label>
    <p class="actions"><button type="submit"><?= $word('BENCH', 'attach') ?></button></p>
  </form>
</section>
