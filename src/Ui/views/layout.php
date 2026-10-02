<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?> - Central Warehouse</title>
<link rel="stylesheet" href="/ui/assets/app.css">
<script src="/ui/assets/app.js" defer></script>
</head>
<body>
<?php if ($who !== null): ?>
<header class="top">
  <a class="brand" href="/ui/">Central Warehouse</a>
<?php if ($searchBox): ?>
  <form class="quick" method="get" action="/ui/search" role="search">
    <input type="search" name="q" placeholder="Item name, barcode or CW code" aria-label="Search items and listings" maxlength="100">
    <button type="submit">Search</button>
  </form>
<?php endif; ?>
  <div class="me">
    <a href="/ui/password"<?php if ($active === 'password'): ?> aria-current="page"<?php endif; ?>><?= $e($who->displayName) ?></a>
    <span class="role"><?= $e($who->rolesLabel()) ?></span>
    <form method="post" action="/ui/logout">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <button type="submit" class="link">Sign out</button>
    </form>
  </div>
</header>
<?php if ($menu !== []): ?>
<nav class="menu" aria-label="Main">
<?php foreach ($menu as $section): ?>
  <div class="menu-group">
    <span class="menu-label"><?= $e($section['section']) ?></span>
<?php foreach ($section['items'] as $item): ?>
<?php if (isset($item['path'])): ?>
    <a href="<?= $u($item['path'], $item['query'] ?? []) ?>"<?php if (($item['key'] ?? null) === $active): ?> aria-current="page"<?php endif; ?>><?= $e($item['label']) ?><?php if (isset($item['badge']) && ($badges[$item['badge']] ?? 0) > 0): ?> <span class="badge"><?= $n($badges[$item['badge']]) ?></span><?php endif; ?></a>
<?php else: ?>
    <span class="soon"><?= $e($item['label']) ?> &middot; coming in Phase <?= $e($item['phase']) ?></span>
<?php endif; ?>
<?php endforeach; ?>
  </div>
<?php endforeach; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<main>
<?php if ($notice !== null): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?= $body ?>
</main>
</body>
</html>
