<?php
// The bell (docs/decisions.md HV4): it opens Approvals › Waiting for you and its number is that page's (the badge approvals_inbox), so its
// name, its count and where it leads agree. Drawn for the people who answer there; the layout draws it in the phone's top bar and the sidebar.
?>
<a class="icon-btn bell" href="<?= $u($href, $query) ?>" title="<?= $word('UI', 'bell') ?>"><span class="ico ico-bell" aria-hidden="true"></span><span class="visually-hidden"><?= $word('UI', 'bell') ?></span><?php if ($count > 0): ?><span class="bell-n"><?= $n($count) ?><span class="visually-hidden"> <?= $e($countWords) ?></span></span><?php endif; ?></a>
