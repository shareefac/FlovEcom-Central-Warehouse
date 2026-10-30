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
// The shell of the approved HM_Home and HM_Phone (docs/decisions.md DC7, DC28): on a computer a white sidebar with the plain name, Home, the
// "Every day" entries and the "More" sections (words only, a magenta count where something waits; the current section's pages under it,
// the page's segments one more indent: no tab row on any page), the person at the bottom (their
// account and Sign out); on a phone a top bar with the name and the person's initials, which open the same menu, and the bottom bar. A page
// with a document bar (no bottom bar) also shows the menu button at the top bar's left (PI_New_Phone).
$mark = \CW\Ui\Html::initials($who->displayName) ?: '?';
$nav = $nav ?? []; $pagebar = $pagebar ?? null; $segments = $segments ?? []; $actions = $actions ?? []; $headDrawn = $headDrawn ?? false;
// The sidebar's tree (DC28): the current section's pages under its entry, the current page's segments one more indent; the deepest current
// link is the page (aria-current="page"), the entries above it say they hold it (aria-current="true").
$tree = false;
foreach ($nav as $navItem) {
    $tree = $tree || in_array(true, array_column($navItem['subs'] ?? [], 'current'), true);
}
?>
<header class="appbar">
<?php if ($nav !== []): ?>
  <a class="icon-btn menu-btn" href="#menu" aria-controls="menu"><span class="ico ico-menu" aria-hidden="true"></span><span class="visually-hidden"><?= $word('UI', 'open_menu') ?></span></a>
<?php endif; ?>
  <a class="brand" href="/ui/"><span class="brand-name"><?= $word('UI', 'brand') ?></span></a>
<?php if ($nav !== []): ?>
  <a class="appbar-me" href="#menu" aria-controls="menu" title="<?= $say('UI', 'signed_in_as', $who->displayName) ?>"><span aria-hidden="true"><?= $e($mark) ?></span><span class="visually-hidden"><?= $word('UI', 'open_menu') ?> (<?= $say('UI', 'signed_in_as', $who->displayName) ?>)</span></a>
<?php else: ?>
  <span class="appbar-me" title="<?= $say('UI', 'signed_in_as', $who->displayName) ?>"><span aria-hidden="true"><?= $e($mark) ?></span><span class="visually-hidden"><?= $say('UI', 'signed_in_as', $who->displayName) ?></span></span>
<?php endif; ?>
</header>
<div class="shell">
  <nav class="side" id="menu" aria-label="Main">
    <div class="side-head"><span class="side-title"><?= $word('UI', 'menu') ?></span><a class="btn sm" href="#main"><?= $word('UI', 'close_menu') ?></a></div>
    <div class="side-top">
      <a class="brand" href="/ui/"><span class="brand-name"><?= $word('UI', 'brand') ?></span></a>
    </div>
<?php foreach (['home', 'daily', 'more'] as $group): ?>
<?php if (in_array($group, array_column($nav, 'group'), true)): ?>
<?php if ($group !== 'home'): ?>
    <p class="side-label" id="nav-<?= $e($group) ?>"><?= $word('NAV', $group) ?></p>
<?php endif; ?>
    <ul class="nav"<?php if ($group !== 'home'): ?> aria-labelledby="nav-<?= $e($group) ?>"<?php endif; ?>>
<?php foreach ($nav as $item): ?>
<?php if ($item['group'] === $group): ?>
<?php $subs = $item['subs'] ?? []; ?>
      <li><a class="nav-item<?php if ($item['open'] ?? false): ?> open<?php endif; ?>" href="<?= $u($item['href'], $item['query']) ?>"<?php if ($item['current']): ?> aria-current="<?php if ($tree): ?>true<?php else: ?>page<?php endif; ?>"<?php endif; ?>><span class="nav-label"><?= $e($item['label']) ?></span><?php if ($item['count'] > 0): ?><span class="count" title="<?= $n($item['count']) ?> <?= $e($item['countWords']) ?>"><?= $n($item['count']) ?><span class="visually-hidden"> <?= $e($item['countWords']) ?></span></span><?php endif; ?></a><?php if ($subs === []): ?></li><?php endif; ?>

<?php if ($subs !== []): ?>
        <ul class="nav-sub" aria-label="<?= $e($item['label']) ?>">
<?php foreach ($subs as $sub): ?>
<?php $segCurrent = in_array(true, array_column($sub['segs'], 'current'), true); ?>
<?php if ($sub['soon']): ?>
          <li><span class="nav-sub-item more soon" aria-disabled="true" title="<?= $word('UI', 'soon_title') ?>"><span class="nav-label"><?= $e($sub['label']) ?></span><span class="count soon"><?= $word('UI', 'soon') ?></span></span></li>
<?php continue; ?>
<?php endif; ?>
          <li><a class="nav-sub-item<?php if ($sub['more']): ?> more<?php endif; ?>"<?php if (!$sub['here']): ?> href="<?= $u($sub['href'], $sub['query']) ?>"<?php endif; ?><?php if ($sub['current']): ?> aria-current="<?php if ($segCurrent): ?>true<?php else: ?>page<?php endif; ?>"<?php endif; ?>><span class="nav-label"><?= $e($sub['label']) ?></span><?php if ($sub['count'] > 0): ?><span class="count" title="<?= $n($sub['count']) ?> <?= $e($sub['countWords']) ?>"><?= $n($sub['count']) ?><span class="visually-hidden"> <?= $e($sub['countWords']) ?></span></span><?php endif; ?></a>
<?php if ($sub['segs'] !== []): ?>
            <ul class="nav-sub nav-seg" aria-label="<?= $e($sub['label']) ?>">
<?php foreach ($sub['segs'] as $seg): ?>
              <li><a class="nav-sub-item"<?php if (!$seg['here']): ?> href="<?= $u($seg['href'], $seg['query']) ?>"<?php endif; ?><?php if ($seg['current']): ?> aria-current="page"<?php endif; ?>><span class="nav-label"><?= $e($seg['label']) ?></span><?php if ($seg['count'] > 0): ?><span class="count" title="<?= $n($seg['count']) ?> <?= $e($seg['countWords']) ?>"><?= $n($seg['count']) ?><span class="visually-hidden"> <?= $e($seg['countWords']) ?></span></span><?php endif; ?></a></li>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
          </li>
<?php endforeach; ?>
        </ul>
      </li>
<?php endif; ?>
<?php endif; ?>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php endforeach; ?>
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
  <main id="main" class="work" tabindex="-1">
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
