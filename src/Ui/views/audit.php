<h1><?= $word('MENU', 'audit') ?></h1>
<?= $intro('audit') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form class="filters" method="get" action="/ui/system/audit">
  <label><?= $word('AUDIT', 'from') ?>
    <input type="date" name="from" value="<?= $e($f['from']) ?>">
  </label>
  <label><?= $word('AUDIT', 'to') ?>
    <input type="date" name="to" value="<?= $e($f['to']) ?>">
  </label>
  <label><?= $word('AUDIT', 'who') ?>
    <select name="who">
<?php foreach ($whoOptions as $o): ?>
      <option value="<?= $e($o['value']) ?>"<?php if ($o['selected']): ?> selected<?php endif; ?>><?= $e($o['name']) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('AUDIT', 'action') ?>
    <select name="action">
      <option value=""><?= $word('AUDIT', 'anything') ?></option>
<?php foreach ($families as $code => $name): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['action'] === $code): ?> selected<?php endif; ?>><?= $e($name) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('AUDIT', 'record') ?>
    <select name="record">
      <option value=""><?= $word('AUDIT', 'any') ?></option>
<?php foreach ($records as $code => $name): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['record'] === $code): ?> selected<?php endif; ?>><?= $e($name) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('AUDIT', 'id') ?>
    <input type="text" name="id" value="<?= $e($f['id'] ?? '') ?>" maxlength="64">
  </label>
  <button type="submit" class="primary"><?= $word('AUDIT', 'search') ?></button>
</form>
<p class="actions"><a href="<?= $e($csv) ?>"><?= $word('AUDIT', 'download') ?></a><?php if ($filtered): ?> <a href="/ui/system/audit"><?= $word('AUDIT', 'clear') ?></a><?php endif; ?></p>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::AUDIT['none'], \CW\Ui\Words::AUDIT['none_text']) ?>
<?php else: ?>
<p class="muted"><?= $say('AUDIT', 'shown', count($rows)) ?></p>
<div class="table-wrap">
<table class="stack list audit">
  <thead>
    <tr>
      <th scope="col"><?= $word('AUDIT', 'when') ?></th>
      <th scope="col"><?= $word('AUDIT', 'who') ?></th>
      <th scope="col"><?= $word('AUDIT', 'action') ?></th>
      <th scope="col"><?= $word('AUDIT', 'record') ?></th>
      <th scope="col"><?= $word('AUDIT', 'details') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head"><?= $when($r['created_at']) ?></th>
      <td data-label="<?= $word('AUDIT', 'who') ?>"><?= $e($r['who']) ?></td>
      <td data-label="<?= $word('AUDIT', 'action') ?>"><?= $e($r['what']) ?> <small class="muted"><code><?= $e($r['action']) ?></code></small></td>
      <td data-label="<?= $word('AUDIT', 'record') ?>"><?= $e($r['record_words']) ?> <?= $e($r['entity_id'] ?? '') ?></td>
      <td data-label="<?= $word('AUDIT', 'details') ?>"><?php if ($r['detail'] !== null || $r['ip'] !== null): ?><details class="fold tech"><summary><?= $word('CONFIG', 'technical') ?></summary><?php if ($r['ip'] !== null): ?><p><?= $word('AUDIT', 'ip') ?>: <code><?= $e($r['ip']) ?></code></p><?php endif; ?><?php if ($r['detail'] !== null): ?><pre class="json"><?= $e($r['detail']) ?></pre><?php endif; ?></details><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($next !== null): ?>
<p><a class="btn" href="<?= $e($next) ?>"><?= $word('AUDIT', 'older') ?></a></p>
<?php endif; ?>
<?php endif; ?>
