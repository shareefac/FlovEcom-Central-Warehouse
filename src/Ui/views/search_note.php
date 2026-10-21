<?php
// While a search is on (docs/decisions.md SX5; the matching lists, Store Products, New Variants, By Item): how many rows it found, and
// a link that clears the search alone (the page's other filters stay). `count` is what the page lists for it, `text` what was typed,
// `clear` the page's address without the search.
?>
<p class="search-note"><span class="search-found"><?php if ($count === 1): ?><?= $say('FIND', 'found_one', \CW\Ui\Words::quoted($text, '')) ?><?php else: ?><?= $say('FIND', 'found_many', $count, \CW\Ui\Words::quoted($text, '')) ?><?php endif; ?></span> <a class="search-clear" href="<?= $e($clear) ?>"><?= $word('FIND', 'clear') ?></a></p>
