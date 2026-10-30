<?php
// To check › Waiting for me as an inbox (the approved design of 29 Oct 2026, AP_Inbox; docs/decisions.md W12.10): ONE Show row (the kind of
// work: the Filter pop-over before); the two lists on the left as its groups (needs your OK first, then already done: check it), each row
// what it is and when it is due ("Late" when it is), a link `?open=<task>`; the open one on the right (first on a phone): its list and
// place, its name, the status block, its facts, and ONE main action: "Open" its own page, where it is decided as before (what each answer
// does is said there). A task this person may not decide says why instead. The open row is the page shown: no link.
$filtered = $type !== null;
?>
<?= $head(\CW\Ui\Words::PAGE_TITLE['reviews'], intro: 'reviews') ?>
<form class="toolbar" method="get" action="/ui/documents/reviews" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-state">
    <label for="tb-show"><?= $word('CHECKS', 'kind') ?></label>
    <select id="tb-show" name="type">
      <option value=""><?= $word('CHECKS', 'everything') ?></option>
<?php foreach ($types as $code => $name): ?>
      <option value="<?= $e($code) ?>"<?php if ($type === $code): ?> selected<?php endif; ?>><?= $e($name) ?></option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="/ui/documents/reviews"><?= $word('CHECKS', 'show_all') ?></a>
<?php endif; ?>
  </div>
</form>
<?php if ($record === null && $filtered): ?>
<?= $empty(\CW\Ui\Words::CHECKS['none_kind'], \CW\Ui\Words::CHECKS['none_kind_text'], '/ui/documents/reviews', \CW\Ui\Words::CHECKS['show_all']) ?>
<?php elseif ($record === null): ?>
<?= $empty(\CW\Ui\Words::CHECKS['none'], \CW\Ui\Words::CHECKS['none_text']) ?>
<?php else: ?>
<div class="ap-inbox">
  <section class="card ap-list" aria-label="<?= $word('PAGE_TITLE', 'reviews') ?>">
<?php foreach ($groups as $g): ?>
    <div class="ap-group <?= $e($g['tone']) ?>" id="<?= $e($g['key'] === 'approval' ? 'approvals' : 'reviews') ?>">
      <h2 class="ap-gh"><span class="ap-dot" aria-hidden="true"></span><span class="ap-gname"><?= $e($g['name']) ?></span> <span class="ap-gcount"><?= $n(count($g['rows'])) ?></span></h2>
      <ul class="ap-rows">
<?php foreach ($g['rows'] as $r): ?>
<?php $sel = $r['task_id'] === $record['task_id']; ?>
        <li><a class="ap-row<?php if ($sel): ?> sel<?php endif; ?>"<?php if ($sel): ?> aria-current="true"<?php else: ?> href="<?= $e($r['open_href']) ?>"<?php endif; ?>><span class="ap-t"><?= $e($r['label']) ?></span><span class="ap-m"><?= $word('CHECK_REASON', $r['reason']) ?> · <?= $word('CHECKS', 'check_by') ?> <?= $day($r['due_at']) ?><?php if ($r['overdue']): ?> · <?= $word('CHECKS', 'late') ?><?php endif; ?></span></a></li>
<?php endforeach; ?>
      </ul>
    </div>
<?php endforeach; ?>
  </section>
  <section class="ap-record <?= $e($record['tone']) ?>" aria-labelledby="ap-h">
  <div class="card ap-card" id="task-<?= $e($record['task_id']) ?>">
    <div class="ap-rhead">
      <div class="ap-rtitle">
        <p class="ap-kind"><?= $word('CHECK_KIND', $record['kind']) ?> · <?= $e($record['place']) ?></p>
        <h2 id="ap-h"><a href="<?= $u($record['href']) ?>"><?= $e($record['label']) ?></a></h2>
      </div>
<?php if ($record['overdue']): ?>
      <?= $chip('blocked', \CW\Ui\Words::CHECKS['late']) ?>
<?php else: ?>
      <?= $chip($record['tone'], \CW\Ui\Words::INBOX['waiting']) ?>
<?php endif; ?>
    </div>
    <dl class="doc-facts">
      <dt><?= $word('CHECKS', 'why') ?></dt><dd><?= $word('CHECK_REASON', $record['reason']) ?></dd>
<?php if ($record['money'] !== null): ?>
      <dt><?= $word('CHECKS', 'value') ?></dt><dd><?= $money($record['money']) ?></dd>
<?php elseif ($record['items'] !== null): ?>
      <dt><?= $word('INBOX', 'f_items') ?></dt><dd><?= $n($record['items']) ?></dd>
<?php endif; ?>
      <dt><?= $word('CHECKS', 'asked_by') ?></dt><dd><?= $e($record['opened_by'] ?? '') ?></dd>
      <dt><?= $word('CHECKS', 'asked_on') ?></dt><dd><?= $when($record['opened_at']) ?></dd>
      <dt><?= $word('CHECKS', 'check_by') ?></dt><dd><?= $day($record['due_at']) ?></dd>
    </dl>
<?php if ($record['refusal'] !== null): ?>
    <p class="note read-only"><?= $e($record['refusal']) ?></p>
<?php endif; ?>
    <div class="ap-actions">
<?php if ($record['refusal'] === null): ?>
      <a class="btn primary" href="<?= $u($record['href']) ?>"><?= $word('CHECKS', 'open') ?></a>
<?php else: ?>
      <a class="btn" href="<?= $u($record['href']) ?>"><?= $word('CHECKS', 'open') ?></a>
<?php endif; ?>
      <p class="ap-next"><?php if ($record['next'] !== null): ?><?= $say('INBOX', 'next', $record['next']) ?><?php else: ?><?= $word('INBOX', 'last') ?><?php endif; ?></p>
    </div>
  </div>
  </section>
</div>
<?php endif; ?>
