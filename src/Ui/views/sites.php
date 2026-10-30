<?php
// Settings › Stores, a board and each store's part (the approved board and record patterns of 29 Oct 2026, GI_List and PO_View; docs/
// decisions.md W13.18): read only, the page changes nothing. The heading with what each mode means under its "?"; the stores grouped by their
// mode in CW (live, watching only, off), each group a coloured heading with its count over a card with its colour on the left edge, a row a
// store: its mode filled solid, what the website says, its last contact, what waits to send; a row opens the store's part below. Then each
// store's part: what is wrong first, its facts in the paper card, the server's commands folded (for the Admin).
$modes = ['live', 'shadow', 'off'];
?>
<?= $head(\CW\Ui\Words::MENU['sites'], intro: 'sites', aside: $explain('site_modes', \CW\Ui\Words::SITES['mode'])) ?>
<div class="set-shell">
<?= $partial('settings_nav', ['current' => 'sites']) ?>
<div class="set-main">
<?php if ($sites === []): ?>
<?= $empty(\CW\Ui\Words::SITES['none']) ?>
<?php else: ?>
<?php foreach ($modes as $mode): ?>
<?php $rows = array_values(array_filter($sites, static fn (array $x): bool => $x['mode'] === $mode)); ?>
<?php if ($rows !== []): ?>
<div class="grp-block doc-group <?= $e(\CW\Ui\Words::tone('MODE', $mode)) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($mode) ?>"><?= $word('MODE', $mode) ?></button> <span class="grp-count"><?= $n(count($rows)) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($mode) ?>">
  <table class="stack list board doc-list sites">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('MENU', 'sites') ?></th>
        <th scope="col" class="c-status"><?= $word('SITES', 'mode') ?></th>
        <th scope="col"><?= $word('SITES', 'site_mode') ?></th>
        <th scope="col"><?= $word('SITES', 'contact') ?></th>
        <th scope="col"><?= $word('SITES', 'queue') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $s): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="#site-<?= $e($s['code']) ?>"><?= $e($s['name']) ?></a><?php if ($s['no_contact'] !== null || $s['mismatch']): ?> <span class="tag blocked"><?= $word('SITES', 'look') ?></span><?php endif; ?></th>
        <td class="c-status doc-st <?= $e(\CW\Ui\Words::tone('MODE', $s['mode'])) ?>"><span class="chip"><?= $word('MODE', $s['mode']) ?></span></td>
        <td data-label="<?= $word('SITES', 'site_mode') ?>"><?php if ($s['site_mode'] === null): ?><span class="muted"><?= $word('SITES', 'not_reported') ?></span><?php else: ?><?= $word('MODE', $s['site_mode']) ?><?php endif; ?></td>
        <td data-label="<?= $word('SITES', 'contact') ?>"><?= $e($s['contact']) ?></td>
        <td data-label="<?= $word('SITES', 'queue') ?>"><?= $e($s['queue_text']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php foreach ($sites as $s): ?>
<section class="doc-related site" id="site-<?= $e($s['code']) ?>" aria-labelledby="site-<?= $e($s['code']) ?>-h">
  <div class="board-head"><h2 id="site-<?= $e($s['code']) ?>-h" class="board-sub"><?= $e($s['name']) ?></h2> <?= $stateChip('MODE', $s['mode']) ?></div>
<?php if ($s['no_contact'] !== null): ?>
  <p class="error" role="alert"><?= $e($s['no_contact']) ?></p>
<?php endif; ?>
<?php if ($s['mismatch']): ?>
  <p class="note"><?= $word('SITES', 'mismatch') ?></p>
<?php endif; ?>
  <article class="doc-view">
    <dl class="doc-facts">
      <dt><?= $word('SITES', 'mode') ?></dt><dd><?= $word('MODE', $s['mode']) ?></dd>
      <dt><?= $word('SITES', 'site_mode') ?></dt><dd><?php if ($s['site_mode'] === null): ?><span class="muted"><?= $word('SITES', 'not_reported') ?></span><?php else: ?><?= $word('MODE', $s['site_mode']) ?><?php endif; ?></dd>
      <dt><?= $word('SITES', 'contact') ?></dt><dd><?= $e($s['contact']) ?></dd>
      <dt><?= $word('SITES', 'queue') ?></dt><dd><?= $e($s['queue_text']) ?></dd>
      <dt><?= $word('SITES', 'dead') ?></dt><dd><?= $e($s['dead_text']) ?></dd>
      <dt><?= $word('SITES', 'feed') ?></dt><dd><?= $e($s['feed_text']) ?></dd>
      <dt><?= $word('SITES', 'sells_from') ?></dt><dd><?php if ($s['sells_text'] === ''): ?><span class="muted"><?= $word('WAREHOUSES', 'none') ?></span><?php else: ?><?= $e($s['sells_text']) ?><?php endif; ?></dd>
      <dt><?= $word('SITES', 'writer') ?></dt><dd><?= $e($s['writer_text']) ?></dd>
      <dt><?= $word('SITES', 'relay') ?></dt><dd><?= $e($s['relay_text']) ?></dd>
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
</section>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>
