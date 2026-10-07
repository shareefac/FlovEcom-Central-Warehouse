<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title><?= $e($title) ?> - Central Warehouse</title>
<link rel="stylesheet" href="/ui/assets/app.css">
<script src="/ui/assets/app.js" defer></script>
</head>
<body>
<a class="skip" href="#main"><?= $word('UI', 'skip') ?></a>
<?php if (($testSystem ?? false) === true): ?>
<p class="strip test-system" role="note"><?= $word('UI', 'test_system') ?></p>
<?php endif; ?>
<?php if ($who !== null): ?>
<?php $initials = preg_split('/\s+/u', trim($who->displayName), -1, PREG_SPLIT_NO_EMPTY) ?: ['?']; ?>
<div class="shell">
  <div class="side">
    <header class="top">
      <a class="brand" href="/ui/"><span class="brand-mark" aria-hidden="true">CW</span><span class="brand-name">Central Warehouse</span></a>
<?php if ($menu !== []): ?>
      <a class="menu-link" href="#menu"><span class="ico ico-more" aria-hidden="true"></span><span><?= $word('UI', 'menu') ?></span></a>
<?php endif; ?>
      <details class="me">
        <summary><span class="avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr($initials[0], 0, 1) . (isset($initials[1]) ? mb_substr($initials[1], 0, 1) : ''))) ?></span><span class="me-name"><?= $e($who->displayName) ?></span><span class="visually-hidden"> (<?= $word('UI', 'account') ?>)</span></summary>
        <div class="me-panel">
          <p class="who"><?= $e($who->displayName) ?></p>
          <p class="jobs"><?= $word('UI', 'you_work_as') ?> <span class="role"><?= $jobs($who->roles) ?></span></p>
          <p><a class="btn secondary sm full" href="/ui/password"<?php if ($active === 'password'): ?> aria-current="page"<?php endif; ?>><?= $word('UI', 'my_account') ?></a></p>
          <form method="post" action="/ui/logout">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <button type="submit" class="btn secondary sm full"><?= $word('UI', 'sign_out') ?></button>
          </form>
        </div>
      </details>
    </header>
<?php if ($menu !== []): ?>
    <nav class="menu" id="menu" aria-label="Main">
      <div class="sheet-head"><span class="sheet-title"><?= $word('UI', 'menu') ?></span><a class="btn secondary sm" href="#main"><?= $word('UI', 'close_menu') ?></a></div>
<?php if ($searchBox): ?>
      <form class="menu-search" method="get" action="/ui/search" role="search">
        <label class="visually-hidden" for="find"><?= $word('UI', 'search_label') ?></label>
        <input id="find" type="search" name="q" placeholder="<?= $word('UI', 'search_placeholder') ?>" maxlength="100" autocomplete="off">
        <button type="submit" class="btn secondary sm icon-btn"><span class="visually-hidden"><?= $word('UI', 'search_button') ?></span><span class="ico ico-search" aria-hidden="true"></span></button>
      </form>
<?php endif; ?>
<?php foreach ($menu as $section): ?>
      <div class="menu-group<?php if (($section['key'] ?? '') === 'home'): ?> menu-top<?php endif; ?>">
        <span class="menu-label"><?= $e($section['section']) ?></span>
<?php foreach ($section['items'] as $item): ?>
<?php $count = isset($item['badge']) ? (int) ($badges[$item['badge']] ?? 0) : 0; ?>
        <a href="<?= $u($item['path'], $item['query'] ?? []) ?>"<?php if (($item['key'] ?? null) === $active): ?> aria-current="page"<?php endif; ?>><?= $e($item['label']) ?><?php if ($count > 0): ?> <span class="badge" title="<?= $n($count) ?> <?= $word('BADGE', $item['badge']) ?>"><?= $n($count) ?><span class="visually-hidden"> <?= $word('BADGE', $item['badge']) ?></span></span><?php endif; ?></a>
<?php endforeach; ?>
      </div>
<?php endforeach; ?>
    </nav>
<?php endif; ?>
  </div>
  <main id="main" class="main" tabindex="-1">
    <div class="page">
<?php if (($switchedOff ?? null) !== null): ?>
      <div class="alert needs admin-off" role="note">
        <p class="alert-title"><?= $e($switchedOff) ?></p>
        <?= $explain('admin_off', 'switched off') ?>
      </div>
<?php endif; ?>
<?php if ($notice !== null): ?>
      <p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?= $body ?>
    </div>
  </main>
</div>
<?php if (($tabs ?? []) !== []): ?>
<nav class="tabbar" aria-label="Main tasks">
<?php foreach ($tabs as $tab): ?>
<?php $count = isset($tab['badge']) ? (int) ($badges[$tab['badge']] ?? 0) : 0; ?>
  <a href="<?= $u($tab['path'], $tab['query'] ?? []) ?>"<?php if ($tab['key'] === $active): ?> aria-current="page"<?php endif; ?>><span class="ico ico-<?= $e($tab['key']) ?>" aria-hidden="true"></span><span><?= $e($tab['label']) ?></span><?php if ($count > 0): ?><span class="badge" title="<?= $n($count) ?> <?= $word('BADGE', $tab['badge']) ?>"><?= $n($count) ?><span class="visually-hidden"> <?= $word('BADGE', $tab['badge']) ?></span></span><?php endif; ?></a>
<?php endforeach; ?>
</nav>
<?php endif; ?>
<?php else: ?>
<main id="main" class="main bare" tabindex="-1">
  <div class="page">
<?php if ($notice !== null): ?>
    <p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?= $body ?>
  </div>
</main>
<?php endif; ?>
</body>
</html>
