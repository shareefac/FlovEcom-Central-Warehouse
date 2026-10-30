<?php
// Reports › Audit log, a report (the approved TP_Report of 29 Oct 2026, pattern 5; docs/decisions.md W13.8): read only, append-only. The
// heading with the search's file (Download (Excel)) at its right; one search (a record number) and the Show row (from, to, who, what was done,
// the kind of record) with its Show button, Clear under the toolbar's More; then the entries as one board with its blue edge, newest first,
// each with its technical details folded; "Older entries" under it.
$bar = $partial('report_bar', ['href' => $csv]);
?>
<div class="doc-head">
<?= $head(\CW\Ui\Words::MENU['audit'], intro: 'audit', side: $bar) ?>
</div>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form class="toolbar" method="get" action="/ui/system/audit" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('AUDIT', 'id') ?></label><input id="tb-q" type="search" name="id" value="<?= $e($f['id'] ?? '') ?>" maxlength="64" placeholder="<?= $word('AUDIT', 'find') ?>"></div>
  <div class="tb-state">
    <label class="tb-day"><?= $word('AUDIT', 'from') ?> <input type="date" name="from" value="<?= $e($f['from']) ?>"></label>
    <label class="tb-day"><?= $word('AUDIT', 'to') ?> <input type="date" name="to" value="<?= $e($f['to']) ?>"></label>
    <label for="tb-who"><?= $word('AUDIT', 'who') ?></label>
    <select id="tb-who" name="who">
<?php foreach ($whoOptions as $o): ?>
      <option value="<?= $e($o['value']) ?>"<?php if ($o['selected']): ?> selected<?php endif; ?>><?= $e($o['name']) ?></option>
<?php endforeach; ?>
    </select>
    <label for="tb-action"><?= $word('AUDIT', 'action') ?></label>
    <select id="tb-action" name="action">
      <option value=""><?= $word('AUDIT', 'anything') ?></option>
<?php foreach ($families as $code => $name): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['action'] === $code): ?> selected<?php endif; ?>><?= $e($name) ?></option>
<?php endforeach; ?>
    </select>
    <label for="tb-record"><?= $word('AUDIT', 'record') ?></label>
    <select id="tb-record" name="record">
      <option value=""><?= $word('AUDIT', 'any') ?></option>
<?php foreach ($records as $code => $name): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['record'] === $code): ?> selected<?php endif; ?>><?= $e($name) ?></option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
<?php if ($filtered): ?>
  <div class="tb-end">
    <details class="more tb-more">
      <summary class="btn ghost sm"><?= $word('UI', 'more') ?></summary>
      <div class="more-menu">
        <a class="menu-item" href="/ui/system/audit"><?= $word('AUDIT', 'clear') ?></a>
      </div>
    </details>
  </div>
<?php endif; ?>
</form>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::AUDIT['none'], \CW\Ui\Words::AUDIT['none_text']) ?>
<?php else: ?>
<div class="table-wrap doc-board info">
<table class="stack list board doc-list audit">
  <caption class="visually-hidden"><?= $say('AUDIT', 'shown', count($rows)) ?></caption>
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
<p class="pager"><a href="<?= $e($next) ?>"><?= $word('AUDIT', 'older') ?></a></p>
<?php endif; ?>
<?php endif; ?>
