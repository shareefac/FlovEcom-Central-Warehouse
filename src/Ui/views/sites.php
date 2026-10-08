<p class="crumbs"><a href="/ui/reference/settings"><?= $word('MENU', 'settings') ?></a></p>
<div class="head-help">
  <h1><?= $word('MENU', 'sites') ?></h1>
  <?= $explain('site_modes', \CW\Ui\Words::SITES['mode']) ?>
</div>
<?= $intro('sites') ?>
<?php if ($sites === []): ?>
<?= $empty(\CW\Ui\Words::SITES['none']) ?>
<?php else: ?>
<?php foreach ($sites as $s): ?>
<article class="card site" id="site-<?= $e($s['code']) ?>">
  <h2><?= $e($s['name']) ?> <?= $stateChip('MODE', $s['mode']) ?></h2>
<?php if ($s['no_contact'] !== null): ?>
  <p class="error" role="alert"><?= $e($s['no_contact']) ?></p>
<?php endif; ?>
<?php if ($s['mismatch']): ?>
  <p class="note"><?= $word('SITES', 'mismatch') ?></p>
<?php endif; ?>
  <dl class="wide">
    <dt><?= $word('SITES', 'mode') ?></dt><dd><?= $word('MODE', $s['mode']) ?></dd>
    <dt><?= $word('SITES', 'site_mode') ?></dt><dd><?php if ($s['site_mode'] === null): ?><span class="muted"><?= $word('SITES', 'not_reported') ?></span><?php else: ?><?= $word('MODE', $s['site_mode']) ?><?php endif; ?></dd>
    <dt><?= $word('SITES', 'contact') ?></dt><dd><?= $e($s['contact']) ?></dd>
    <dt><?= $word('SITES', 'queue') ?></dt><dd><?= $e($s['queue_text']) ?></dd>
    <dt><?= $word('SITES', 'dead') ?></dt><dd><?= $e($s['dead_text']) ?></dd>
    <dt><?= $word('SITES', 'feed') ?></dt><dd><?= $e($s['feed_text']) ?></dd>
    <dt><?= $word('SITES', 'sells_from') ?></dt><dd><?php if ($s['sells_text'] === ''): ?><span class="muted"><?= $word('WAREHOUSES', 'none') ?></span><?php else: ?><?= $e($s['sells_text']) ?><?php endif; ?></dd>
    <dt><?= $word('SITES', 'writer') ?></dt><dd><?= $e($s['writer_text']) ?></dd>
  </dl>
  <details class="fold tech">
    <summary><?= $word('CONFIG', 'technical') ?></summary>
    <dl class="tech">
      <dt><?= $word('SITES', 'code') ?></dt><dd><code><?= $e($s['code']) ?></code></dd>
      <dt><?= $word('SITES', 'connector') ?></dt><dd><?= $e($s['connector'] ?? '') ?></dd>
      <dt><?= $word('SITES', 'key') ?></dt><dd><?php if ($s['has_key']): ?><?= $word('CONFIG', 'yes') ?><?php else: ?><?= $word('CONFIG', 'no') ?><?php endif; ?></dd>
      <dt><?= $word('SITES', 'ips') ?></dt><dd><?= $n($s['ips']) ?></dd>
    </dl>
    <p class="muted"><?= $word('SITES', 'commands') ?></p>
    <ul class="plain commands">
<?php foreach ($s['commands'] as $c): ?>
      <li><?= $e($c['what']) ?><br><code><?= $e($c['command']) ?></code></li>
<?php endforeach; ?>
    </ul>
  </details>
</article>
<?php endforeach; ?>
<?php endif; ?>
