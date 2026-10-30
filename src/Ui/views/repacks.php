<?php
// Inventory › Repack, a simple list (the approved redesign of 30 Oct 2026, the Adjustments artboard's look; docs/decisions.md RD-A7; W8
// before): the title, More (Clear) and the ONE main button "+ New repack" (it opens the booking form, its fields in two columns); one
// search (the repack's number, a CW number) and ONE Show choice (the state); then the groups by status, four columns: the record (it opens
// it, Approvals › History; its date under it), what it does, where, and a draft's or a waiting one's own small forms (make it final, stop
// it, take it back, as before).
?>
<?= $head(\CW\Ui\Words::MENU['repack'], intro: 'repack', look: $lookOnly, side: $partial('rda_bar', $bar)) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('REPACK', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('REPACK', 'search') ?>"></div>
<?php if ($f['state'] !== null): ?>
  <input type="hidden" name="state" value="<?= $e($f['state']) ?>">
<?php endif; ?>
  <?= $partial('rda_show', ['choices' => $show, 'label' => \CW\Ui\Words::INV_LIST['show']]) ?>
</form>

<?php if ($done !== null): ?>
<p class="note done" role="status"><?= $e($done['text']) ?> <a href="<?= $u($done['href']) ?>"><?= $word('REPACK', 'open') ?></a></p>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($formKey !== null): ?>
<details class="fold new-order drawer" id="new"<?php if ($error !== null || $rows_n === 0 || $typed['pack'] !== ''): ?> open<?php endif; ?>>
  <summary><?= $word('REPACK', 'add') ?></summary>
<?php if ($packs === []): ?>
  <?= $empty(\CW\Ui\Words::REPACK['no_packs'], '', '/ui/items/packs', \CW\Ui\Words::REPACK['no_packs_link']) ?>
<?php else: ?>
  <form class="record" method="post" action="/ui/stock/repack" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <fieldset class="doc-grid">
      <legend class="visually-hidden"><?= $word('REPACK', 'add') ?></legend>
      <div class="field">
        <label for="r-wh"><?= $word('REPACK', 'warehouse') ?></label>
        <select id="r-wh" name="warehouse">
<?php foreach ($warehouses as $w): ?>
          <option value="<?= $e($w['id']) ?>"<?php if ($typed['warehouse'] === (string) $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="r-pack"><?= $word('REPACK', 'pack') ?></label>
        <select id="r-pack" name="pack" required>
<?php foreach ($packs as $p): ?>
          <option value="<?= $e($p['id']) ?>"<?php if ($typed['pack'] === (string) $p['id']): ?> selected<?php endif; ?>><?= $e($p['label']) ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <fieldset class="doc-wide">
        <legend><?= $word('REPACK', 'direction') ?></legend>
<?php foreach ($directions as $code => $label): ?>
        <label class="choice"><input type="radio" name="direction" value="<?= $e($code) ?>"<?php if ($typed['direction'] === $code): ?> checked<?php endif; ?>> <?= $e($label) ?></label>
<?php endforeach; ?>
      </fieldset>
      <div class="field">
        <label for="r-packs"><?= $word('REPACK', 'packs') ?></label>
        <input id="r-packs" type="text" name="packs" inputmode="numeric" maxlength="8" required value="<?= $e($typed['packs']) ?>"<?php if ($errorCode === 'bad_packs'): ?> aria-invalid="true"<?php endif; ?>>
      </div>
      <div class="field">
        <label for="r-note"><?= $word('REPACK', 'note') ?></label>
        <textarea id="r-note" name="note" rows="2" maxlength="1000"><?= $e($typed['note']) ?></textarea>
      </div>
    </fieldset>
    <p class="hint"><?= $word('REPACK', 'book_does') ?></p>
    <p class="actions"><button type="submit" class="btn primary"><?= $word('REPACK', 'book') ?></button></p>
  </form>
<?php endif; ?>
</details>
<?php endif; ?>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::REPACK['none_filter'], '', '/ui/stock/repack', \CW\Ui\Words::REPACK['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::REPACK['none'], \CW\Ui\Words::REPACK['none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g => $list): ?>
<?php $tone = \CW\Ui\Words::tone('DOC_STATUS', $g); ?>
<div class="grp-block doc-group <?= $e($tone) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $word('DOC_STATUS', $g) ?></button> <span class="grp-count"><?= $n(count($list)) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g) ?>">
  <table class="stack list board doc-list repacks">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('REPACK', 'record') ?></th>
        <th scope="col"><?= $word('REPACK', 'what') ?></th>
        <th scope="col"><?= $word('REPACK', 'where') ?></th>
<?php if ($canPost && in_array($g, ['draft', 'awaiting_approval'], true)): ?>
        <th scope="col" class="c-next"><span class="visually-hidden"><?= $word('REPACK', 'actions') ?></span></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['title']) ?></a><span class="o-sub"><?= $when($r['date']) ?></span></th>
        <td data-label="<?= $word('REPACK', 'what') ?>"><?= $e($r['way']) ?><span class="o-sub"><?= $e($r['what']) ?></span></td>
        <td data-label="<?= $word('REPACK', 'where') ?>"><?= $e($r['where']) ?><span class="o-sub"><?= $e($r['made_by']) ?></span></td>
<?php if ($canPost && in_array($g, ['draft', 'awaiting_approval'], true)): ?>
        <td class="c-next">
<?php if ($r['canFinal']): ?>
          <form class="inline" method="post" action="<?= $u('/ui/stock/repack/' . $r['id'] . '/post') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="version" value="<?= $e($r['version']) ?>">
            <button type="submit" class="btn sm"><?= $word('REPACK', 'final') ?></button>
          </form>
<?php endif; ?>
<?php if ($r['canStop']): ?>
          <form class="inline" method="post" action="<?= $u('/ui/stock/repack/' . $r['id'] . '/cancel') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="version" value="<?= $e($r['version']) ?>">
            <label class="visually-hidden" for="rs-<?= $e($r['id']) ?>"><?= $word('REPACK', 'stop_reason') ?></label>
            <input id="rs-<?= $e($r['id']) ?>" type="text" name="reason" minlength="3" maxlength="500" required placeholder="<?= $word('REPACK', 'stop_reason') ?>">
            <button type="submit" class="btn sm"><?= $word('REPACK', 'stop') ?></button>
          </form>
<?php endif; ?>
<?php if ($r['canTakeBack']): ?>
          <form class="inline" method="post" action="<?= $u('/ui/stock/repack/' . $r['id'] . '/withdraw') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <button type="submit" class="btn sm"><?= $word('REPACK', 'take_back') ?></button>
          </form>
<?php endif; ?>
        </td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($rows_n >= $limit): ?>
<p class="muted"><?= $say('REPACK', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
