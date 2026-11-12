<?php
// Inventory › Doubtful stock › To check (0066; docs/decisions.md DS1-): ONLY the items of one store whose stock figure is in doubt, in the order
// to check them, 50 a page. One search and the ONE Show (To check / Checked, with the store and why in its panel). A board of six columns: the
// item (its page, its CW number, the list's note), the shop's figure when the list was made, CW's figure now, why, the units sold in 30 days,
// and the answer: ONE plain form, "On the shelf" and "Save count" (no script; a refusal comes back with the words under the box and what was
// typed in it), and under More "Not needed" with its note. A phone gets one card per item (.stack). A person who may not count only looks.
?>
<?= $head(\CW\Ui\Words::MENU['doubtful'], intro: 'doubtful', look: $lookOnly) ?>
<form class="toolbar" method="get" action="/ui/stock/doubtful" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('DOUBT', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($q) ?>" maxlength="100" placeholder="<?= $word('DOUBT', 'find') ?>"></div>
<?php if (count($stores) < 2 && $storeCode !== null): ?>
  <input type="hidden" name="store" value="<?= $e($storeCode) ?>">
<?php endif; ?>
  <?= $showStart() ?>
  <div class="tb-state">
<?php if (count($stores) > 1): ?>
    <label for="tb-store"><?= $word('DOUBT', 'store') ?></label>
    <select id="tb-store" name="store">
<?php foreach ($stores as $s): ?>
      <option value="<?= $e($s['code']) ?>"<?php if ($store !== null && $s['id'] === $store['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?> (<?= $n($s['open']) ?>)</option>
<?php endforeach; ?>
    </select>
<?php endif; ?>
    <label for="tb-why"><?= $word('DOUBT', 'why') ?></label>
    <select id="tb-why" name="why">
      <option value=""><?= $word('DOUBT', 'all') ?></option>
<?php foreach ($whys as $code): ?>
      <option value="<?= $e($code) ?>"<?php if ($why === $code): ?> selected<?php endif; ?>><?= $word('DOUBT_WHY', $code) ?></option>
<?php endforeach; ?>
    </select>
    <button class="btn sm" type="submit"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <?= $showEnd() ?>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($rows === []): ?>
<?php if ($narrowed): ?>
<?= $empty(\CW\Ui\Words::DOUBT['none_found'], \CW\Ui\Words::DOUBT['none_found_text'], $listHref, \CW\Ui\Words::DOUBT['show_all']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::DOUBT['none'], \CW\Ui\Words::DOUBT['none_text'], $checkedHref, \CW\Ui\Words::SEGMENT['doubtful_checked']) ?>
<?php endif; ?>
<?php else: ?>
<?php if ($shown !== null): ?>
<p class="muted"><?= $e($shown) ?></p>
<?php endif; ?>
<div class="grp-block doc-group needs">
  <h3 class="grp-title"><?= $word('DOUBT_STATE', 'open') ?> <span class="grp-count"><?= $n($total) ?></span></h3>
  <div class="table-wrap doc-board">
  <table class="stack list board doc-list doubt-list">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('DOUBT', 'col_item') ?></th>
        <th scope="col" class="num"><?= $word('DOUBT', 'col_shop') ?></th>
        <th scope="col" class="num"><?= $word('DOUBT', 'col_cw') ?></th>
        <th scope="col"><?= $word('DOUBT', 'col_why') ?></th>
        <th scope="col" class="num"><?= $word('DOUBT', 'col_sold') ?></th>
        <th scope="col"><?= $word('DOUBT', 'col_answer') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr id="doubt-<?= $e($r['id']) ?>">
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?></span><?php if ($r['note'] !== null): ?><span class="o-sub"><?= $say('DOUBT', 'list_note', (string) $r['note']) ?></span><?php endif; ?></th>
        <td class="num" data-label="<?= $word('DOUBT', 'col_shop') ?>"><?= $n($r['shop_qty']) ?></td>
        <td class="num" data-label="<?= $word('DOUBT', 'col_cw') ?>"><?= $n($r['cw_now']) ?><?php if ($r['cw_reserved'] > 0): ?><span class="o-sub"><?= $say('COUNTS', 'reserved', $r['cw_reserved']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('DOUBT', 'col_why') ?>"><span class="tag <?= $e($r['tone']) ?>"><?= $e($r['why']) ?></span></td>
        <td class="num" data-label="<?= $word('DOUBT', 'col_sold') ?>"><?= $n($r['sold_30d']) ?></td>
        <td class="c-next" data-label="">
<?php if ($canCount): ?>
          <form class="count-answer" method="post" action="<?= $u($r['count_action']) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php foreach ($back as $k => $v): ?>
            <input type="hidden" name="<?= $e($k) ?>" value="<?= $e($v) ?>">
<?php endforeach; ?>
            <label for="qty-<?= $e($r['id']) ?>"><?= $word('DOUBT', 'on_shelf') ?></label>
            <input id="qty-<?= $e($r['id']) ?>" type="text" name="qty" value="<?= $e($r['typed_qty']) ?>" inputmode="numeric" pattern="[0-9]*" maxlength="20" autocomplete="off" required<?php if ($r['count_error'] !== null): ?> aria-invalid="true" aria-describedby="qty-<?= $e($r['id']) ?>-error"<?php endif; ?>>
            <button class="btn" type="submit"><?= $word('DOUBT', 'save') ?></button>
<?php if ($r['count_error'] !== null): ?>
            <p class="error" id="qty-<?= $e($r['id']) ?>-error" role="alert" data-code="<?= $e($r['error_code']) ?>"><?= $e($r['count_error']) ?></p>
<?php endif; ?>
          </form>
          <details class="more"<?php if ($r['dismiss_error'] !== null): ?> open<?php endif; ?>>
            <summary class="btn"><?= $word('UI', 'more') ?></summary>
            <div class="more-menu">
              <form class="record" method="post" action="<?= $u($r['dismiss_action']) ?>">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php foreach ($back as $k => $v): ?>
                <input type="hidden" name="<?= $e($k) ?>" value="<?= $e($v) ?>">
<?php endforeach; ?>
                <label><?= $word('DOUBT', 'note') ?> <input type="text" name="note" value="<?= $e($r['typed_note']) ?>" minlength="<?= $e($noteMin) ?>" maxlength="<?= $e($noteMax) ?>" required<?php if ($r['dismiss_error'] !== null): ?> aria-invalid="true" aria-describedby="note-<?= $e($r['id']) ?>-error"<?php endif; ?>></label>
<?php if ($r['dismiss_error'] !== null): ?>
                <p class="error" id="note-<?= $e($r['id']) ?>-error" role="alert" data-code="<?= $e($r['error_code']) ?>"><?= $e($r['dismiss_error']) ?></p>
<?php endif; ?>
                <p class="actions"><button class="btn risky" type="submit"><?= $word('DOUBT', 'not_needed') ?></button></p>
              </form>
            </div>
          </details>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php if ($prev !== null || $next !== null): ?>
<p class="pager"><?php if ($prev !== null): ?><a href="<?= $e($prev) ?>"><?= $word('DOUBT', 'prev') ?></a><?php endif; ?><?php if ($next !== null): ?><a href="<?= $e($next) ?>"><?= $word('DOUBT', 'next') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
