<?= $head(\CW\Ui\Words::PAGE_TITLE['packs'], intro: 'packs', look: $lookOnly) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('PACKS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="100" placeholder="<?= $word('PACKS', 'search') ?>"></div>
  <label class="choice"><input type="checkbox" name="off" value="1"<?php if ($off): ?> checked<?php endif; ?>> <?= $word('PACKS', 'show_off') ?></label>
  <button type="submit" class="btn ghost sm"><?= $word('UI', 'apply') ?></button>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<details class="fold how">
  <summary><?= $word('PACKS', 'how') ?></summary>
  <ul>
    <li><?= $word('PACKS', 'how_link') ?></li>
    <li><?= $word('PACKS', 'how_item') ?></li>
  </ul>
  <p class="hint"><?php if ($rule): ?><?= $word('PACKS', 'rule_on') ?><?php else: ?><?= $word('PACKS', 'rule_off') ?><?php endif; ?></p>
</details>

<?php if ($formKey !== null): ?>
<details class="fold new-pack drawer" id="new"<?php if ($error !== null || $total === 0): ?> open<?php endif; ?>>
  <summary><?= $word('PACKS', 'add') ?></summary>
  <p class="hint"><?= $word('PACKS', 'add_text') ?></p>
  <form class="record" method="post" action="/ui/items/packs">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <label for="p-pack"><?= $word('PACKS', 'pack_label') ?></label>
    <input id="p-pack" type="text" name="pack" maxlength="64" required autocomplete="off" value="<?= $e($typed['pack']) ?>"<?php if ($errorField === 'pack'): ?> aria-invalid="true"<?php endif; ?>>
    <label for="p-single"><?= $word('PACKS', 'single_label') ?></label>
    <input id="p-single" type="text" name="single" maxlength="64" required autocomplete="off" value="<?= $e($typed['single']) ?>"<?php if ($errorField === 'single'): ?> aria-invalid="true"<?php endif; ?>>
    <label for="p-units"><?= $word('PACKS', 'units_label') ?></label>
    <input id="p-units" type="text" name="units" inputmode="numeric" maxlength="5" required value="<?= $e($typed['units']) ?>"<?php if ($errorField === 'units'): ?> aria-invalid="true"<?php endif; ?>>
    <label for="p-note"><?= $word('PACKS', 'note') ?></label>
    <textarea id="p-note" name="note" rows="2" maxlength="500"><?= $e($typed['note']) ?></textarea>
    <p class="actions"><button type="submit" class="primary"><?= $word('PACKS', 'add_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::PACKS['none_filter'], '', '/ui/items/packs', \CW\Ui\Words::PACKS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::PACKS['none'], \CW\Ui\Words::PACKS['none_text']) ?>
<?php else: ?>
<p class="muted"><?php if ($total === 1): ?><?= $word('PACKS', 'count_one') ?><?php else: ?><?= $say('PACKS', 'count_many', $total) ?><?php endif; ?></p>
<div class="table-wrap">
<table class="stack list packs">
  <thead>
    <tr>
      <th scope="col"><?= $word('PACKS', 'pack_item') ?></th>
      <th scope="col"><?= $word('PACKS', 'single') ?></th>
      <th scope="col"><?= $word('PACKS', 'stock') ?></th>
      <th scope="col"><?= $word('PACKS', 'checks') ?></th>
      <th scope="col" class="c-status"><?= $word('PACKS', 'state') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr<?php if (!$r['active']): ?> class="inactive off"<?php endif; ?>>
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/items/' . $r['pack']['id']) ?>"><?= $e($r['pack']['code']) ?></a> <?= $e($r['pack']['name']) ?><span class="o-sub"><?= $say('PACKS', 'equals', $r['units']) ?></span></th>
      <td data-label="<?= $word('PACKS', 'single') ?>"><a href="<?= $u('/ui/items/' . $r['single']['id']) ?>"><?= $e($r['single']['code']) ?></a> <?= $e($r['single']['name']) ?></td>
      <td data-label="<?= $word('PACKS', 'stock') ?>"><?= $e($r['stock']) ?></td>
      <td class="c-wide" data-label="<?= $word('PACKS', 'checks') ?>">
<?php if ($r['flags'] === [] && $r['suggest'] === [] && !$r['ownProduct']): ?>
        <span class="muted"><?= $word('PACKS', 'nothing') ?></span>
<?php endif; ?>
<?php foreach ($r['flags'] as $fl): ?>
        <span class="pack-flag"><?= $chip($fl['tone'], $fl['text']) ?></span>
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
      <td class="c-status"><?php if ($r['active']): ?><?= $chip('done', \CW\Ui\Words::PACKS['on']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::PACKS['off']) ?><?php endif; ?>
<?php if ($r['active'] && $canRepack): ?>
        <a class="btn ghost sm" href="<?= $u('/ui/stock/repack', ['pack' => $r['id']]) ?>#new"><?= $word('PACKS', 'repack') ?></a>
<?php endif; ?>
<?php if ($canEdit): ?>
        <form class="inline" method="post" action="<?= $u('/ui/items/packs/' . $r['id'] . '/switch') ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="on" value="<?php if ($r['active']): ?>0<?php else: ?>1<?php endif; ?>">
          <button type="submit" class="secondary sm" title="<?= $word('PACKS', 'switch_does') ?>"><?php if ($r['active']): ?><?= $word('PACKS', 'switch_off') ?><?php else: ?><?= $word('PACKS', 'switch_on') ?><?php endif; ?></button>
        </form>
<?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('PACKS', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('PACKS', 'prev') ?></a><?php endif; ?>
  <span><?= $say('PACKS', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('PACKS', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
