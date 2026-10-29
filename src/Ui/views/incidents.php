<?php
// Goods In › Incidents, a board (docs/decisions.md W9.9): the Show row (open, dealt with, nothing needed or all; the kind of problem); then
// the incidents grouped by their state, each group a coloured heading with its count over a card with its colour on the left edge, the
// status cell filled. A row: the problem, its state, the delivery line it came from, where the items are, who found it; an open one closes
// from its own fold (incidents.resolve).
?>
<?= $head(\CW\Ui\Words::MENU['incidents'], intro: 'incidents', look: $lookOnly, aside: $explain('where_units_go', \CW\Ui\Words::INCIDENTS['where_label'])) ?>
<form class="toolbar" method="get" action="/ui/receiving/incidents" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-state">
    <label for="tb-show"><?= $word('INCIDENTS', 'show') ?></label>
    <select id="tb-show" name="status">
<?php foreach ($statuses as $code): ?>
      <option value="<?= $e($code) ?>"<?php if ($status === $code): ?> selected<?php endif; ?>><?php if ($code === 'all'): ?><?= $word('INCIDENTS', 'all') ?><?php else: ?><?= $word('INCIDENT_STATE', $code) ?><?php endif; ?></option>
<?php endforeach; ?>
    </select>
    <label class="visually-hidden" for="tb-kind"><?= $word('INCIDENTS', 'kind') ?></label>
    <select id="tb-kind" name="kind">
      <option value=""><?= $word('INCIDENTS', 'any_kind') ?></option>
<?php foreach ($kinds as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($kind === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
    <button class="btn sm" type="submit"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($rows === [] && $status === 'open' && $kind === null): ?>
<?= $empty(\CW\Ui\Words::INCIDENTS['none_open'], \CW\Ui\Words::INCIDENTS['none_open_text']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::INCIDENTS['none'], \CW\Ui\Words::INCIDENTS['none_text'], '/ui/receiving/incidents', \CW\Ui\Words::INCIDENTS['show_open']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('INCIDENTS', 'group_one') ?><?php else: ?><?= $say('INCIDENTS', 'group_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list incidents">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('INCIDENTS', 'what') ?></th>
        <th scope="col" class="c-status"><?= $word('INCIDENTS', 'state') ?></th>
        <th scope="col"><?= $word('INCIDENTS', 'came_from') ?></th>
        <th scope="col"><?= $word('INCIDENTS', 'found') ?></th>
<?php if ($canResolve && $g['key'] === 'open'): ?>
        <th scope="col" class="c-next"><span class="visually-hidden"><?= $word('INCIDENTS', 'close') ?></span></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr id="incident-<?= $e($r['id']) ?>">
        <th scope="row" class="c-head c-item"><span class="o-name"><?= $e($r['title']) ?></span><span class="o-sub"><?= $e($r['where']) ?></span></th>
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><?= $word('INCIDENT_STATE', (string) $r['status']) ?></td>
        <td data-label="<?= $word('INCIDENTS', 'came_from') ?>"><a href="<?= $u('/ui/receiving/' . $r['document_id']) ?>"><?= $e($r['from']) ?></a></td>
        <td data-label="<?= $word('INCIDENTS', 'found') ?>"><?= $e($r['opened']) ?><?php if ($r['closed'] !== null): ?><span class="o-sub"><?= $e($r['closed']) ?></span><?php endif; ?></td>
<?php if ($canResolve && $g['key'] === 'open'): ?>
        <td class="c-next">
          <details class="fold">
            <summary><?= $word('INCIDENTS', 'close') ?></summary>
            <form class="record close-incident" method="post" action="<?= $u('/ui/receiving/incidents/' . $r['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <label><?= $word('INCIDENTS', 'close_how') ?>
                <select name="status">
                  <option value="resolved"><?= $word('INCIDENTS', 'resolved') ?></option>
                  <option value="dismissed"><?= $word('INCIDENTS', 'dismissed') ?></option>
                </select>
              </label>
              <label><?= $word('INCIDENTS', 'close_note') ?> <input type="text" name="note" minlength="3" maxlength="500" required></label>
              <p class="actions"><button class="btn" type="submit"><?= $word('INCIDENTS', 'close_button') ?></button></p>
            </form>
          </details>
        </td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
