<?php
// Home's one Find box (HM_Home): the product search (/ui/search), beside the greeting; on a phone under it. A GET form: Enter sends it.
?>
<form class="hm-find" method="get" action="/ui/search" role="search">
  <label class="visually-hidden" for="hm-q"><?= $word('UI', 'search_label') ?></label>
  <span class="ico ico-search" aria-hidden="true"></span><input id="hm-q" type="search" name="q" maxlength="100" placeholder="<?= $word('UI', 'search_placeholder') ?>" autocomplete="off">
</form>
