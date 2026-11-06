<?php
// The supplier invoice's "Posting date" and "Posting time" (the owner, 6 Nov 2026: "in purchase invoice need posting date; ... only at the
// scheduled time this stock should take effect on the site"; docs/decisions.md KG27, KG32, KG33): two fields of the editor's own form, on a
// new invoice and on a draft, the date (submit_date) and the time (submit_time, UK) side by side under one label. "Save and make final" with
// both typed saves the draft and sets the time in the same request, by the timed route's rules (pack J, PJ10: a time still to come, the
// hour the clocks go back chosen, the person's jobs checked again then, the Timed list), never "make final now"; only both empty make it
// final now, and one without the other is refused. A refusal of the time is said under them, what was typed kept (a conflict, a new
// supplier too). The line under them says when the stock goes to the websites (app.js).
$canTime = $arrival !== null && $arrival['editable'];
$timeError = in_array($error, ['bad_submit_at', 'ambiguous_submit_at', 'partial_submit_at'], true);
$typed = $typed ?? null;
$shownAt = $clockChoice['typed'] ?? ($canTime ? (string) $arrival['atValue'] : '');
$shownDate = $typed !== null ? $typed['date'] : (string) substr($shownAt, 0, 10);
$shownTime = $typed !== null ? $typed['time'] : (string) substr($shownAt, 11, 5);
?>
<?php if ($canTime || $isNew): ?>
  <fieldset class="kg-posting" data-kg-partial="<?= $word('ARRIVAL_ERROR', 'partial_submit_at') ?>">
    <legend class="visually-hidden"><?= $word('ARRIVALS', 'when_title') ?></legend>
<?php if ($canTime && $arrival['failed'] !== null): ?>
    <p class="error doc-wide" role="alert"><?= $say('ARRIVALS', 'failed', $arrival['failed']) ?></p>
<?php elseif ($canTime && $arrival['at'] !== null): ?>
    <p class="doc-wide"><?= $say('ARRIVALS', 'timed', $arrival['at'], $arrival['by']) ?></p>
<?php endif; ?>
    <div class="kg-pair-field" role="group" aria-labelledby="kg-posting-words">
      <span class="kg-label" id="kg-posting-words"><?= $word('DOC_GRID', 'posting_pair') ?></span>
      <div class="kg-pair">
        <input type="date" name="submit_date" id="kg-posting-date" value="<?= $e($shownDate) ?>" aria-label="<?= $word('DOC_GRID', 'posting_date') ?>" data-kg-posting-date<?php if ($timeError): ?> aria-invalid="true" aria-describedby="kg-posting-error"<?php endif; ?>>
        <input type="time" name="submit_time" value="<?= $e($shownTime) ?>" aria-label="<?= $word('DOC_GRID', 'posting_time') ?>" data-kg-posting-time<?php if ($timeError): ?> aria-invalid="true" aria-describedby="kg-posting-error"<?php endif; ?>>
      </div>
    </div>
<?php if ($timeError): ?>
    <p class="field-error" id="kg-posting-error"><?= $word('ARRIVAL_ERROR', (string) $error) ?></p>
<?php endif; ?>
<?php if ($clockChoice !== null): ?>
    <fieldset class="doc-wide">
      <legend><?= $say('ARRIVALS', 'clock_title', $clockChoice['time']) ?></legend>
      <label class="choice"><input type="radio" name="submit_clock" value="BST" required<?php if (($clockChoice['chosen'] ?? '') === 'BST'): ?> checked<?php endif; ?>> <?= $say('ARRIVALS', 'clock_bst', $clockChoice['time']) ?></label>
      <label class="choice"><input type="radio" name="submit_clock" value="GMT" required<?php if (($clockChoice['chosen'] ?? '') === 'GMT'): ?> checked<?php endif; ?>> <?= $say('ARRIVALS', 'clock_gmt', $clockChoice['time']) ?></label>
    </fieldset>
<?php endif; ?>
    <p class="kg-sched-line" data-kg-sched data-words="<?= $word('DOC_GRID', 'sched_line') ?>" data-schedule="<?= $word('DOC_GRID', 'schedule') ?>" aria-live="polite" hidden></p>
  </fieldset>
<?php endif; ?>
