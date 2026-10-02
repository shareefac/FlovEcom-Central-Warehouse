<h1>Central Warehouse</h1>
<p>Signed in as <?= $e($name) ?> (<?= $e($roles) ?>).</p>
<?php if ($noRoles): ?>
<p class="note">You have no roles yet: ask an admin to give you the roles of your job.</p>
<?php endif; ?>
<?php if ($sections !== []): ?>
<div class="home-sections">
<?php foreach ($sections as $section): ?>
  <section class="card">
    <h2><?= $e($section['section']) ?></h2>
    <ul class="plain">
<?php foreach ($section['items'] as $item): ?>
<?php if (isset($item['path'])): ?>
      <li><a href="<?= $u($item['path'], $item['query'] ?? []) ?>"><?= $e($item['label']) ?></a></li>
<?php else: ?>
      <li><span class="soon"><?= $e($item['label']) ?> &middot; coming in Phase <?= $e($item['phase']) ?></span></li>
<?php endif; ?>
<?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>
</div>
<?php endif; ?>
