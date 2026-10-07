<p class="crumbs"><a href="/ui/reference/settings"><?= $word('MENU', 'settings') ?></a></p>
<h1><?= $word('PAGE_TITLE', 'reasons') ?></h1>
<?= $intro('reasons') ?>
<p class="muted"><?= $word('SETTINGS_PAGE', 'reasons_text') ?></p>
<p><a href="/ui/reference/reasons.csv"><?= $word('SETTINGS_PAGE', 'download_reasons') ?></a></p>
<div class="table-wrap">
<table class="stack reasons">
  <thead>
    <tr>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'reason') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'code') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'used_for') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'direction') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'needs_note') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'gift') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'by_cw') ?></th>
      <th scope="col"><?= $word('SETTINGS_PAGE', 'in_use') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($reasons as $r): ?>
    <tr<?php if ($r['is_active'] !== 'yes'): ?> class="inactive"<?php endif; ?>>
      <th scope="row" class="c-head"><?= $e($r['label']) ?></th>
      <td data-label="<?= $word('SETTINGS_PAGE', 'code') ?>"><code><?= $e($r['code']) ?></code></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'used_for') ?>"><?= $e($r['used_for']) ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'direction') ?>"><?= $e($r['way']) ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'needs_note') ?>"><?= $word('SETTINGS_PAGE', $r['needs_note']) ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'gift') ?>"><?= $word('SETTINGS_PAGE', $r['is_gift']) ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'by_cw') ?>"><?= $word('SETTINGS_PAGE', $r['system_only']) ?></td>
      <td data-label="<?= $word('SETTINGS_PAGE', 'in_use') ?>"><?= $word('SETTINGS_PAGE', $r['is_active']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
