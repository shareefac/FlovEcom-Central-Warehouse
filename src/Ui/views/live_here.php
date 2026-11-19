<?php
// Who else has this draft open (the live drafts plan, step 3b, 3.1; docs/decisions.md LV18-LV25), under the title: drawn empty, filled by
// app.js's block "live" from each answer of the page's poll (names as text, never as markup): the people as chips in their colour inside
// the page's sentence ("Fazil is here too", "Fazil and Asha are here too", "4 others are here", "Asha is looking", "Fazil (away)"); a
// note ("Paused. Click to carry on.", signed out, "Fazil saved a change at 14:02. [Show it]", confirmed by someone else); and one polite
// live region that says who came and who left, in whole sentences, at most one every few seconds. Without the script, or with live.mode
// off (then this is not drawn), nothing shows.
?>
<div class="live-here" data-live-here>
  <p class="live-who" data-live-who aria-label="<?= $word('LIVE', 'label') ?>" hidden></p>
  <p class="live-note" data-live-note role="status" hidden></p>
  <p class="visually-hidden" data-live-say aria-live="polite"></p>
</div>
