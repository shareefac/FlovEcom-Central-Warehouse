<?php
// Items › Item groups (families), a simple list (the approved redesign of 30 Oct 2026, the Items artboard's look; docs/decisions.md RD-A7;
// W11.4 before): the title, More (Clear) and the ONE main button "+ New family" (it opens the add form, its fields in two columns; what a
// refused one brings back is guarded as typed, W11.13); one search (the family's name) and ONE Show choice (in use, or in use and switched
// off); then the groups in use and switched off, three columns: the family (it opens it), its state, how many items it holds.
?>
<?= $head(\CW\Ui\Words::MENU['families'], intro: 'families', look: $lookOnly, side: $partial('rda_bar', $bar)) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('FAMILY', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="100" placeholder="<?= $word('FAMILY', 'search') ?>"></div>
<?php if ($off): ?>
  <input type="hidden" name="off" value="1">
<?php endif; ?>
  <?= $partial('rda_show', ['choices' => $show, 'label' => \CW\Ui\Words::INV_LIST['show']]) ?>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($formKey !== null): ?>
<details class="fold new-order drawer" id="new"<?php if ($error !== null || $total === 0): ?> open<?php endif; ?>>
  <summary><?= $word('FAMILY', 'add') ?></summary>
  <form class="doc-details" method="post" action="/ui/items/families" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1"<?php if ($typed['name'] . $typed['note'] !== ''): ?> data-dirty="1"<?php endif; ?>>
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <fieldset class="doc-grid">
      <legend class="visually-hidden"><?= $word('FAMILY', 'add') ?></legend>
      <div class="field">
        <label for="f-name"><?= $word('FAMILY', 'add_name') ?> <span class="hint"><?= $word('FAMILY', 'add_name_hint') ?></span></label>
        <input id="f-name" type="text" name="name" maxlength="255" required value="<?= $e($typed['name']) ?>"<?php if (in_array($errorCode, ['bad_name', 'family_exists'], true)): ?> aria-invalid="true"<?php endif; ?>>
      </div>
      <div class="field">
        <label for="f-note"><?= $word('FAMILY', 'note') ?></label>
        <textarea id="f-note" name="note" rows="2" maxlength="500"><?= $e($typed['note']) ?></textarea>
      </div>
    </fieldset>
    <p class="actions"><button type="submit" class="btn primary"><?= $word('FAMILY', 'add_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::FAMILY['none_filter'], \CW\Ui\Words::FAMILY['none_filter_text'], '/ui/items/families', \CW\Ui\Words::FAMILY['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::FAMILY['none'], \CW\Ui\Words::FAMILY['none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?= $n(count($g['rows'])) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list families">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('FAMILY', 'name') ?></th>
        <th scope="col" class="c-status"><?= $word('FAMILY', 'state') ?></th>
        <th scope="col" class="num"><?= $word('FAMILY', 'items') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['name']) ?></a></th>
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><span class="chip"><?= $e($g['name']) ?></span></td>
        <td class="num" data-label="<?= $word('FAMILY', 'items') ?>"><?= $n($r['items']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('FAMILY', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('FAMILY', 'prev') ?></a><?php endif; ?>
  <span><?= $say('FAMILY', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('FAMILY', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
