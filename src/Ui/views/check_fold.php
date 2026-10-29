<?php
// The Check & match fold of a list row (docs/decisions.md CM1; To review, Store Products): a <details> that opens in place under the
// row's name. With app.js, opening it shows the variant checked against its suggestion (data-check, a path of this site: the fragment
// listing_check.php, which alone decides whether the row may be answered here). Without app.js, or when the fragment cannot be shown,
// it offers the variant's page.
?>
<details class="check-fold" data-check-fold data-check="<?= $e($check) ?>" data-listing-id="<?= $e($listing) ?>" data-loading="<?= $word('CHECK', 'loading') ?>" data-failed="<?= $word('CHECK', 'failed') ?>" data-opened="<?= $word('CHECK', 'next_open') ?>">
  <summary class="btn sm" title="<?= $say('CHECK', 'fold_named', $title ?? \CW\Ui\Words::LISTING['no_title']) ?>"><?= $word('CHECK', 'fold') ?></summary>
  <div class="lc-results" data-check-results aria-live="polite"></div>
  <p class="lc-page"><a href="<?= $e($link) ?>"><?= $word('CHECK', 'open_page') ?></a></p>
</details>
