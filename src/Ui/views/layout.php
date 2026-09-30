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
  <nav aria-label="Main">
    <a href="/ui/"<?php if ($active === 'dashboard'): ?> aria-current="page"<?php endif; ?>>Dashboard</a>
    <a href="/ui/review?queue=Key"<?php if ($active === 'review'): ?> aria-current="page"<?php endif; ?>>Review</a>
    <a href="/ui/review?queue=pending"<?php if ($active === 'pending'): ?> aria-current="page"<?php endif; ?>>Second approval<?php if ($pendingCount): ?> <span class="badge"><?= $n($pendingCount) ?></span><?php endif; ?></a>
    <a href="/ui/search"<?php if ($active === 'search'): ?> aria-current="page"<?php endif; ?>>Search</a>
  </nav>
  <form class="quick" method="get" action="/ui/search" role="search">
    <input type="search" name="q" placeholder="Item name, barcode or CW code" aria-label="Search items and listings" maxlength="100">
    <button type="submit">Search</button>
  </form>
  <div class="me">
    <a href="/ui/password"<?php if ($active === 'password'): ?> aria-current="page"<?php endif; ?>><?= $e($who->displayName) ?></a>
    <span class="role"><?= $e($who->role) ?></span>
    <form method="post" action="/ui/logout">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <button type="submit" class="link">Sign out</button>
    </form>
  </div>
</header>
<?php endif; ?>
<main>
<?php if ($notice !== null): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?= $body ?>
</main>
</body>
</html>
