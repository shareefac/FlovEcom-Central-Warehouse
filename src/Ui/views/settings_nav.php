<?php
// Settings' own section list (the approved Settings artboard of 30 Oct 2026, one settings shell; docs/decisions.md RD-C14): at the left of every
// settings page, each section this person may open (ReferenceController::settingsSections: the permission its page's route checks), the one
// shown tinted and not a link (CV13). $current: the key of the section shown. It is the Settings group's ONE way between its pages, so the head
// draws no Show of its own on a settings page ($showNav, the integration of 30 Oct 2026).
$shown = \CW\Ui\Controller\ReferenceController::settingsSections($who);
?>
<?= $showNav() ?>
<nav class="set-nav" aria-label="<?= $word('RD_SETTINGS', 'sections') ?>">
<?php foreach ($shown as $s): ?>
<?php if ($s['key'] === $current): ?>
  <a class="set-nav-item" aria-current="page"><?= $word('RD_SETTINGS', $s['key']) ?></a>
<?php else: ?>
  <a class="set-nav-item" href="<?= $e($s['href']) ?>"><?= $word('RD_SETTINGS', $s['key']) ?></a>
<?php endif; ?>
<?php endforeach; ?>
</nav>
