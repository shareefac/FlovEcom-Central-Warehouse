<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<title><?= $e($title) ?> - Central Warehouse</title>
<link rel="stylesheet" href="<?= $e(\CW\Ui\Assets::url('app.css')) ?>">
<script src="<?= $e(\CW\Ui\Assets::url('app.js')) ?>" defer></script>
</head>
<body>
<a class="skip" href="#main"><?= $word('UI', 'skip') ?></a>
<?php if (($testSystem ?? false) === true): ?>
<p class="strip test-system" role="note"><?= $word('UI', 'test_system') ?></p>
<?php endif; ?>
<?php if ($who !== null): ?>
<?php $initials = preg_split('/\s+/u', trim($who->displayName), -1, PREG_SPLIT_NO_EMPTY) ?: ['?']; ?>
<?php $nav = $nav ?? []; $pagebar = $pagebar ?? null; $segments = $segments ?? []; $actions = $actions ?? []; $flow = $flow ?? null; $headDrawn = $headDrawn ?? false; ?>
<?php $waiting = array_sum(array_map(static fn (array $item): int => (int) $item['count'], $nav)); ?>
<header class="appbar">
  <a class="brand" href="/ui/"><span class="brand-mark" aria-hidden="true">CW</span><span class="visually-hidden"><?= $word('UI', 'brand') ?></span></a>
  <p class="appbar-page"><?php if ($pagebar !== null): ?><?= $e($pagebar['tab']) ?><?php else: ?><?= $e($title) ?><?php endif; ?></p>
<?php if ($searchBox): ?>
  <a class="icon-btn" href="/ui/search"><span class="ico ico-search" aria-hidden="true"></span><span class="visually-hidden"><?= $word('UI', 'find') ?></span></a>
<?php endif; ?>
<?php if ($nav !== []): ?>
  <a class="icon-btn bell" href="/ui/"><span class="ico ico-bell" aria-hidden="true"></span><span class="visually-hidden"><?= $word('UI', 'bell') ?></span><?php if ($waiting > 0): ?><span class="bell-n"><?= $n($waiting) ?></span><?php endif; ?></a>
  <a class="icon-btn menu-btn" href="#menu" aria-controls="menu"><span class="ico ico-menu" aria-hidden="true"></span><span class="visually-hidden"><?= $word('UI', 'open_menu') ?></span></a>
<?php endif; ?>
</header>
<div class="shell">
  <nav class="side" id="menu" aria-label="Main">
    <div class="side-head"><span class="side-title"><?= $word('UI', 'menu') ?></span><a class="btn sm" href="#main"><?= $word('UI', 'close_menu') ?></a></div>
    <div class="side-top">
      <a class="brand" href="/ui/"><span class="brand-mark" aria-hidden="true">CW</span><span class="brand-name"><?= $word('UI', 'brand') ?></span></a>
      <div class="side-tools">
<?php if ($nav !== []): ?>
        <a class="icon-btn bell" href="/ui/"><span class="ico ico-bell" aria-hidden="true"></span><span class="visually-hidden"><?= $word('UI', 'bell') ?></span><?php if ($waiting > 0): ?><span class="bell-n"><?= $n($waiting) ?></span><?php endif; ?></a>
<?php endif; ?>
        <a class="icon-btn" href="/ui/#about"><span class="ico ico-help" aria-hidden="true"></span><span class="visually-hidden"><?= $word('UI', 'help_link') ?></span></a>
      </div>
    </div>
<?php if ($searchBox): ?>
    <a class="side-find" href="/ui/search"><span class="ico ico-search" aria-hidden="true"></span><span><?= $word('UI', 'find') ?></span></a>
<?php endif; ?>
<?php if ($nav !== []): ?>
    <p class="side-label" aria-hidden="true"><?= $word('UI', 'menu') ?></p>
    <ul class="nav">
<?php foreach ($nav as $item): ?>
      <li><a class="nav-item" href="<?= $u($item['href'], $item['query']) ?>"<?php if ($item['current']): ?> aria-current="page"<?php endif; ?>><span class="ico ico-<?= $e($item['key']) ?>" aria-hidden="true"></span><span class="nav-label"><?= $e($item['label']) ?></span><?php if ($item['count'] > 0): ?><span class="count<?php if ($item['key'] === 'approvals'): ?> hot<?php endif; ?>" title="<?= $n($item['count']) ?> <?= $e($item['countWords']) ?>"><?= $n($item['count']) ?><span class="visually-hidden"> <?= $e($item['countWords']) ?></span></span><?php endif; ?></a></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
    <div class="side-user">
      <span class="avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr($initials[0], 0, 1) . (isset($initials[1]) ? mb_substr($initials[1], 0, 1) : ''))) ?></span>
      <p class="who"><strong><?= $e($who->displayName) ?></strong><span class="who-jobs"><span class="visually-hidden"><?= $word('UI', 'you_work_as') ?> </span><span class="role"><?= $jobs($who->roles) ?></span></span></p>
      <details class="user-menu"<?php if ($active === 'password'): ?> open<?php endif; ?>>
        <summary class="icon-btn" title="<?= $word('UI', 'account') ?>"><span class="ico ico-more" aria-hidden="true"></span><span class="visually-hidden"><?= $word('UI', 'account') ?></span></summary>
        <div class="user-pop">
          <a class="side-link" href="/ui/password"<?php if ($active === 'password'): ?> aria-current="page"<?php endif; ?>><span class="ico ico-key" aria-hidden="true"></span><span><?= $word('UI', 'my_account') ?></span></a>
          <form class="signout" method="post" action="/ui/logout">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <button type="submit" class="side-link"><span class="ico ico-signout" aria-hidden="true"></span><span><?= $word('UI', 'sign_out') ?></span></button>
          </form>
        </div>
      </details>
    </div>
  </nav>
  <a class="scrim" href="#main" tabindex="-1" aria-hidden="true"></a>
  <main id="main" class="work" tabindex="-1">
    <div class="page">
<?php if (!$headDrawn): ?>
      <div class="frame-only">
        <?= $partial('page_frame', ['pagebar' => $pagebar, 'flow' => $flow, 'segments' => $segments, 'switchedOff' => $switchedOff ?? null]) ?>
      </div>
<?php if ($actions !== []): ?>
      <div class="toolbar page-actions">
        <?= $partial('split', ['actions' => $actions, 'main' => $pagebar !== null]) ?>
      </div>
<?php endif; ?>
<?php endif; ?>
<?= $body ?>
    </div>
  </main>
</div>
<?php if (($tabs ?? []) !== []): ?>
<nav class="tabbar" aria-label="Main tasks">
<?php foreach ($tabs as $tab): ?>
  <a href="<?= $u($tab['href'], $tab['query']) ?>"<?php if ($tab['current']): ?> aria-current="page"<?php endif; ?>><span class="ico ico-<?= $e($tab['key']) ?>" aria-hidden="true"></span><span><?= $e($tab['label']) ?></span><?php if ($tab['count'] > 0): ?><span class="tb-badge" title="<?= $n($tab['count']) ?> <?= $e($tab['countWords']) ?>"><?= $n($tab['count']) ?><span class="visually-hidden"> <?= $e($tab['countWords']) ?></span></span><?php endif; ?></a>
<?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($notice !== null): ?>
<p class="notice toast" role="status"><?= $e($notice) ?></p>
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
