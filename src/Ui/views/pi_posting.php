<?php
// The supplier invoice's "Posting date" and "Posting time" (the owner, 6 Nov 2026: "in purchase invoice need posting date; ... only at the
// scheduled time this stock should take effect on the site"; docs/decisions.md KG27, KG32): a field of the editor's own form, on a new
// invoice and on a draft. "Save and make final" with a time typed saves the draft and sets the time in the same request, by the timed
// route's rules (pack J, PJ10: a time still to come, the hour the clocks go back chosen, the person's jobs checked again then, the Timed
// list), never "make final now"; empty: made final now. One UK date-and-time box (submit_at), which app.js shows as the date and the time
// side by side, with the line saying when the stock goes to the websites. A refusal of the time is said under it, the time kept.
$canTime = $arrival !== null && $arrival['editable'];
$timeError = in_array($error, ['bad_submit_at', 'ambiguous_submit_at'], true);
$typed = $typed ?? null;
?>
<?php if ($canTime || $isNew): ?>
  <fieldset class="kg-posting">
    <legend class="visually-hidden"><?= $word('ARRIVALS', 'when_title') ?></legend>
<?php if ($canTime && $arrival['failed'] !== null): ?>
    <p class="error doc-wide" role="alert"><?= $say('ARRIVALS', 'failed', $arrival['failed']) ?></p>
<?php elseif ($canTime && $arrival['at'] !== null): ?>
    <p class="doc-wide"><?= $say('ARRIVALS', 'timed', $arrival['at'], $arrival['by']) ?></p>
<?php endif; ?>
    <label><?= $word('DOC_GRID', 'posting_pair') ?> <input type="datetime-local" name="submit_at" value="<?= $e($clockChoice['typed'] ?? ($typed ?? ($canTime ? $arrival['atValue'] : ''))) ?>" data-kg-posting data-pair-words="<?= $word('DOC_GRID', 'posting_pair') ?>" data-date-words="<?= $word('DOC_GRID', 'posting_date') ?>" data-time-words="<?= $word('DOC_GRID', 'posting_time') ?>"<?php if ($timeError): ?> aria-invalid="true" aria-describedby="kg-posting-error"<?php endif; ?>></label>
<?php if ($timeError): ?>
    <p class="field-error" id="kg-posting-error"><?= $word('ARRIVAL_ERROR', (string) $error) ?></p>
<?php endif; ?>
<?php if ($clockChoice !== null): ?>
    <fieldset class="doc-wide">
      <legend><?= $say('ARRIVALS', 'clock_title', $clockChoice['time']) ?></legend>
      <label class="choice"><input type="radio" name="submit_clock" value="BST" required> <?= $say('ARRIVALS', 'clock_bst', $clockChoice['time']) ?></label>
      <label class="choice"><input type="radio" name="submit_clock" value="GMT" required> <?= $say('ARRIVALS', 'clock_gmt', $clockChoice['time']) ?></label>
    </fieldset>
<?php endif; ?>
    <p class="kg-sched-line" data-kg-sched data-words="<?= $word('DOC_GRID', 'sched_line') ?>" aria-live="polite" hidden></p>
  </fieldset>
<?php endif; ?>
