<?php
// Settings › Rules and numbers (the approved TP_Settings of 29 Oct 2026, pattern 6; docs/decisions.md W13.9). The section's pages are the
// sidebar's sub-items (DC28). The heading; the 2-step warning while it is off; one search ("Find a setting"); then a card per topic: the
// company details first (their own page), then each topic's settings, a row each: the name (its page: the value, the agreed tick and its
// History), what it does, "Not agreed yet" while the owner has not said yes, who changed it last; at the right its value. For whoever
// changes settings a simple value is a box in the row with its unit, a reason and a plain Save that saves that setting alone (its own
// route and fields; the row's Save never changes "agreed"); a list, a long text or a hidden value has "Change" to its page. The VAT codes
// last (read only), then the one line that says each change is kept.
?>
<?= $head(\CW\Ui\Words::SETTINGS_PAGE['title'], intro: 'settings', look: $lookOnly) ?>
<div class="set-shell">
<?= $partial('settings_nav', ['current' => 'settings']) ?>
<div class="set-main">
<?php if ($codeOffUntil !== null): ?>
<div class="alert blocked code-off" role="alert">
  <p class="alert-title"><?= $say('SETTINGS_PAGE', 'code_off', \CW\Ui\Html::when($codeOffUntil)) ?></p>
  <p><a href="<?= $u('/ui/reference/settings/setting', ['key' => \CW\Auth\SecondStep::SETTING]) ?>"><?= $word('SETTINGS_PAGE', 'code_off_open') ?></a></p>
</div>
<?php endif; ?>
<form class="toolbar" method="get" action="/ui/reference/settings" aria-label="<?= $word('SETTINGS_PAGE', 'find') ?>"<?php if ($canChange): ?> data-leaves="1"<?php endif; ?>>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('SETTINGS_PAGE', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="100" placeholder="<?= $word('SETTINGS_PAGE', 'find') ?>"></div>
<?php if ($q !== ''): ?>
  <div class="tb-end"><a class="btn ghost sm" href="/ui/reference/settings"><?= $word('SETTINGS_PAGE', 'clear_find') ?></a></div>
<?php endif; ?>
</form>

<?php if ($q !== '' && $topics === [] && $vat === []): ?>
<?= $empty(\CW\Ui\Words::SETTINGS_PAGE['none_found'], \CW\Ui\Words::SETTINGS_PAGE['none_found_text'], '/ui/reference/settings', \CW\Ui\Words::SETTINGS_PAGE['clear_find']) ?>
<?php else: ?>
<div class="set-list">
<?php if ($q === ''): ?>
<section class="set-card company-summary<?php if (!$company['confirmed']): ?> waiting<?php endif; ?>" aria-labelledby="company-h">
  <h2 id="company-h"><?= $word('MENU', 'company') ?></h2>
  <div class="set-row">
    <div class="set-k">
      <p class="set-kt"><?= $word('SETTINGS_PAGE', 'company_text') ?><?php if ($company['legal_name'] !== ''): ?>: <strong><?= $e($company['legal_name']) ?></strong><?php endif; ?>.</p>
<?php if ($company['confirmed']): ?>
      <p class="set-kd"><span class="tag done"><?= $word('COMPANY', 'confirmed') ?></span></p>
<?php else: ?>
      <p class="set-kd"><span class="tag blocked"><?= $word('COMPANY', 'not_confirmed') ?></span> <?= $word('SETTINGS_PAGE', 'not_confirmed_text') ?><?php if ($company['missing'] !== []): ?> <?= $say('COMPANY', 'missing', implode(', ', $company['missing'])) ?><?php endif; ?></p>
<?php endif; ?>
    </div>
    <div class="set-v"><?php if ($company['canEdit']): ?><a class="btn" href="/ui/reference/company"><?= $word('SETTINGS_PAGE', 'edit_company') ?></a><?php else: ?><a class="btn ghost" href="/ui/reference/company"><?= $word('SETTINGS_PAGE', 'see_company') ?></a><?php endif; ?></div>
  </div>
</section>
<?php endif; ?>
<?php foreach (array_values($topics) as $t => $rows): ?>
<section class="set-card" aria-labelledby="topic-<?= $e($t) ?>">
  <h2 id="topic-<?= $e($t) ?>"><?= $e(array_keys($topics)[$t]) ?></h2>
<?php foreach ($rows as $s): ?>
<?php if ($s['inline']): ?>
  <form method="post" action="/ui/reference/settings/setting" class="set-row<?php if (($s['unused'] ?? null) !== null): ?> unused<?php endif; ?>" data-key="<?= $e($s['key']) ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="key" value="<?= $e($s['key']) ?>">
    <input type="hidden" name="seen" value="<?= $e($s['seen']) ?>">
<?php if ($s['agreed']): ?>
    <input type="hidden" name="agreed" value="1">
<?php endif; ?>
    <div class="set-k">
      <a class="set-kt" href="<?= $e($s['href']) ?>"><?= $e($s['name']) ?></a><?php if (!$s['agreed']): ?> <span class="tag needs"><?= $word('SETTINGS_PAGE', 'not_agreed') ?></span><?php endif; ?>
      <p class="set-kd"><?= $e($s['help']) ?><?php if (($s['unused'] ?? null) !== null): ?> <strong class="unused-note"><?= $e($s['unused']) ?></strong><?php endif; ?></p>
<?php if ($s['changed_shown']): ?>
      <p class="set-kh"><?= $say('SETTINGS_PAGE', 'last_changed', $s['changed']) ?></p>
<?php endif; ?>
    </div>
    <div class="set-v">
      <label class="visually-hidden" for="v-<?= $e($s['key']) ?>"><?= $word('SETTINGS_PAGE', 'new_value') ?></label>
<?php if ($s['type'] === 'bool'): ?>
      <select id="v-<?= $e($s['key']) ?>" name="value">
        <option value="true"<?php if ($s['raw'] === 'true'): ?> selected<?php endif; ?>><?= $word('SETTINGS_PAGE', 'yes') ?></option>
        <option value="false"<?php if ($s['raw'] !== 'true'): ?> selected<?php endif; ?>><?= $word('SETTINGS_PAGE', 'no') ?></option>
      </select>
<?php elseif ($s['type'] === 'date'): ?>
      <input id="v-<?= $e($s['key']) ?>" type="date" name="value" value="<?= $e($s['raw']) ?>">
<?php else: ?>
      <input id="v-<?= $e($s['key']) ?>" class="<?php if ($s['type'] === 'string'): ?>set-in wide<?php else: ?>set-in<?php endif; ?>" type="text" name="value" value="<?= $e($s['raw']) ?>" maxlength="255"<?php if ($s['type'] === 'int'): ?> inputmode="numeric"<?php elseif ($s['type'] === 'decimal'): ?> inputmode="decimal"<?php endif; ?>>
<?php endif; ?>
<?php if ($s['unit'] !== ''): ?>
      <span class="muted"><?= $e($s['unit']) ?></span>
<?php endif; ?>
      <label class="visually-hidden" for="w-<?= $e($s['key']) ?>"><?= $word('SETTINGS_PAGE', 'why_label') ?></label>
      <input id="w-<?= $e($s['key']) ?>" class="set-why" type="text" name="reason" minlength="3" maxlength="500" required placeholder="<?= $word('SETTINGS_PAGE', 'why') ?>">
      <button type="submit" class="btn"><?= $word('SETTINGS_PAGE', 'save') ?></button>
    </div>
  </form>
<?php else: ?>
  <div class="set-row<?php if (($s['unused'] ?? null) !== null): ?> unused<?php endif; ?>" data-key="<?= $e($s['key']) ?>">
    <div class="set-k">
      <a class="set-kt" href="<?= $e($s['href']) ?>"><?= $e($s['name']) ?></a><?php if (!$s['agreed']): ?> <span class="tag needs"><?= $word('SETTINGS_PAGE', 'not_agreed') ?></span><?php endif; ?>
      <p class="set-kd"><?= $e($s['help']) ?><?php if (($s['unused'] ?? null) !== null): ?> <strong class="unused-note"><?= $e($s['unused']) ?></strong><?php endif; ?></p>
<?php if ($s['changed_shown']): ?>
      <p class="set-kh"><?= $say('SETTINGS_PAGE', 'last_changed', $s['changed']) ?></p>
<?php endif; ?>
    </div>
    <div class="set-v">
      <span class="set-val pre"><?php if ($s['value'] === null): ?><span class="muted"><?= $word('SETTINGS_PAGE', 'not_set') ?></span><?php else: ?><?= $e($s['value']) ?><?php if ($s['unit'] !== ''): ?> <?= $e($s['unit']) ?><?php endif; ?><?php endif; ?></span>
<?php if ($canChange): ?>
      <a class="btn" href="<?= $e($s['href']) ?>"><?= $word('SETTINGS_PAGE', 'change') ?></a>
<?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php if ($vat !== []): ?>
<section class="set-card vat-codes" aria-labelledby="vat-h">
  <h2 id="vat-h"><?= $word('SETTINGS_PAGE', 'vat') ?></h2>
<?php foreach ($vat as $c): ?>
  <div class="set-row vat-code">
    <div class="set-k">
      <p class="set-kt"><?= $e($c['label']) ?><?php if ((int) $c['is_active'] !== 1): ?> <span class="tag off"><?= $word('SETTINGS_PAGE', 'vat_off') ?></span><?php endif; ?></p>
      <p class="set-kd"><?= $word('SETTINGS_PAGE', 'code') ?> <code><?= $e($c['code']) ?></code></p>
    </div>
    <div class="set-v"><span class="set-val"><?= $dec($c['rate_percent']) ?>%</span></div>
  </div>
<?php endforeach; ?>
</section>
<?php endif; ?>
<p class="set-foot"><?= $word('SETTINGS_PAGE', 'foot') ?></p>
</div>
<?php endif; ?>
</div>
</div>
