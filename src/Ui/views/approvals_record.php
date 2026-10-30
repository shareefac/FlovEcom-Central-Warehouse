<?php
// The open record of Approvals › Waiting for you (docs/decisions.md AV2-AV8): its kind, where it came from and its place in the list, its
// title (a link to its own page where it has one) and status, the facts as big figures, the lines that say what it is and what the answers
// do, the refusal of the last answer, then ONE decision: the form `ap-answer` posts to the kind's own route (inbox=1, the next record and
// this one's place), with the fields the kind needs (the item box of Lines to place, how it was sorted out, the note), its main answer in
// the accent, and the rarer answers under More: a button of the same form with its own route (formaction), or a fold with its own small
// form and note. Without the script every answer is a plain form. Its forms keep what is typed: a link that leaves the page asks first
// (data-unsaved, CV14; AV19), and a refused answer comes back with them marked as not saved. The approved Approvals artboard of 30 Oct 2026
// (docs/decisions.md RD-C13): the figures as tiles (the first in the kind's tint), the note under them, then "Next: …" at the left of the
// answers, More and the main answer at the right; the kind shown on the list travels with the answer (inbox_show). Enter in a field sends the
// main answer: the form's first submit button is its hidden twin (More's buttons stand before the main one; docs/decisions.md RD-F1).
?>
<section class="ap-record <?= $e($record['tone']) ?>" aria-labelledby="ap-h">
<div class="card ap-card">
  <div class="ap-rhead">
    <div class="ap-rtitle">
      <p class="ap-kind"><?= $e($record['one']) ?><?php if ($record['from'] !== ''): ?> · <?= $e($record['from']) ?><?php endif; ?> · <?= $e($record['place']) ?></p>
      <h2 id="ap-h"><?php if ($record['href'] !== null): ?><a href="<?= $u($record['href']) ?>"><?= $e($record['title']) ?></a><?php else: ?><?= $e($record['title']) ?><?php endif; ?></h2>
<?php if ($record['sub'] !== null && $record['sub'] !== ''): ?>
      <p class="ap-sub"><?= $e($record['sub']) ?></p>
<?php endif; ?>
    </div>
    <?= $chip($record['tone'], \CW\Ui\Words::INBOX['waiting']) ?>
  </div>
<?php if ($record['facts'] !== []): ?>
  <dl class="ap-facts">
<?php foreach ($record['facts'] as $f): ?>
    <div class="ap-fact"><dt><?= $e($f['label']) ?></dt><dd<?php if ($f['tone'] !== ''): ?> class="<?= $e($f['tone']) ?>"<?php endif; ?>><?= $e($f['value']) ?></dd></div>
<?php endforeach; ?>
  </dl>
<?php endif; ?>
<?php foreach ($record['lines'] as $line): ?>
  <p class="ap-line"><?= $e($line) ?></p>
<?php endforeach; ?>
<?php if ($record['list'] !== []): ?>
  <ul class="plain ap-notes">
<?php foreach ($record['list'] as $line): ?>
    <li><?= $e($line) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($record['links'] !== []): ?>
  <p class="ap-links"><?php foreach ($record['links'] as $l): ?><a href="<?= $u($l['href']) ?>"><?= $e($l['label']) ?></a> <?php endforeach; ?></p>
<?php endif; ?>
<?php if ($record['error'] !== null): ?>
  <p class="error" role="alert" data-code="<?= $e($record['errorCode']) ?>"><?= $e($record['error']) ?></p>
<?php endif; ?>
  <form class="ap-answer" id="ap-answer" method="post" action="<?= $u($record['action']) ?>" data-unsaved="1"<?php if ($record['error'] !== null && !$record['moreOpen']): ?> data-dirty="1"<?php endif; ?>>
    <button type="submit" class="po-default" tabindex="-1" aria-hidden="true"<?php if ($record['mainName'] !== null): ?> name="<?= $e($record['mainName']) ?>" value="<?= $e($record['mainValue']) ?>"<?php endif; ?>><?= $e($record['main']) ?></button>
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="inbox" value="1">
    <input type="hidden" name="inbox_pos" value="<?= $e($record['pos']) ?>">
<?php if ($record['nextKey'] !== null): ?>
    <input type="hidden" name="inbox_next" value="<?= $e($record['nextKey']) ?>">
<?php endif; ?>
<?php if ($show !== null): ?>
    <input type="hidden" name="inbox_show" value="<?= $e($show) ?>">
<?php endif; ?>
<?php foreach ($record['hidden'] as $name => $value): ?>
    <input type="hidden" name="<?= $e($name) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
<?php if ($record['item'] !== null): ?>
    <div class="ap-fields">
      <div class="ap-item">
        <label for="lq-item-ap"><?= $word('LQ', 'col_item') ?></label>
<?= $partial('lq_item_box', ['id' => 'ap', 'form' => 'ap-answer', 'value' => $record['item']['value'], 'picked' => $record['item']['picked'], 'itemsHref' => $record['item']['itemsHref'],
    'addHref' => $record['item']['add_href'], 'addWords' => $record['item']['addWords'], 'askWords' => $record['item']['askWords']]) ?>
      </div>
      <label class="ap-units"><?= $word('LQ', 'col_each') ?> <input type="number" name="units" min="1" max="10000" step="1" value="<?= $e($record['item']['units']) ?>" required></label>
    </div>
<?php endif; ?>
<?php if ($record['resolution'] !== null): ?>
    <label><?= $e($record['resolution']['label']) ?>
      <select name="resolution">
<?php foreach ($record['resolution']['options'] as $code): ?>
        <option value="<?= $e($code) ?>"<?php if ($record['resolution']['value'] === $code): ?> selected<?php endif; ?>><?= $word($record['resolution']['group'], $code) ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php endif; ?>
<?php if ($record['note'] !== null): ?>
    <label><?= $e($record['note']['label']) ?> <input type="text" name="note" value="<?= $e($record['note']['value']) ?>"<?php if ($record['note']['required']): ?> minlength="<?= $e($record['note']['min']) ?>" required<?php endif; ?> maxlength="<?= $e($record['note']['max']) ?>"<?php if ($record['note']['hint'] !== ''): ?> placeholder="<?= $e($record['note']['hint']) ?>"<?php endif; ?>></label>
<?php endif; ?>
  </form>
  <div class="ap-actions">
    <p class="ap-next"><?php if ($record['next'] !== null): ?><?= $e($record['next']) ?><?php else: ?><?= $word('INBOX', 'last') ?><?php endif; ?></p>
<?php if ($record['more'] !== []): ?>
    <details class="more"<?php if ($record['moreOpen']): ?> open<?php endif; ?>>
      <summary class="btn" aria-label="<?= $word('INBOX', 'more') ?>"><?= $word('UI', 'more') ?></summary>
      <div class="more-menu">
<?php foreach ($record['more'] as $m): ?>
<?php if (isset($m['fold'])): ?>
        <details class="doc-act"<?php if ($record['moreOpen']): ?> open<?php endif; ?>>
          <summary class="btn risky"><?= $e($m['label']) ?></summary>
          <div class="doc-act-panel">
            <form class="record" method="post" action="<?= $u($m['fold']['action']) ?>" data-unsaved="1"<?php if ($record['moreOpen']): ?> data-dirty="1"<?php endif; ?>>
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="inbox" value="1">
              <input type="hidden" name="inbox_pos" value="<?= $e($record['pos']) ?>">
<?php if ($record['nextKey'] !== null): ?>
              <input type="hidden" name="inbox_next" value="<?= $e($record['nextKey']) ?>">
<?php endif; ?>
<?php if ($show !== null): ?>
              <input type="hidden" name="inbox_show" value="<?= $e($show) ?>">
<?php endif; ?>
              <label><?= $e($m['fold']['label']) ?> <input type="text" name="note" value="<?= $e($m['fold']['value']) ?>" minlength="<?= $e($m['fold']['min']) ?>" maxlength="<?= $e($m['fold']['max']) ?>" required></label>
              <p class="actions"><button type="submit" class="btn risky"><?= $e($m['label']) ?></button></p>
            </form>
          </div>
        </details>
<?php else: ?>
        <button type="submit" form="ap-answer" class="btn risky" formaction="<?= $u($m['formaction']) ?>"<?php if (isset($m['name'])): ?> name="<?= $e($m['name']) ?>" value="<?= $e($m['value']) ?>"<?php endif; ?>><?= $e($m['label']) ?></button>
<?php endif; ?>
<?php endforeach; ?>
      </div>
    </details>
<?php endif; ?>
    <button type="submit" form="ap-answer" class="btn primary"<?php if ($record['mainName'] !== null): ?> name="<?= $e($record['mainName']) ?>" value="<?= $e($record['mainValue']) ?>"<?php endif; ?>><?= $e($record['main']) ?></button>
  </div>
</div>
</section>
