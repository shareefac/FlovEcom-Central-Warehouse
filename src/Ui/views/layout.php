<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
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
<?php $nav = $nav ?? []; $pagebar = $pagebar ?? null; $segments = $segments ?? []; $actions = $actions ?? []; $flow = $flow ?? null; ?>
<header class="appbar">
<?php if ($nav !== []): ?>
  <a class="icon-btn menu-btn" href="#menu" aria-controls="menu"><span class="ico ico-menu" aria-hidden="true"></span><span class="visually-hidden"><?= $word('UI', 'open_menu') ?></span></a>
<?php endif; ?>
  <a class="brand" href="/ui/"><span class="brand-mark" aria-hidden="true">CW</span><span class="brand-name"><?= $word('UI', 'brand') ?></span></a>
  <div class="appbar-end">
<?php if ($searchBox): ?>
    <a class="icon-btn" href="/ui/search"><span class="ico ico-search" aria-hidden="true"></span><span class="visually-hidden"><?= $word('UI', 'find') ?></span></a>
<?php endif; ?>
    <a class="icon-btn" href="/ui/#about"><span class="ico ico-help" aria-hidden="true"></span><span class="visually-hidden"><?= $word('UI', 'help_link') ?></span></a>
  </div>
</header>
<div class="shell">
  <nav class="side" id="menu" aria-label="Main">
    <div class="side-head"><span class="side-title"><?= $word('UI', 'menu') ?></span><a class="btn secondary sm" href="#main"><?= $word('UI', 'close_menu') ?></a></div>
<?php if ($nav !== []): ?>
    <ul class="nav">
<?php foreach ($nav as $item): ?>
      <li><a class="nav-item" href="<?= $u($item['href'], $item['query']) ?>"<?php if ($item['current']): ?> aria-current="page"<?php endif; ?>><span class="ico ico-<?= $e($item['key']) ?>" aria-hidden="true"></span><span class="nav-label"><?= $e($item['label']) ?></span><?php if ($item['count'] > 0): ?><span class="count<?php if ($item['key'] === 'approvals'): ?> hot<?php endif; ?>" title="<?= $n($item['count']) ?> <?= $e($item['countWords']) ?>"><?= $n($item['count']) ?><span class="visually-hidden"> <?= $e($item['countWords']) ?></span></span><?php endif; ?></a></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
    <div class="side-user">
      <span class="avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr($initials[0], 0, 1) . (isset($initials[1]) ? mb_substr($initials[1], 0, 1) : ''))) ?></span>
      <p class="who"><strong><?= $e($who->displayName) ?></strong><span class="who-jobs"><span class="visually-hidden"><?= $word('UI', 'you_work_as') ?> </span><span class="role"><?= $jobs($who->roles) ?></span></span></p>
      <a class="side-link" href="/ui/password"<?php if ($active === 'password'): ?> aria-current="page"<?php endif; ?>><span class="ico ico-key" aria-hidden="true"></span><span><?= $word('UI', 'my_account') ?></span></a>
      <form class="signout" method="post" action="/ui/logout">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="side-link"><span class="ico ico-signout" aria-hidden="true"></span><span><?= $word('UI', 'sign_out') ?></span></button>
      </form>
    </div>
  </nav>
  <a class="scrim" href="#main" tabindex="-1" aria-hidden="true"></a>
  <main id="main" class="work" tabindex="-1">
<?php if ($pagebar !== null): ?>
    <header class="pagebar">
      <div class="pagebar-title">
        <p class="pagebar-name"><?= $e($pagebar['label']) ?></p>
        <p class="page-desc"><?= $e($pagebar['desc']) ?></p>
      </div>
<?php if ($flow !== null): ?>
      <ol class="flow" aria-label="<?= $word('FLOW', 'label') ?>">
<?php foreach ($flow as $step): ?>
        <li><?php if ($step['href'] !== null): ?><a href="<?= $u($step['href'], $step['query']) ?>"<?php if ($step['current']): ?> aria-current="step"<?php endif; ?>><?php else: ?><span class="flow-off"><?php endif; ?><span class="flow-n" aria-hidden="true"><?= $n($step['n']) ?></span><span class="flow-txt"><span class="flow-t"><?= $e($step['label']) ?></span><?php if ($step['text'] !== ''): ?><span class="flow-c"><?= $e($step['text']) ?></span><?php endif; ?></span><?php if ($step['href'] !== null): ?></a><?php else: ?></span><?php endif; ?></li>
<?php endforeach; ?>
      </ol>
<?php endif; ?>
      <nav class="tabs" aria-label="<?= $e($pagebar['label']) ?>">
<?php foreach ($pagebar['tabs'] as $tab): ?>
<?php if ($tab['soon']): ?>
        <span class="tab soon" aria-disabled="true" title="<?= $word('UI', 'soon_title') ?>"><span><?= $e($tab['label']) ?></span><span class="count soon"><?= $word('UI', 'soon') ?></span></span>
<?php else: ?>
        <a class="tab" href="<?= $u($tab['href'], $tab['query']) ?>"<?php if ($tab['current']): ?> aria-current="page"<?php endif; ?>><span><?= $e($tab['label']) ?></span><?php if ($tab['count'] > 0): ?><span class="count" title="<?= $n($tab['count']) ?> <?= $e($tab['countWords']) ?>"><?= $n($tab['count']) ?><span class="visually-hidden"> <?= $e($tab['countWords']) ?></span></span><?php endif; ?></a>
<?php endif; ?>
<?php endforeach; ?>
      </nav>
    </header>
<?php endif; ?>
    <div class="content">
      <div class="page">
<?php if ($segments !== []): ?>
        <nav class="seg" aria-label="<?= $e($pagebar['tab']) ?>">
<?php foreach ($segments as $seg): ?>
          <a class="seg-item" href="<?= $u($seg['href'], $seg['query']) ?>"<?php if ($seg['current']): ?> aria-current="page"<?php endif; ?>><span><?= $e($seg['label']) ?></span><?php if ($seg['count'] > 0): ?><span class="count" title="<?= $n($seg['count']) ?> <?= $e($seg['countWords']) ?>"><?= $n($seg['count']) ?><span class="visually-hidden"> <?= $e($seg['countWords']) ?></span></span><?php endif; ?></a>
<?php endforeach; ?>
        </nav>
<?php endif; ?>
<?php if ($actions !== []): ?>
        <div class="toolbar page-actions">
          <?= $partial('split', ['actions' => $actions]) ?>
        </div>
<?php endif; ?>
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
