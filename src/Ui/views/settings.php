<h1><?= $word('MENU', 'settings') ?></h1>
<?= $intro('settings') ?>
<p class="see-also"><a href="/ui/reference/reasons"><?= $word('PAGE_TITLE', 'reasons') ?></a> <a href="/ui/reference/series"><?= $word('PAGE_TITLE', 'series') ?></a></p>
<section class="card company-summary<?php if (!$company['confirmed']): ?> waiting<?php endif; ?>" aria-labelledby="company-h">
  <h2 id="company-h"><?= $word('MENU', 'company') ?></h2>
  <p><?= $word('SETTINGS_PAGE', 'company_text') ?><?php if ($company['legal_name'] !== ''): ?>: <strong><?= $e($company['legal_name']) ?></strong><?php endif; ?>.
<?php if ($company['confirmed']): ?>
    <?= $chip('done', \CW\Ui\Words::COMPANY['confirmed']) ?></p>
<?php else: ?>
    <?= $chip('blocked', \CW\Ui\Words::COMPANY['not_confirmed']) ?> <?= $word('SETTINGS_PAGE', 'not_confirmed_text') ?><?php if ($company['missing'] !== []): ?> <?= $say('COMPANY', 'missing', implode(', ', $company['missing'])) ?><?php endif; ?></p>
<?php endif; ?>
  <p><?php if ($company['canEdit']): ?><a class="button primary-link" href="/ui/reference/company"><?= $word('SETTINGS_PAGE', 'edit_company') ?></a><?php else: ?><a href="/ui/reference/company"><?= $word('SETTINGS_PAGE', 'see_company') ?></a><?php endif; ?></p>
</section>

<h2><?= $word('SETTINGS_PAGE', 'settings') ?></h2>
<p class="muted"><?= $word('SETTINGS_PAGE', 'settings_text') ?></p>
<?php foreach ($topics as $topic => $rows): ?>
<h3><?= $e($topic) ?></h3>
<div class="table-wrap">
<table class="stack settings">
  <thead>
    <tr>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'setting') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'status') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'value') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'what') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'changed') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $s): ?>
    <tr class="<?php if ($s['agreed']): ?>done<?php else: ?>needs<?php endif; ?>">
      <th scope="row" class="c-head"><?= $e($s['name']) ?> <small class="muted"><code><?= $e($s['key']) ?></code></small></th>
      <td class="c-status"><?php if ($s['agreed']): ?><?= $chip('done', \CW\Ui\Words::SETTINGS_PAGE['agreed']) ?><?php else: ?><?= $chip('needs', \CW\Ui\Words::SETTINGS_PAGE['not_agreed']) ?><?php endif; ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'value') ?>" class="pre"><?php if ($s['value'] === null): ?><span class="muted"><?= $word('SETTINGS_PAGE', 'not_set') ?></span><?php else: ?><?= $e($s['value']) ?><?php endif; ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'what') ?>"><?= $e($s['help']) ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'changed') ?>"><?= $e($s['changed']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endforeach; ?>

<div class="head-help">
  <h2><?= $word('SETTINGS_PAGE', 'rules') ?></h2>
  <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
</div>
<p class="muted"><?= $word('SETTINGS_PAGE', 'rules_text') ?></p>
<ul class="plain rules-list">
<?php foreach ($rules as $r): ?>
  <li><strong><?= $e($r['name']) ?>:</strong> <?= $e($r['review']) ?><?php if ($r['approval'] !== null): ?> <?= $e($r['approval']) ?><?php endif; ?> <?= $e($r['reject']) ?></li>
<?php endforeach; ?>
</ul>

<h2><?= $word('SETTINGS_PAGE', 'vat') ?></h2>
<div class="table-wrap">
<table class="stack vat">
  <thead><tr><th scope="col"><?= $word('SETTINGS_PAGE', 'meaning') ?></th><th scope="col"><?= $word('SETTINGS_PAGE', 'code') ?></th><th scope="col" class="num"><?= $word('SETTINGS_PAGE', 'rate') ?></th><th scope="col"><?= $word('SETTINGS_PAGE', 'in_use') ?></th></tr></thead>
  <tbody>
<?php foreach ($vat as $c): ?>
    <tr>
      <th scope="row" class="c-head"><?= $e($c['label']) ?></th>
      <td data-label="<?= $word('SETTINGS_PAGE', 'code') ?>"><code><?= $e($c['code']) ?></code></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'rate') ?>" class="num"><?= $dec($c['rate_percent']) ?>%</td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'in_use') ?>"><?php if ((int) $c['is_active'] === 1): ?><?= $word('SETTINGS_PAGE', 'yes') ?><?php else: ?><?= $word('SETTINGS_PAGE', 'no') ?><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="muted"><?= $word('SETTINGS_PAGE', 'footer') ?></p>
