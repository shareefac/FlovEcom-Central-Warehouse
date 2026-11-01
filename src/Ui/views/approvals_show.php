<?php
// Approvals' Show (the approved Approvals artboard of 30 Oct 2026; docs/decisions.md RD-C13): everything waiting, or one kind of it, each with its
// count; then Answered today (no count: it is read only when chosen, docs/decisions.md SS3). A GET form of the page itself: its Show button sends it without the script. ONE Show with Approvals' other pages (the integration of
// 30 Oct 2026, View::showStart: the group's pages, then this choice). With ERPNext corrections shown and some ready (ConfirmAll.dc.html,
// docs/decisions.md CA1): the green "Confirm all ready (N)" after it, a plain link to the check page (it saves nothing); not the page's main
// button (the record's answer stays the one magenta button).
?>
<form class="ap-show" method="get" action="/ui/approvals" aria-label="<?= $word('RD_INBOX', 'show_label') ?>">
  <?= $showStart() ?>
  <div class="tb-state">
  <label for="ap-show"><?= $word('RD_INBOX', 'show') ?></label>
  <select id="ap-show" name="show">
    <option value=""><?= $say('RD_INBOX', 'everything', $everything) ?></option>
<?php foreach ($choices as $c): ?>
    <option value="<?= $e($c['value']) ?>"<?php if ($c['current']): ?> selected<?php endif; ?>><?= $e($c['label']) ?><?php if ($c['count'] !== null): ?> (<?= $n($c['count']) ?>)<?php endif; ?></option>
<?php endforeach; ?>
  </select>
  <button type="submit" class="btn"><?= $word('RD_INBOX', 'show') ?></button>
  </div>
  <?= $showEnd() ?>
</form>
<?php if (($confirmAll ?? null) !== null): ?>
<a class="btn ok ap-confirm-all" href="<?= $u($confirmAll['href']) ?>"><span class="ico ico-check" aria-hidden="true"></span><?= $say('CONFIRM_ALL', 'go', $confirmAll['ready']) ?></a>
<?php endif; ?>
