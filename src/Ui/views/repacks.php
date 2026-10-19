<?= $head(\CW\Ui\Words::MENU['repack'], intro: 'repack', look: $lookOnly) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('REPACK', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('REPACK', 'search') ?>"></div>
  <label><span class="visually-hidden"><?= $word('REPACK', 'state') ?></span>
    <select name="state">
      <option value=""><?= $word('REPACK', 'any_state') ?></option>
<?php foreach ($states as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($f['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <button type="submit" class="btn ghost sm"><?= $word('UI', 'apply') ?></button>
</form>

<?php if ($done !== null): ?>
<p class="note done" role="status"><?= $e($done['text']) ?> <a href="<?= $u($done['href']) ?>"><?= $word('REPACK', 'open') ?></a></p>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($formKey !== null): ?>
<details class="fold new-repack drawer" id="new"<?php if ($error !== null || $rows_n === 0 || $typed['pack'] !== ''): ?> open<?php endif; ?>>
  <summary><?= $word('REPACK', 'add') ?></summary>
<?php if ($packs === []): ?>
  <?= $empty(\CW\Ui\Words::REPACK['no_packs'], '', '/ui/items/packs', \CW\Ui\Words::REPACK['no_packs_link']) ?>
<?php else: ?>
  <p class="hint"><?= $word('REPACK', 'add_text') ?></p>
  <form class="record" method="post" action="/ui/stock/repack">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <label for="r-wh"><?= $word('REPACK', 'warehouse') ?></label>
    <select id="r-wh" name="warehouse">
<?php foreach ($warehouses as $w): ?>
      <option value="<?= $e($w['id']) ?>"<?php if ($typed['warehouse'] === (string) $w['id']): ?> selected<?php endif; ?>><?= $e($w['name']) ?></option>
<?php endforeach; ?>
    </select>
    <label for="r-pack"><?= $word('REPACK', 'pack') ?></label>
    <select id="r-pack" name="pack" required>
<?php foreach ($packs as $p): ?>
      <option value="<?= $e($p['id']) ?>"<?php if ($typed['pack'] === (string) $p['id']): ?> selected<?php endif; ?>><?= $e($p['label']) ?></option>
<?php endforeach; ?>
    </select>
    <fieldset>
      <legend><?= $word('REPACK', 'direction') ?></legend>
<?php foreach ($directions as $code => $label): ?>
      <label class="choice"><input type="radio" name="direction" value="<?= $e($code) ?>"<?php if ($typed['direction'] === $code): ?> checked<?php endif; ?>> <?= $e($label) ?></label>
<?php endforeach; ?>
    </fieldset>
    <label for="r-packs"><?= $word('REPACK', 'packs') ?></label>
    <input id="r-packs" type="text" name="packs" inputmode="numeric" maxlength="8" required value="<?= $e($typed['packs']) ?>"<?php if ($errorCode === 'bad_packs'): ?> aria-invalid="true"<?php endif; ?>>
    <label for="r-note"><?= $word('REPACK', 'note') ?></label>
    <textarea id="r-note" name="note" rows="2" maxlength="1000"><?= $e($typed['note']) ?></textarea>
    <p class="hint"><?= $word('REPACK', 'book_does') ?></p>
    <p class="actions"><button type="submit" class="primary"><?= $word('REPACK', 'book') ?></button></p>
  </form>
<?php endif; ?>
</details>
<?php endif; ?>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::REPACK['none_filter'], '', '/ui/stock/repack', \CW\Ui\Words::REPACK['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::REPACK['none'], \CW\Ui\Words::REPACK['none_text']) ?>
<?php else: ?>
<p class="hint"><?= $word('REPACK', 'cancel_how') ?></p>
<div class="table-wrap">
<table class="stack list repacks">
  <thead>
    <tr>
      <th scope="col"><?= $word('REPACK', 'record') ?></th>
      <th scope="col"><?= $word('REPACK', 'what') ?></th>
      <th scope="col"><?= $word('REPACK', 'where') ?></th>
      <th scope="col"><?= $word('REPACK', 'made_by') ?></th>
      <th scope="col" class="c-status"><?= $word('REPACK', 'status') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['title']) ?></a><span class="o-sub"><?= $when($r['date']) ?></span></th>
      <td data-label="<?= $word('REPACK', 'what') ?>"><?= $e($r['way']) ?><span class="o-sub"><?= $e($r['what']) ?></span></td>
      <td data-label="<?= $word('REPACK', 'where') ?>"><?= $e($r['where']) ?></td>
      <td data-label="<?= $word('REPACK', 'made_by') ?>"><?= $e($r['made_by']) ?></td>
      <td class="c-status"><?= $stateChip('DOC_STATUS', $r['status']) ?>
<?php if ($r['canFinal']): ?>
        <form class="inline" method="post" action="<?= $u('/ui/stock/repack/' . $r['id'] . '/post') ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="version" value="<?= $e($r['version']) ?>">
          <button type="submit" class="primary sm"><?= $word('REPACK', 'final') ?></button>
        </form>
<?php endif; ?>
<?php if ($r['canStop']): ?>
        <form class="inline" method="post" action="<?= $u('/ui/stock/repack/' . $r['id'] . '/cancel') ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="version" value="<?= $e($r['version']) ?>">
          <label class="visually-hidden" for="rs-<?= $e($r['id']) ?>"><?= $word('REPACK', 'stop_reason') ?></label>
          <input id="rs-<?= $e($r['id']) ?>" type="text" name="reason" minlength="3" maxlength="500" required placeholder="<?= $word('REPACK', 'stop_reason') ?>">
          <button type="submit" class="secondary sm"><?= $word('REPACK', 'stop') ?></button>
        </form>
<?php endif; ?>
<?php if ($r['canTakeBack']): ?>
        <form class="inline" method="post" action="<?= $u('/ui/stock/repack/' . $r['id'] . '/withdraw') ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <button type="submit" class="secondary sm"><?= $word('REPACK', 'take_back') ?></button>
        </form>
<?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($rows_n >= $limit): ?>
<p class="muted"><?= $say('REPACK', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
