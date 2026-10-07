<div class="head-help">
  <h1><?= $word('MENU', 'incidents') ?></h1>
  <?= $explain('where_units_go', \CW\Ui\Words::INCIDENTS['where_label']) ?>
</div>
<?= $intro('incidents', $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form class="filters" method="get" action="/ui/receiving/incidents">
  <label><?= $word('INCIDENTS', 'show') ?>
    <select name="status">
<?php foreach ($statuses as $code): ?>
      <option value="<?= $e($code) ?>"<?php if ($status === $code): ?> selected<?php endif; ?>><?php if ($code === 'all'): ?><?= $word('INCIDENTS', 'all') ?><?php else: ?><?= $word('INCIDENT_STATE', $code) ?><?php endif; ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('INCIDENTS', 'kind') ?>
    <select name="kind">
      <option value=""><?= $word('INCIDENTS', 'any_kind') ?></option>
<?php foreach ($kinds as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($kind === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <button type="submit"><?= $word('INCIDENTS', 'filter') ?></button>
</form>
<?php if ($rows === [] && $status === 'open' && $kind === null): ?>
<?= $empty(\CW\Ui\Words::INCIDENTS['none_open'], \CW\Ui\Words::INCIDENTS['none_open_text']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::INCIDENTS['none'], \CW\Ui\Words::INCIDENTS['none_text'], '/ui/receiving/incidents', \CW\Ui\Words::INCIDENTS['show_open']) ?>
<?php else: ?>
<p class="muted"><?php if (count($rows) === 1): ?><?= $word('INCIDENTS', 'total_one') ?><?php else: ?><?= $say('INCIDENTS', 'total_many', count($rows)) ?><?php endif; ?></p>
<ol class="incident-list">
<?php foreach ($rows as $r): ?>
  <li>
  <article class="incident card <?= $e(\CW\Ui\Words::tone('INCIDENT_STATE', $r['status'])) ?>" id="incident-<?= $e($r['id']) ?>">
    <div class="task-head">
      <h2><?= $e($r['title']) ?></h2>
      <?= $stateChip('INCIDENT_STATE', $r['status']) ?>
    </div>
    <p><a href="<?= $u('/ui/receiving/' . $r['document_id']) ?>"><?= $e($r['from']) ?></a></p>
    <p><strong><?= $e($r['where']) ?></strong></p>
    <p class="hint"><?= $e($r['opened']) ?></p>
<?php if ($r['closed'] !== null): ?>
    <p class="hint"><?= $e($r['closed']) ?></p>
<?php elseif ($canResolve): ?>
    <form class="record close-incident" method="post" action="<?= $u('/ui/receiving/incidents/' . $r['id']) ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <fieldset>
        <legend><?= $word('INCIDENTS', 'close') ?></legend>
        <label><?= $word('INCIDENTS', 'close_how') ?>
          <select name="status">
            <option value="resolved"><?= $word('INCIDENTS', 'resolved') ?></option>
            <option value="dismissed"><?= $word('INCIDENTS', 'dismissed') ?></option>
          </select>
        </label>
        <label><?= $word('INCIDENTS', 'close_note') ?> <input type="text" name="note" minlength="3" maxlength="500" required></label>
        <p class="actions"><button type="submit"><?= $word('INCIDENTS', 'close_button') ?></button></p>
        <p class="hint"><?= $word('INCIDENTS', 'close_hint') ?></p>
      </fieldset>
    </form>
<?php endif; ?>
  </article>
  </li>
<?php endforeach; ?>
</ol>
<?php endif; ?>
