<h1><?= $word('PAGE_TITLE', 'series') ?></h1>
<?= $intro('series') ?>
<p class="muted"><?= $word('SETTINGS_PAGE', 'series_text') ?></p>
<div class="table-wrap">
<table class="stack series">
  <thead>
    <tr>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'kind') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'screens') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'last') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'next') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'checks') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'ok_first') ?></th>
      <th scope="col" class="num"><?= $word('SETTINGS_PAGE', 'days') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($series as $s): ?>
    <tr class="<?php if ($s['live']): ?>done<?php else: ?>off<?php endif; ?>">
      <th scope="row" class="c-head"><?= $e($s['kind']) ?></th>
      <td class="c-status"><?php if ($s['live']): ?><?= $chip('done', \CW\Ui\Words::SETTINGS_PAGE['live']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::SETTINGS_PAGE['later']) ?><?php endif; ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'last') ?>"><?php if ($s['last'] === null): ?><span class="muted"><?= $word('SETTINGS_PAGE', 'none_yet') ?></span><?php else: ?><code><?= $e($s['last']) ?></code><?php endif; ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'next') ?>"><code><?= $e($s['next']) ?></code></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'checks') ?>"><?= $e($s['review']) ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'ok_first') ?>"><?= $e($s['approval']) ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'days') ?>" class="num"><?= $n($s['due_days']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
