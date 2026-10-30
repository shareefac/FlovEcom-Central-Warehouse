<?php
// Items › Multipacks › Pack items, a simple list (the approved redesign of 30 Oct 2026, the Items artboard's look; docs/decisions.md RD-A7;
// W11.6 before): the title, More (Clear) and the ONE main button "+ New pack item" (it opens the form, its fields in two columns; what a
// refused one brings back is guarded as typed, W11.13); one search (CW number or name) and Multipacks' ONE Show choice (in use, with the
// switched-off ones, Pack links); then the groups in use and switched off, five columns: the pack item (it opens it; how many singles it
// is under it), its single, the stock of both, what to check (soft tags, the variants that could sell singles), its actions (Repack it,
// switch off or on).
?>
<?= $head(\CW\Ui\Words::INV_LIST['packs_on'], intro: 'packs', look: $lookOnly, menu: $bar) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('PACKS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="100" placeholder="<?= $word('PACKS', 'search') ?>"></div>
<?php if ($off): ?>
  <input type="hidden" name="off" value="1">
<?php endif; ?>
  <?= $showMine($show) ?>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($formKey !== null): ?>
<details class="fold new-order drawer" id="new"<?php if ($error !== null || $total === 0): ?> open<?php endif; ?>>
  <summary><?= $word('PACKS', 'add') ?></summary>
  <form class="doc-details" method="post" action="/ui/items/packs" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1"<?php if (implode('', $typed) !== ''): ?> data-dirty="1"<?php endif; ?>>
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <fieldset class="doc-grid">
      <legend class="visually-hidden"><?= $word('PACKS', 'add') ?></legend>
      <div class="field">
        <label for="p-pack"><?= $word('PACKS', 'pack_label') ?></label>
        <input id="p-pack" type="text" name="pack" maxlength="64" required autocomplete="off" value="<?= $e($typed['pack']) ?>"<?php if ($errorField === 'pack'): ?> aria-invalid="true"<?php endif; ?>>
      </div>
      <div class="field">
        <label for="p-single"><?= $word('PACKS', 'single_label') ?></label>
        <input id="p-single" type="text" name="single" maxlength="64" required autocomplete="off" value="<?= $e($typed['single']) ?>"<?php if ($errorField === 'single'): ?> aria-invalid="true"<?php endif; ?>>
      </div>
      <div class="field">
        <label for="p-units"><?= $word('PACKS', 'units_label') ?></label>
        <input id="p-units" type="text" name="units" inputmode="numeric" maxlength="5" required value="<?= $e($typed['units']) ?>"<?php if ($errorField === 'units'): ?> aria-invalid="true"<?php endif; ?>>
      </div>
      <div class="field">
        <label for="p-note"><?= $word('PACKS', 'note') ?></label>
        <textarea id="p-note" name="note" rows="2" maxlength="500"><?= $e($typed['note']) ?></textarea>
      </div>
    </fieldset>
    <p class="actions"><button type="submit" class="btn primary"><?= $word('PACKS', 'add_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::PACKS['none_filter'], '', '/ui/items/packs', \CW\Ui\Words::PACKS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::PACKS['none'], \CW\Ui\Words::PACKS['none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?= $n(count($g['rows'])) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list packs">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('PACKS', 'pack_item') ?></th>
        <th scope="col"><?= $word('PACKS', 'single') ?></th>
        <th scope="col"><?= $word('PACKS', 'stock') ?></th>
        <th scope="col"><?= $word('PACKS', 'checks') ?></th>
<?php if ($canEdit || $canRepack): ?>
        <th scope="col" class="c-next"><span class="visually-hidden"><?= $word('PACKS', 'actions') ?></span></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/items/' . $r['pack']['id']) ?>"><?= $e($r['pack']['code']) ?> <?= $e($r['pack']['name']) ?></a><span class="o-sub"><?= $say('PACKS', 'equals', $r['units']) ?></span></th>
        <td class="c-next" data-label="<?= $word('PACKS', 'single') ?>"><a href="<?= $u('/ui/items/' . $r['single']['id']) ?>"><?= $e($r['single']['code']) ?></a> <?= $e($r['single']['name']) ?></td>
        <td data-label="<?= $word('PACKS', 'stock') ?>"><?= $e($r['stock']) ?></td>
        <td class="c-next c-wide" data-label="<?= $word('PACKS', 'checks') ?>">
<?php if ($r['flags'] === [] && $r['suggest'] === [] && !$r['ownProduct']): ?>
          <span class="muted"><?= $word('PACKS', 'nothing') ?></span>
<?php endif; ?>
<?php foreach ($r['flags'] as $fl): ?>
          <span class="tag <?= $e($fl['tone']) ?>"><?= $e($fl['text']) ?></span>
<?php endforeach; ?>
<?php if ($r['suggest'] !== [] && $canLink): ?>
          <p class="pack-suggest"><strong><?= $word('PACKS', 'suggest_title') ?></strong> <?= $say('PACKS', 'suggest_text', $r['units']) ?></p>
          <ul class="plain">
<?php foreach ($r['suggest'] as $s): ?>
            <li><a href="<?= $e($s['href']) ?>"><?= $e($s['title']) ?></a> <span class="muted"><?= $e($s['store']) ?></span></li>
<?php endforeach; ?>
          </ul>
<?php elseif ($r['ownProduct']): ?>
          <p class="hint"><?= $word('PACKS', 'own_product') ?></p>
<?php endif; ?>
<?php if ($r['note'] !== null): ?>
          <p class="muted"><?= $e($r['note']) ?></p>
<?php endif; ?>
        </td>
<?php if ($canEdit || $canRepack): ?>
        <td class="c-next">
<?php if ($r['active'] && $canRepack): ?>
          <a class="btn ghost sm" href="<?= $u('/ui/stock/repack', ['pack' => $r['id']]) ?>#new"><?= $word('PACKS', 'repack') ?></a>
<?php endif; ?>
<?php if ($canEdit): ?>
          <form class="inline" method="post" action="<?= $u('/ui/items/packs/' . $r['id'] . '/switch') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="on" value="<?php if ($r['active']): ?>0<?php else: ?>1<?php endif; ?>">
            <button type="submit" class="btn sm" title="<?= $word('PACKS', 'switch_does') ?>"><?php if ($r['active']): ?><?= $word('PACKS', 'switch_off') ?><?php else: ?><?= $word('PACKS', 'switch_on') ?><?php endif; ?></button>
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
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('PACKS', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('PACKS', 'prev') ?></a><?php endif; ?>
  <span><?= $say('PACKS', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('PACKS', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
