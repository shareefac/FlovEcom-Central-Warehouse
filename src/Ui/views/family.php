<p class="crumbs"><a href="/ui/items/families"><?= $word('FAMILY', 'back') ?></a></p>
<?= $head((string) $f['name'], intro: 'family', look: $lookOnly) ?>
<p class="muted"><?= $e($f['made']) ?><?php if ($f['changed'] !== null): ?> <?= $e($f['changed']) ?><?php endif; ?></p>
<?php if ($f['note'] !== null): ?>
<p class="note"><?= $e($f['note']) ?></p>
<?php endif; ?>
<?php if (!$f['active']): ?>
<p class="note"><?= $chip('off', \CW\Ui\Words::FAMILY['off']) ?> <?= $word('FAMILY', 'is_off') ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($result !== null): ?>
<section class="card bulk-result" aria-labelledby="result-h">
  <h2 id="result-h" class="visually-hidden"><?= $word('FAMILY', 'bulk') ?></h2>
<?php if ($result['added'] !== []): ?>
  <p><strong><?= $say('FAMILY', 'result_added', count($result['added'])) ?></strong></p>
  <ul class="plain result-added">
<?php foreach ($result['added'] as $a): ?>
    <li><a href="<?= $u('/ui/items/' . $a['sku_id']) ?>"><?= $e($a['code']) ?></a> <?= $e($a['name']) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($result['removed'] !== []): ?>
  <p><strong><?= $say('FAMILY', 'result_removed', count($result['removed'])) ?></strong></p>
  <ul class="plain result-removed">
<?php foreach ($result['removed'] as $a): ?>
    <li><a href="<?= $u('/ui/items/' . $a['sku_id']) ?>"><?= $e($a['code']) ?></a> <?= $e($a['name']) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($result['added'] === [] && $result['removed'] === []): ?>
  <p><?= $word('FAMILY', 'result_none') ?></p>
<?php endif; ?>
<?php if ($result['skipped'] !== []): ?>
  <p><strong><?= $say('FAMILY', 'result_skipped', count($result['skipped'])) ?></strong></p>
  <ul class="plain result-skipped">
<?php foreach ($result['skipped'] as $s): ?>
    <li><?php if ($s['sku_id'] !== null): ?><a href="<?= $u('/ui/items/' . $s['sku_id']) ?>"><?= $e($s['text']) ?></a><?php else: ?><code><?= $e($s['text']) ?></code><?php endif; ?> · <?= $e($s['why']) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($canManage): ?>
<details class="fold family-add"<?php if ($f['active'] && ($f['items'] === 0 || $errorCode === 'empty_list' || $errorCode === 'too_many_rows')): ?> open<?php endif; ?>>
  <summary><?= $word('FAMILY', 'bulk') ?></summary>
<?php if ($f['active']): ?>
  <form class="record" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/add') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($addKey) ?>">
    <label for="fa-list"><?= $word('FAMILY', 'bulk_label') ?> <span class="hint"><?= $word('FAMILY', 'bulk_hint') ?></span></label>
    <textarea id="fa-list" name="list" rows="6" maxlength="20000" required><?= $e($typed['list']) ?></textarea>
    <p class="actions"><button type="submit" class="primary"><?= $word('FAMILY', 'bulk_button') ?></button></p>
  </form>
<?php else: ?>
  <p class="hint"><?= $word('FAMILY', 'is_off') ?></p>
<?php endif; ?>
</details>
<details class="fold family-rename">
  <summary><?= $word('FAMILY', 'rename') ?></summary>
  <form class="record" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/rename') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($f['version']) ?>">
    <label for="fr-name"><?= $word('FAMILY', 'rename_label') ?></label>
    <input id="fr-name" type="text" name="name" maxlength="255" required value="<?= $e($typed['name']) ?>">
    <p class="actions"><button type="submit" class="secondary"><?= $word('FAMILY', 'rename_button') ?></button></p>
  </form>
</details>
<form class="inline family-switch" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/switch') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($f['version']) ?>">
<?php if ($f['active']): ?>
  <input type="hidden" name="on" value="0">
  <button type="submit" class="secondary"><?= $word('FAMILY', 'switch_off') ?></button> <span class="hint"><?= $word('FAMILY', 'switch_off_does') ?></span>
<?php else: ?>
  <input type="hidden" name="on" value="1">
  <button type="submit" class="secondary"><?= $word('FAMILY', 'switch_on') ?></button>
<?php endif; ?>
</form>
<?php endif; ?>

<h2><?= $word('FAMILY', 'members') ?> <span class="muted"><?= $say('FAMILY', 'members_count', $total) ?></span></h2>
<?php if ($members === []): ?>
<?= $empty(\CW\Ui\Words::FAMILY['members_none'], \CW\Ui\Words::FAMILY['members_none_text']) ?>
<?php else: ?>
<form class="family-members" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/remove') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <div class="table-wrap">
  <table class="stack list family-items">
    <thead>
      <tr>
<?php if ($canManage): ?>
        <th scope="col" class="c-pick"><span class="visually-hidden"><?= $word('FAMILY', 'remove_button') ?></span></th>
<?php endif; ?>
        <th scope="col"><?= $word('FAMILY', 'item') ?></th>
        <th scope="col"><?= $word('FAMILY', 'brand') ?></th>
        <th scope="col"><?= $word('FAMILY', 'flavour') ?></th>
        <th scope="col"><?= $word('FAMILY', 'strength') ?></th>
        <th scope="col" class="num"><?= $word('FAMILY', 'on_hand') ?></th>
        <th scope="col"><?= $word('FAMILY', 'since') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($members as $m): ?>
      <tr>
<?php if ($canManage): ?>
        <td class="c-pick" data-label=""><label class="pick"><input type="checkbox" name="pick_<?= $e($m['id']) ?>" value="1"><span class="visually-hidden"><?= $say('FAMILY', 'tick', $m['code']) ?></span></label></td>
<?php endif; ?>
        <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/items/' . $m['id']) ?>"><?= $e($m['code']) ?></a> <?= $e($m['name']) ?></th>
        <td data-label="<?= $word('FAMILY', 'brand') ?>"><?= $e($m['brand']) ?></td>
        <td data-label="<?= $word('FAMILY', 'flavour') ?>"><?= $e($m['flavour']) ?></td>
        <td data-label="<?= $word('FAMILY', 'strength') ?>"><?= $e($m['strength']) ?></td>
        <td class="num" data-label="<?= $word('FAMILY', 'on_hand') ?>"><?= $n($m['on_hand']) ?></td>
        <td data-label="<?= $word('FAMILY', 'since') ?>"><?= $day($m['since']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php if ($canManage): ?>
  <p class="actions"><button type="submit" class="secondary"><?= $word('FAMILY', 'remove_button') ?></button> <span class="hint"><?= $word('FAMILY', 'remove_does') ?></span></p>
<?php endif; ?>
</form>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('FAMILY', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('FAMILY', 'prev') ?></a><?php endif; ?>
  <span><?= $say('FAMILY', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('FAMILY', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
