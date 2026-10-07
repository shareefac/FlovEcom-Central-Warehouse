<h1><?= $word('MENU', 'documents') ?></h1>
<?= $intro('documents') ?>
<p class="muted"><?php if ($today === ''): ?><?= $word('RECORDS', 'none_live') ?><?php else: ?><?= $say('RECORDS', 'today', $today) ?><?php endif; ?>
<?php if ($later !== ''): ?> <?= $say('RECORDS', 'later', $later) ?><?php endif; ?>
<?php if ($poHidden): ?> <?= $word('RECORDS', 'po_hidden') ?><?php endif; ?></p>

<form class="filters" method="get" action="/ui/documents">
  <label><?= $word('RECORDS', 'kind') ?>
    <select name="type">
      <option value=""><?= $word('RECORDS', 'all_kinds') ?></option>
<?php foreach ($kinds as $t): ?>
      <option value="<?= $e($t['code']) ?>"<?php if ($filters['type'] === $t['code']): ?> selected<?php endif; ?>><?= $e($t['name']) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('RECORDS', 'status') ?>
    <select name="status">
      <option value=""><?= $word('RECORDS', 'any_status') ?></option>
<?php foreach ($statuses as $s): ?>
      <option value="<?= $e($s) ?>"<?php if ($filters['status'] === $s): ?> selected<?php endif; ?>><?= $word('DOC_STATUS', $s) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('RECORDS', 'check') ?>
    <select name="review">
      <option value=""><?= $word('RECORDS', 'any_check') ?></option>
<?php foreach ($reviewStates as $s): ?>
      <option value="<?= $e($s) ?>"<?php if ($filters['review'] === $s): ?> selected<?php endif; ?>><?= $word('CHECK_STATE', $s) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('RECORDS', 'search') ?>
    <input type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100">
  </label>
  <button type="submit" class="btn secondary"><?= $word('RECORDS', 'show') ?></button>
</form>

<?php $filtered = $filters['type'] !== null || $filters['status'] !== null || $filters['review'] !== null || $filters['q'] !== ''; ?>
<?php if ($rows === []): ?>
<?php if ($filtered): ?>
<?= $empty(\CW\Ui\Words::RECORDS['none_filter'], '', '/ui/documents', \CW\Ui\Words::RECORDS['clear']) ?>
<?php elseif ($poHidden): ?>
<?= $empty(\CW\Ui\Words::RECORDS['none_po'], \CW\Ui\Words::RECORDS['po_hidden']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::RECORDS['none'], \CW\Ui\Words::RECORDS['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<p class="muted"><?php if ($total === 1): ?><?= $word('RECORDS', 'total_one') ?><?php else: ?><?= $say('RECORDS', 'total_many', $total) ?><?php endif; ?></p>
<div class="table-wrap">
<table class="stack list documents">
  <thead>
    <tr>
      <th scope="col"><?= $word('RECORDS', 'number') ?></th>
      <th scope="col"><?= $word('RECORDS', 'status') ?></th>
      <th scope="col"><?= $word('RECORDS', 'what') ?></th>
      <th scope="col"><?= $word('RECORDS', 'check') ?></th>
      <th scope="col"><?= $word('RECORDS', 'date') ?></th>
      <th scope="col"><?= $word('RECORDS', 'ref') ?></th>
      <th scope="col"><?= $word('RECORDS', 'made_by') ?></th>
      <th scope="col"><?= $word('RECORDS', 'final_on') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?= $e(\CW\Ui\Words::tone('DOC_STATUS', $r['status'])) ?>">
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/documents/' . $r['id']) ?>"><?php if ($r['number'] === null): ?><?= $word('RECORDS', 'no_number') ?><?php else: ?><?= $e($r['number']) ?><?php endif; ?></a></th>
      <td class="c-status"><?= $stateChip('DOC_STATUS', $r['status']) ?></td>
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
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
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
