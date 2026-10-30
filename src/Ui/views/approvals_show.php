<?php
// Approvals' Show (the approved Approvals artboard of 30 Oct 2026; docs/decisions.md RD-C13): everything waiting, or one kind of it, each with its
// count. A GET form of the page itself: its Show button sends it without the script.
?>
<form class="ap-show" method="get" action="/ui/approvals" aria-label="<?= $word('RD_INBOX', 'show_label') ?>">
  <label for="ap-show"><?= $word('RD_INBOX', 'show') ?></label>
  <select id="ap-show" name="show">
    <option value=""><?= $say('RD_INBOX', 'everything', $everything) ?></option>
<?php foreach ($choices as $c): ?>
    <option value="<?= $e($c['value']) ?>"<?php if ($c['current']): ?> selected<?php endif; ?>><?= $e($c['label']) ?> (<?= $n($c['count']) ?>)</option>
<?php endforeach; ?>
  </select>
  <button type="submit" class="btn"><?= $word('RD_INBOX', 'show') ?></button>
</form>
