<?php

declare(strict_types=1);

namespace CW\Documents;

use CW\Caller;
use CW\Db;

/**
 * Optional for a DocumentHandler (pack C, review finding 5): module rows that must change when a record of its type is cancelled
 * before it is posted, whichever way: a draft stopped (Documents::cancelDraft) or an approval request refused (Documents::reject). A
 * supplier invoice frees the supplier's number it held. Called inside the cancelling transaction, after the document row is
 * cancelled and its audit row written; a reversal's request that is cancelled calls it too (the handler ignores what is not its own).
 */
interface CancelHook
{
    public function cancelled(Db $db, Document $doc, Caller $caller): void;
}
