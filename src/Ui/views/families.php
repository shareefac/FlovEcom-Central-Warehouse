<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('FAMILY', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="100" placeholder="<?= $word('FAMILY', 'search') ?>"></div>
  <label class="choice"><input type="checkbox" name="off" value="1"<?php if ($off): ?> checked<?php endif; ?>> <?= $word('FAMILY', 'show_off') ?></label>
  <button type="submit" class="btn ghost sm"><?= $word('UI', 'apply') ?></button>
</form>

<h1><?= $word('MENU', 'families') ?></h1>
<?= $intro('families', $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($formKey !== null): ?>
<details class="fold new-family" id="new"<?php if ($error !== null || $total === 0): ?> open<?php endif; ?>>
  <summary><?= $word('FAMILY', 'add') ?></summary>
  <form class="record" method="post" action="/ui/items/families">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <label for="f-name"><?= $word('FAMILY', 'add_name') ?> <span class="hint"><?= $word('FAMILY', 'add_name_hint') ?></span></label>
    <input id="f-name" type="text" name="name" maxlength="255" required value="<?= $e($typed['name']) ?>"<?php if (in_array($errorCode, ['bad_name', 'family_exists'], true)): ?> aria-invalid="true"<?php endif; ?>>
    <label for="f-note"><?= $word('FAMILY', 'note') ?></label>
    <textarea id="f-note" name="note" rows="2" maxlength="500"><?= $e($typed['note']) ?></textarea>
    <p class="actions"><button type="submit" class="primary"><?= $word('FAMILY', 'add_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::FAMILY['none_filter'], \CW\Ui\Words::FAMILY['none_filter_text'], '/ui/items/families', \CW\Ui\Words::FAMILY['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::FAMILY['none'], \CW\Ui\Words::FAMILY['none_text']) ?>
<?php else: ?>
<p class="muted"><?php if ($total === 1): ?><?= $word('FAMILY', 'count_one') ?><?php else: ?><?= $say('FAMILY', 'count_many', $total) ?><?php endif; ?></p>
<div class="table-wrap">
<table class="stack list families">
  <thead>
    <tr>
      <th scope="col"><?= $word('FAMILY', 'name') ?></th>
      <th scope="col" class="num"><?= $word('FAMILY', 'items') ?></th>
      <th scope="col" class="c-status"><?= $word('FAMILY', 'state') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr<?php if (!$r['active']): ?> class="inactive off"<?php endif; ?>>
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['name']) ?></a></th>
      <td class="num" data-label="<?= $word('FAMILY', 'items') ?>"><?= $n($r['items']) ?></td>
      <td class="c-status"><?php if ($r['active']): ?><?= $chip('done', \CW\Ui\Words::FAMILY['on']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::FAMILY['off']) ?><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('FAMILY', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('FAMILY', 'prev') ?></a><?php endif; ?>
  <span><?= $say('FAMILY', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('FAMILY', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
