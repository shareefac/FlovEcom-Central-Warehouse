<?php
// Settings › Numbering, on the settings shell (the approved TP_Settings of 29 Oct 2026, pattern 6; docs/decisions.md W13.10): read
// only. One card, a row per kind of record in the kinds' order: its name (and "Coming later" while no screen makes it), who checks it, the OK
// needed first and the days to check; how its numbers start and the last one given; at the right the next number. How the numbers are made
// is the heading's "?".
?>
<?= $head(\CW\Ui\Words::PAGE_TITLE['series'], intro: 'series', more: \CW\Ui\Words::SETTINGS_PAGE['series_text']) ?>
<div class="set-shell">
<?= $partial('settings_nav', ['current' => 'series']) ?>
<div class="set-main">
<div class="set-list">
<section class="set-card series" aria-labelledby="series-h">
  <h2 id="series-h"><?= $word('SETTINGS_PAGE', 'series_card') ?></h2>
<?php foreach ($series as $s): ?>
  <div class="set-row<?php if (!$s['live']): ?> unused<?php endif; ?>" data-code="<?= $e($s['code']) ?>">
    <div class="set-k">
      <p class="set-kt"><?= $e($s['kind']) ?> <span class="tag <?php if ($s['live']): ?>done<?php else: ?>off<?php endif; ?>"><?php if ($s['live']): ?><?= $word('SETTINGS_PAGE', 'live') ?><?php else: ?><?= $word('SETTINGS_PAGE', 'later') ?><?php endif; ?></span></p>
      <p class="set-kd"><?= $say('SETTINGS_PAGE', 'series_rules', $s['review'], $s['approval'], $s['due_days']) ?></p>
      <p class="set-kh"><?php if ($s['last'] === null): ?><?= $say('SETTINGS_PAGE', 'series_none', $s['prefix']) ?><?php else: ?><?= $say('SETTINGS_PAGE', 'series_line', $s['prefix'], $s['last']) ?><?php endif; ?></p>
    </div>
    <div class="set-v"><span class="muted"><?= $word('SETTINGS_PAGE', 'next') ?></span> <code class="set-val next"><?= $e($s['next']) ?></code></div>
  </div>
<?php endforeach; ?>
</section>
</div>
</div>
</div>
