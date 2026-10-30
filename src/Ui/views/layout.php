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
<body data-unsaved-link="<?= $word('UI', 'unsaved_link') ?>">
<a class="skip" href="#main"><?= $word('UI', 'skip') ?></a>
<?php if (($testSystem ?? false) === true): ?>
<p class="strip test-system" role="note"><?= $word('UI', 'test_system') ?></p>
<?php endif; ?>
<?php if ($who !== null): ?>
<?php
// The shell of the approved redesign (Sidebar.dc.html, Phone.dc.html, 30 Oct 2026; docs/decisions.md RD1, RD5): on a computer the sidebar:
// the CW tile with the name and the stores, the find box, then one entry per section (its coloured icon chip, its name, its count),
// dividers above Items and Reports, the current section tinted with its pages under it (13 px; no third level: a page's group is its
// Show), the person at the bottom (their account and Sign out). On a phone a top bar (the CW tile, the section's name, Find and Menu,
// which opens the same sidebar as a drawer) and the bottom bar (Home, Receive, Count, Approvals, More).
$mark = \CW\Ui\Html::initials($who->displayName) ?: '?';
$nav = $nav ?? []; $pagebar = $pagebar ?? null; $actions = $actions ?? []; $headDrawn = $headDrawn ?? false; $stores = $stores ?? [];
$find = ($searchBox ?? false) === true && $nav !== [];
$here = null;
foreach ($nav as $navItem) {
    $here = $navItem['current'] ? $navItem : $here;
}
?>
<header class="appbar">
  <a class="appbar-home" href="/ui/"><span class="brand-tile" aria-hidden="true">CW</span><span class="visually-hidden"><?= $word('UI', 'brand') ?></span></a>
  <span class="appbar-title"><?php if ($here !== null): ?><?= $e($here['label']) ?><?php else: ?><?= $word('UI', 'brand') ?><?php endif; ?></span>
<?php if ($find): ?>
  <a class="icon-btn" href="/ui/search"><span class="ico ico-search" aria-hidden="true"></span><span class="visually-hidden"><?= $word('UI', 'side_find') ?></span></a>
<?php endif; ?>
<?php if ($nav !== []): ?>
  <a class="icon-btn menu-btn" href="#menu" aria-controls="menu"><span class="ico ico-menu" aria-hidden="true"></span><span class="visually-hidden"><?= $word('UI', 'open_menu') ?> (<?= $say('UI', 'signed_in_as', $who->displayName) ?>)</span></a>
<?php endif; ?>
</header>
<div class="shell">
  <nav class="side" id="menu" aria-label="Main">
    <div class="side-head"><span class="side-title"><?= $word('UI', 'menu') ?></span><a class="btn sm" href="#main"><?= $word('UI', 'close_menu') ?></a></div>
    <a class="side-brand" href="/ui/"><span class="brand-tile" aria-hidden="true">CW</span><span class="brand-text"><span class="brand-name"><?= $word('UI', 'brand') ?></span><?php if ($stores !== []): ?><span class="brand-stores"><?= $e(implode(' · ', $stores)) ?></span><?php endif; ?></span></a>
<?php if ($find): ?>
    <form class="side-find" method="get" action="/ui/search" role="search">
      <label class="visually-hidden" for="side-find"><?= $word('UI', 'side_find') ?></label>
      <span class="ico ico-search" aria-hidden="true"></span><input id="side-find" type="search" name="q" maxlength="100" placeholder="<?= $word('UI', 'side_find') ?>" autocomplete="off">
    </form>
<?php endif; ?>
<?php if ($nav !== []): ?>
    <ul class="nav">
<?php foreach ($nav as $i => $item): ?>
<?php if ($item['gap'] && $i > 0): ?>
    </ul>
    <hr class="nav-gap">
    <ul class="nav">
<?php endif; ?>
<?php $subs = $item['subs']; $held = in_array(true, array_column($subs, 'current'), true); ?>
      <li><a class="nav-item sec-<?= $e($item['key']) ?><?php if ($item['open']): ?> open<?php endif; ?>" href="<?= $u($item['href'], $item['query']) ?>"<?php if ($item['current']): ?> aria-current="<?php if ($held): ?>true<?php else: ?>page<?php endif; ?>"<?php endif; ?>><span class="nav-chip" aria-hidden="true"><span class="ico ico-<?= $e($item['icon']) ?>"></span></span><span class="nav-label"><?= $e($item['label']) ?></span><?php if ($item['count'] > 0): ?><span class="count" title="<?= $n($item['count']) ?> <?= $e($item['countWords']) ?>"><?= $n($item['count']) ?><span class="visually-hidden"> <?= $e($item['countWords']) ?></span></span><?php endif; ?></a>
<?php if ($subs !== []): ?>
        <ul class="nav-sub sec-<?= $e($item['key']) ?>" aria-label="<?= $e($item['label']) ?>">
<?php foreach ($subs as $sub): ?>
          <li><a class="nav-sub-item"<?php if (!$sub['here']): ?> href="<?= $u($sub['href'], $sub['query']) ?>"<?php endif; ?><?php if ($sub['current']): ?> aria-current="page"<?php endif; ?>><span class="nav-label"><?= $e($sub['label']) ?></span><?php if ($sub['count'] > 0): ?><span class="count" title="<?= $n($sub['count']) ?> <?= $e($sub['countWords']) ?>"><?= $n($sub['count']) ?><span class="visually-hidden"> <?= $e($sub['countWords']) ?></span></span><?php endif; ?></a></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
    <div class="side-user">
      <span class="avatar" aria-hidden="true"><?= $e($mark) ?></span>
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
  <main id="main" class="work<?php if (($list ?? false) === true): ?> is-list<?php endif; ?>" tabindex="-1">
    <div class="page">
<?php if (!$headDrawn): ?>
      <div class="frame-only">
        <?= $partial('page_frame', ['switchedOff' => $switchedOff ?? null]) ?>
      </div>
<?php if ($actions !== []): ?>
      <div class="toolbar page-actions">
        <?= $partial('split', ['actions' => $actions, 'main' => $pagebar !== null]) ?>
      </div>
<?php endif; ?>
<?php if (($showRow ?? '') !== ''): ?>
      <div class="show-row"><?= $partial('show_menu', ['choices' => $segments ?? []]) ?></div>
<?php endif; ?>
<?php endif; ?>
<?= $body ?>
    </div>
  </main>
</div>
<?php if (($tabs ?? []) !== []): ?>
<nav class="tabbar" aria-label="Main tasks">
<?php foreach ($tabs as $tab): ?>
  <a href="<?= $u($tab['href'], $tab['query']) ?>"<?php if ($tab['current']): ?> aria-current="page"<?php endif; ?>><span class="ico ico-<?= $e($tab['icon']) ?>" aria-hidden="true"></span><span><?= $e($tab['label']) ?></span><?php if ($tab['count'] > 0): ?><span class="tb-badge" title="<?= $n($tab['count']) ?> <?= $e($tab['countWords']) ?>"><?= $n($tab['count']) ?><span class="visually-hidden"> <?= $e($tab['countWords']) ?></span></span><?php endif; ?></a>
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
