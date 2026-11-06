<?php
// The supplier invoice's "Posting date" and "Posting time" (the owner, 6 Nov 2026: "in purchase invoice need posting date; ... only at the
// scheduled time this stock should take effect on the site"; docs/decisions.md KG27): pack J's "make it final later" (PJ10) in the details,
// its own form pi-schedule (the timed route: a time still to come, the hour the clocks go back chosen, the person's jobs checked again then,
// the Timed list). One UK date-and-time box (submit_at), which app.js shows as the date and the time side by side; empty: made final now by
// the head's button. A refusal of the time is said under it. A new invoice gets it with the script only (its form comes with the draft).
$canTime = $arrival !== null && $arrival['editable'];
$timeError = in_array($error, ['bad_submit_at', 'ambiguous_submit_at'], true);
?>
<?php if ($canTime || $isNew): ?>
  <fieldset class="kg-posting"<?php if (!$canTime): ?> data-kg-js hidden<?php endif; ?>>
    <legend class="visually-hidden"><?= $word('ARRIVALS', 'when_title') ?></legend>
<?php if ($canTime && $arrival['failed'] !== null): ?>
    <p class="error doc-wide" role="alert"><?= $say('ARRIVALS', 'failed', $arrival['failed']) ?></p>
<?php elseif ($canTime && $arrival['at'] !== null): ?>
    <p class="doc-wide"><?= $say('ARRIVALS', 'timed', $arrival['at'], $arrival['by']) ?></p>
<?php endif; ?>
    <label><?= $word('ARRIVALS', 'at') ?> <input type="datetime-local" name="submit_at" form="pi-schedule" value="<?= $e($clockChoice['typed'] ?? ($canTime ? $arrival['atValue'] : '')) ?>" data-kg-posting data-pair-words="<?= $word('DOC_GRID', 'posting_pair') ?>" data-date-words="<?= $word('DOC_GRID', 'posting_date') ?>" data-time-words="<?= $word('DOC_GRID', 'posting_time') ?>"<?php if ($timeError): ?> aria-invalid="true" aria-describedby="kg-posting-error"<?php endif; ?>></label>
<?php if ($timeError): ?>
    <p class="field-error" id="kg-posting-error"><?= $word('ARRIVAL_ERROR', (string) $error) ?></p>
<?php endif; ?>
<?php if ($clockChoice !== null): ?>
    <fieldset class="doc-wide">
      <legend><?= $say('ARRIVALS', 'clock_title', $clockChoice['time']) ?></legend>
      <label class="choice"><input type="radio" name="submit_clock" value="BST" form="pi-schedule" required> <?= $say('ARRIVALS', 'clock_bst', $clockChoice['time']) ?></label>
      <label class="choice"><input type="radio" name="submit_clock" value="GMT" form="pi-schedule" required> <?= $say('ARRIVALS', 'clock_gmt', $clockChoice['time']) ?></label>
    </fieldset>
<?php endif; ?>
    <p class="kg-sched-line" data-kg-sched data-words="<?= $word('DOC_GRID', 'sched_line') ?>" aria-live="polite" hidden></p>
<?php if ($canTime): ?>
    <p class="kg-posting-go" data-kg-nojs><button type="submit" form="pi-schedule" class="btn"><?= $word('ARRIVALS', 'set_time') ?></button></p>
<?php endif; ?>
  </fieldset>
<?php endif; ?>
