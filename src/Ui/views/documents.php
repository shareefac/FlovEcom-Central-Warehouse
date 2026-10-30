<?php
// To check › All records as a board (the approved design of 29 Oct 2026, GI_List; docs/decisions.md W12.10): one search (the number or the
// reference) and ONE Show control (Approvals' pages, then the kind, the status, the check: the filter form before; the integration of
// 30 Oct 2026, View::showStart), Clear at the right; the line of which kinds are in
// use; then the page's records grouped by their status (newest first in each), each group its colour and count over a card with the coloured
// edge, the status the solid cell, a row opening the record.
$filtered = $filters['type'] !== null || $filters['status'] !== null || $filters['review'] !== null || $filters['q'] !== '';
?>
<?= $head(\CW\Ui\Words::MENU['documents'], intro: 'documents') ?>
<form class="toolbar" method="get" action="/ui/documents" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('RECORDS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('RECORDS', 'search') ?>"></div>
  <?= $showStart() ?>
  <div class="tb-state">
    <label for="tb-show"><?= $word('RECORDS', 'kind') ?>
    <select id="tb-show" name="type">
      <option value=""><?= $word('RECORDS', 'all_kinds') ?></option>
<?php foreach ($kinds as $t): ?>
      <option value="<?= $e($t['code']) ?>"<?php if ($filters['type'] === $t['code']): ?> selected<?php endif; ?>><?= $e($t['name']) ?></option>
<?php endforeach; ?>
    </select>
    </label>
    <label for="tb-status"><?= $word('RECORDS', 'status') ?>
    <select id="tb-status" name="status">
      <option value=""><?= $word('RECORDS', 'any_status') ?></option>
<?php foreach ($statuses as $s): ?>
      <option value="<?= $e($s) ?>"<?php if ($filters['status'] === $s): ?> selected<?php endif; ?>><?= $word('DOC_STATUS', $s) ?></option>
<?php endforeach; ?>
    </select>
    </label>
    <label for="tb-check"><?= $word('RECORDS', 'check') ?>
    <select id="tb-check" name="review">
      <option value=""><?= $word('RECORDS', 'any_check') ?></option>
<?php foreach ($reviewStates as $s): ?>
      <option value="<?= $e($s) ?>"<?php if ($filters['review'] === $s): ?> selected<?php endif; ?>><?= $word('CHECK_STATE', $s) ?></option>
<?php endforeach; ?>
    </select>
    </label>
    <button type="submit" class="btn sm"><?= $word('RECORDS', 'show') ?></button>
  </div>
  <?= $showEnd() ?>
  <div class="tb-end">
<?php if ($filtered): ?>
    <a class="btn ghost sm" href="/ui/documents"><?= $word('RECORDS', 'clear') ?></a>
<?php endif; ?>
  </div>
</form>
<p class="muted"><?php if ($today === ''): ?><?= $word('RECORDS', 'none_live') ?><?php else: ?><?= $say('RECORDS', 'today', $today) ?><?php endif; ?>
<?php if ($later !== ''): ?> <?= $say('RECORDS', 'later', $later) ?><?php endif; ?>
<?php if ($poHidden): ?> <?= $word('RECORDS', 'po_hidden') ?><?php endif; ?></p>

<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::RECORDS['none_filter'], '', '/ui/documents', \CW\Ui\Words::RECORDS['clear']) ?>
<?php elseif ($poHidden): ?>
<?= $empty(\CW\Ui\Words::RECORDS['none_po'], \CW\Ui\Words::RECORDS['po_hidden']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::RECORDS['none'], \CW\Ui\Words::RECORDS['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?= $n(count($g['rows'])) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list documents">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('RECORDS', 'number') ?></th>
        <th scope="col" class="c-status"><?= $word('RECORDS', 'status') ?></th>
        <th scope="col"><?= $word('RECORDS', 'what') ?></th>
        <th scope="col"><?= $word('RECORDS', 'check') ?></th>
        <th scope="col"><?= $word('RECORDS', 'date') ?></th>
        <th scope="col"><?= $word('RECORDS', 'ref') ?></th>
        <th scope="col"><?= $word('RECORDS', 'made_by') ?></th>
        <th scope="col"><?= $word('RECORDS', 'final_on') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/documents/' . $r['id']) ?>"><?php if ($r['number'] === null): ?><?= $word('RECORDS', 'no_number') ?><?php else: ?><?= $e($r['number']) ?><?php endif; ?></a></th>
        <td data-label="<?= $word('RECORDS', 'status') ?>" class="c-status doc-st <?= $e($g['tone']) ?>"><span class="chip"><?= $e($g['name']) ?></span></td>
        <td data-label="<?= $word('RECORDS', 'what') ?>"><?= $e($r['what']) ?></td>
        <td data-label="<?= $word('RECORDS', 'check') ?>"><?php if ($r['review_state'] !== null): ?><?= $word('CHECK_STATE', $r['review_state']) ?><?php endif; ?></td>
        <td data-label="<?= $word('RECORDS', 'date') ?>"><?= $day($r['doc_date']) ?></td>
        <td data-label="<?= $word('RECORDS', 'ref') ?>"><?= $e($r['external_ref']) ?></td>
        <td data-label="<?= $word('RECORDS', 'made_by') ?>"><?= $e($r['created_by_name']) ?></td>
        <td data-label="<?= $word('RECORDS', 'final_on') ?>"><?= $when($r['posted_at']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('RECORDS', 'page', $page, $pages) ?>">
<?php if ($page > 1): ?>
  <a href="<?= $u('/ui/documents', $filters + ['page' => $page - 1]) ?>"><?= $word('RECORDS', 'newer') ?></a>
<?php endif; ?>
  <span><?= $say('RECORDS', 'page', $page, $pages) ?></span>
<?php if ($page < $pages): ?>
  <a href="<?= $u('/ui/documents', $filters + ['page' => $page + 1]) ?>"><?= $word('RECORDS', 'older') ?></a>
<?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
