<h1><?= $word('PAGE_TITLE', 'reviews') ?></h1>
<?= $intro('reviews') ?>
<div class="head-help">
  <p class="muted"><?= $word('CHECKS', 'how') ?></p>
  <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
</div>
<form class="filters" method="get" action="/ui/documents/reviews">
  <label><?= $word('CHECKS', 'kind') ?>
    <select name="type">
      <option value=""><?= $word('CHECKS', 'everything') ?></option>
<?php foreach ($types as $code => $name): ?>
      <option value="<?= $e($code) ?>"<?php if ($type === $code): ?> selected<?php endif; ?>><?= $e($name) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <button type="submit" class="btn secondary"><?= $word('CHECKS', 'show') ?></button>
</form>
<?php if ($approvals === [] && $reviews === []): ?>
<?php if ($type !== null): ?>
<?= $empty(\CW\Ui\Words::CHECKS['none_kind'], \CW\Ui\Words::CHECKS['none_kind_text'], '/ui/documents/reviews', \CW\Ui\Words::CHECKS['show_all']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::CHECKS['none'], \CW\Ui\Words::CHECKS['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php foreach ([['id' => 'approvals', 'kind' => 'approval', 'rows' => $approvals], ['id' => 'reviews', 'kind' => 'review', 'rows' => $reviews]] as $list): ?>
<section id="<?= $e($list['id']) ?>" aria-labelledby="<?= $e($list['id']) ?>-h">
  <h2 id="<?= $e($list['id']) ?>-h"><?= $word('CHECK_KIND', $list['kind']) ?></h2>
<?php if ($list['rows'] === []): ?>
  <p class="muted"><?= $word('CHECKS', 'empty_list') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list checks">
    <thead>
      <tr>
        <th scope="col"><?= $word('CHECKS', 'what') ?></th>
        <th scope="col"><?= $word('CHECKS', 'why') ?></th>
        <th scope="col" class="num"><?= $word('CHECKS', 'value') ?></th>
        <th scope="col"><?= $word('CHECKS', 'asked_by') ?></th>
        <th scope="col"><?= $word('CHECKS', 'asked_on') ?></th>
        <th scope="col"><?= $word('CHECKS', 'check_by') ?></th>
        <th scope="col"><?= $word('CHECKS', 'next') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list['rows'] as $r): ?>
      <tr class="<?php if ($r['overdue']): ?>blocked<?php elseif ($r['refusal'] === null): ?>needs<?php else: ?>off<?php endif; ?>">
        <th scope="row" class="c-head"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['label']) ?></a></th>
        <td data-label="<?= $word('CHECKS', 'why') ?>"><?= $word('CHECK_REASON', $r['reason']) ?></td>
        <td data-label="<?= $word('CHECKS', 'value') ?>" class="num"><?php if ($r['money'] !== null): ?><?= $money($r['money']) ?><?php elseif ($r['items'] !== null): ?><?= $say('CHECKS', 'items', $r['items']) ?><?php endif; ?></td>
        <td data-label="<?= $word('CHECKS', 'asked_by') ?>"><?= $e($r['opened_by']) ?></td>
        <td data-label="<?= $word('CHECKS', 'asked_on') ?>"><?= $when($r['opened_at']) ?></td>
        <td data-label="<?= $word('CHECKS', 'check_by') ?>"><?= $day($r['due_at']) ?><?php if ($r['overdue']): ?> <?= $chip('blocked', \CW\Ui\Words::CHECKS['late']) ?><?php endif; ?></td>
        <td class="c-next"><?php if ($r['refusal'] === null): ?><a class="btn primary sm" href="<?= $u($r['href']) ?>"><?= $word('CHECKS', 'open') ?></a><?php else: ?><span class="muted"><?= $e($r['refusal']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>
