<?php
// Matching › Spot checks as an inbox (the approved design of 29 Oct 2026, AP_Inbox; docs/decisions.md W12.11): ONE Show row (the store: the
// store tabs before); the spot checks on the left grouped by their result (in progress, failed, cannot be used, all right: a coloured dot,
// its name and count), each row its name and how many are checked, a link `?open=<id>`; the open one on the right (first on a phone): its
// result and place, its name (a link to its page), the status block, its 20 blocks, its facts, and its one action: for its owner while it
// is in progress "Check the next one", else "Open" its page. The open row is the page shown: no link.
?>
<?= $head(\CW\Ui\Words::PAGE_TITLE['samples'], intro: 'samples') ?>
<form class="toolbar" method="get" action="/ui/review/samples" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-state">
    <label for="tb-show"><?= $word('QUEUE', 'show') ?></label>
    <select id="tb-show" name="channel" aria-label="<?= $word('BULK', 'stores') ?>">
<?php foreach ($stores as $it): ?>
      <option value="<?= $e($it['query']['channel'] ?? '') ?>"<?php if ($it['current']): ?> selected<?php endif; ?>><?= $e($it['label']) ?> (<?= $n($it['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
</form>

<?php if ($record === null): ?>
<?= $empty(\CW\Ui\Words::SAMPLE['none'], \CW\Ui\Words::SAMPLE['none_text']) ?>
<?php else: ?>
<div class="ap-inbox">
  <section class="card ap-list" aria-label="<?= $word('PAGE_TITLE', 'samples') ?>">
<?php foreach ($groups as $g): ?>
    <div class="ap-group <?= $e($g['tone']) ?>">
      <h2 class="ap-gh"><span class="ap-dot" aria-hidden="true"></span><span class="ap-gname"><?= $e($g['name']) ?></span> <span class="ap-gcount"><?= $n(count($g['rows'])) ?></span></h2>
      <ul class="ap-rows">
<?php foreach ($g['rows'] as $r): ?>
<?php $sel = (int) $r['id'] === (int) $record['id']; ?>
        <li><a class="ap-row<?php if ($sel): ?> sel<?php endif; ?>"<?php if ($sel): ?> aria-current="true"<?php else: ?> href="<?= $e($r['href']) ?>"<?php endif; ?>><span class="ap-t"><?= $say('SAMPLE', 'title', $r['name']) ?></span><span class="ap-m"><?= $say('SPOT', 'progress', $r['decided'], $r['size']) ?> · <?= $e($r['created_by'] ?? '') ?> <?= $day($r['created_at']) ?></span></a></li>
<?php endforeach; ?>
      </ul>
    </div>
<?php endforeach; ?>
  </section>
  <section class="ap-record <?= $e($record['tone']) ?>" aria-labelledby="ap-h">
  <div class="card ap-card" id="sample-<?= $e($record['id']) ?>">
    <div class="ap-rhead">
      <div class="ap-rtitle">
        <p class="ap-kind"><?= $word('SAMPLE', 'name') ?> · <?= $e($record['place']) ?></p>
        <h2 id="ap-h"><a href="/ui/review/samples/<?= $e($record['id']) ?>"><?= $say('SAMPLE', 'title', $record['name']) ?></a></h2>
      </div>
      <?= $stateChip('SAMPLE_RESULT', $record['result']) ?>
    </div>
    <ol class="segments" aria-label="<?= $say('SPOT', 'progress', $record['decided'], $record['size']) ?>">
<?php foreach ($record['blocks'] as $b): ?>
      <li class="<?= $e($b['state']) ?>" title="<?= $e($b['title']) ?>"></li>
<?php endforeach; ?>
    </ol>
    <dl class="ap-facts">
      <div class="ap-fact"><dt><?= $word('SAMPLE', 'checked') ?></dt><dd><?= $say('SAMPLE', 'of', $record['decided'], $record['size']) ?></dd></div>
      <div class="ap-fact"><dt><?= $word('SAMPLE', 'picked_from') ?></dt><dd><?= $n($record['population']) ?></dd></div>
      <div class="ap-fact"><dt><?= $word('SAMPLE', 'together') ?></dt><dd><?= $n($record['bulk_linked']) ?><?php if ($record['bulk_undone'] > 0): ?> <?= $say('SAMPLE', 'undone', $record['bulk_undone']) ?><?php endif; ?></dd></div>
    </dl>
    <p class="ap-line"><?= $word('SAMPLE', 'started_by') ?>: <?= $e($record['created_by'] ?? '') ?>, <?= $day($record['created_at']) ?></p>
    <div class="ap-actions">
<?php if ($record['next'] !== null): ?>
      <a class="btn primary" href="<?= $e($record['next']['link']) ?>"><?= $say('SPOT', 'next', $record['next']['position'], $record['size']) ?></a>
<?php else: ?>
      <a class="btn primary" href="/ui/review/samples/<?= $e($record['id']) ?>"><?= $word('CHECKS', 'open') ?></a>
<?php endif; ?>
      <p class="ap-next"><?php if ($record['after'] !== null): ?><?= $say('INBOX', 'next', \CW\Ui\Words::say('SAMPLE', 'title', $record['after'])) ?><?php else: ?><?= $word('INBOX', 'last') ?><?php endif; ?></p>
    </div>
  </div>
  </section>
</div>
<?php endif; ?>
