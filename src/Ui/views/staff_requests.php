<?php
// Approvals › Staff requests as an inbox (the approved design of 29 Oct 2026, AP_Inbox; docs/decisions.md W12.9): the requests on the left
// grouped by what is asked (a change of jobs, a sign-in reset: a coloured dot, its name and count), each row the person and what is asked, a
// link `?open=<id>`; the open one on the right (first on a phone): what is asked and its place, the person and their e-mail, the status
// block, its facts, then ONE decision: OK the main button, "Not OK" under More with its note; "Next: …" beside them. The open row is the page
// shown: no link. A request the person may not decide (theirs, or about them) says so instead of the decision.
?>
<?= $head(\CW\Ui\Words::PAGE_TITLE['staff_requests'], intro: 'staff_requests') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($record === null): ?>
<?= $empty(\CW\Ui\Words::STAFF_REQUESTS['none']) ?>
<?php else: ?>
<div class="ap-inbox">
  <section class="card ap-list" aria-label="<?= $word('STAFF_REQUESTS', 'list') ?>">
<?php foreach ($groups as $g): ?>
    <div class="ap-group <?= $e($g['tone']) ?>">
      <h2 class="ap-gh"><span class="ap-dot" aria-hidden="true"></span><span class="ap-gname"><?= $e($g['name']) ?></span> <span class="ap-gcount"><?= $n(count($g['rows'])) ?></span></h2>
      <ul class="ap-rows">
<?php foreach ($g['rows'] as $r): ?>
<?php $sel = (int) $r['id'] === (int) $record['id']; ?>
        <li><a class="ap-row<?php if ($sel): ?> sel<?php endif; ?>"<?php if ($sel): ?> aria-current="true"<?php else: ?> href="<?= $e($r['href']) ?>"<?php endif; ?>><span class="ap-t"><?= $e($r['person']) ?></span><span class="ap-m"><?= $say('INBOX', 'meta_staff', \CW\Ui\Words::of('RESET_KIND', $r['kind']), \CW\Ui\Html::day($r['requested_at'])) ?></span></a></li>
<?php endforeach; ?>
      </ul>
    </div>
<?php endforeach; ?>
  </section>
  <section class="ap-record <?= $e($record['tone']) ?>" aria-labelledby="ap-h">
  <div class="card ap-card" id="request-<?= $e($record['id']) ?>">
    <div class="ap-rhead">
      <div class="ap-rtitle">
        <p class="ap-kind"><?= $e($record['groupName']) ?> · <?= $e($record['place']) ?></p>
        <h2 id="ap-h"><?= $e($record['person']) ?></h2>
<?php if (($record['email'] ?? '') !== ''): ?>
        <p class="ap-sub"><?= $e($record['email']) ?></p>
<?php endif; ?>
      </div>
      <?= $chip($record['tone'], \CW\Ui\Words::INBOX['waiting']) ?>
    </div>
<?php if ($record['error'] !== null): ?>
    <p class="error" role="alert"><?= $e($record['error']) ?></p>
<?php endif; ?>
    <dl class="doc-facts">
      <dt><?= $word('STAFF_REQUESTS', 'what') ?></dt><dd><?= $word('RESET_KIND', $record['kind']) ?></dd>
      <dt><?= $word('STAFF_REQUESTS', 'email') ?></dt><dd><?= $e($record['email'] ?? '') ?></dd>
      <dt><?= $word('STAFF_REQUESTS', 'made') ?></dt><dd><?php if ($record['person_created_at'] !== null): ?><?= $when($record['person_created_at']) ?><?php endif; ?></dd>
      <dt><?= $word('STAFF_REQUESTS', 'set_up') ?></dt><dd><?php if ($record['setup']['finished'] === null): ?><span class="muted"><?= $word('STAFF_REQUESTS', $record['setup']['state'] === 'signup' ? 'set_up_not_yet' : 'set_up_server') ?></span><?php else: ?><?= $say('STAFF_REQUESTS', 'set_up_at', \CW\Ui\Html::when($record['setup']['finished']['at']), (string) ($record['setup']['finished']['ip'] ?? '')) ?><?php endif; ?></dd>
      <dt><?= $word('STAFF_REQUESTS', 'now') ?></dt><dd><?= $jobs($record['before']) ?></dd>
<?php if ($record['kind'] === 'roles'): ?>
      <dt><?= $word('STAFF_REQUESTS', 'asked') ?></dt><dd><?= $jobs($record['after']) ?></dd>
<?php endif; ?>
      <dt><?= $word('STAFF_REQUESTS', 'by') ?></dt><dd><?= $e($record['requested_by_name'] ?? '') ?></dd>
      <dt><?= $word('STAFF_REQUESTS', 'on') ?></dt><dd><?= $when($record['requested_at']) ?></dd>
    </dl>
<?php if ($record['setup']['finished'] === null && $record['setup']['state'] === 'signup'): ?>
    <p class="ap-line"><?= $word('STAFF_REQUESTS', 'not_set_up_note') ?></p>
<?php endif; ?>
<?php if (!$record['can']): ?>
    <p class="note read-only"><?= $word('STAFF_REQUESTS', 'yours') ?></p>
<?php else: ?>
    <form class="ap-answer" id="ap-answer" method="post" action="<?= $u('/ui/staff-requests/' . $record['id'] . '/approve') ?>" data-leaves="1">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    </form>
    <div class="ap-actions">
      <button type="submit" form="ap-answer" class="btn primary"><?= $word('STAFF_REQUESTS', $record['kind'] === 'roles' ? 'ok' : 'ok_reset') ?></button>
      <details class="more"<?php if ($record['moreOpen']): ?> open<?php endif; ?>>
        <summary class="btn" aria-label="<?= $word('INBOX', 'more') ?>"><?= $word('UI', 'more') ?></summary>
        <div class="more-menu">
          <details class="doc-act"<?php if ($record['moreOpen']): ?> open<?php endif; ?>>
            <summary class="btn risky"><?= $word('STAFF_REQUESTS', $record['kind'] === 'roles' ? 'not_ok' : 'not_ok_reset') ?></summary>
            <div class="doc-act-panel">
              <form class="record" method="post" action="<?= $u('/ui/staff-requests/' . $record['id'] . '/reject') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1"<?php if ($record['moreOpen']): ?> data-dirty="1"<?php endif; ?>>
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <label><?= $word('STAFF_REQUESTS', 'note') ?>
                  <textarea name="note" rows="2" minlength="3" maxlength="500" required></textarea>
                </label>
                <p class="actions"><button class="btn risky" type="submit"><?= $word('STAFF_REQUESTS', $record['kind'] === 'roles' ? 'not_ok' : 'not_ok_reset') ?></button></p>
              </form>
            </div>
          </details>
        </div>
      </details>
      <p class="ap-next"><?php if ($record['next'] !== null): ?><?= $say('INBOX', 'next', $record['next']) ?><?php else: ?><?= $word('INBOX', 'last') ?><?php endif; ?></p>
    </div>
<?php endif; ?>
  </div>
  </section>
</div>
<?php endif; ?>
