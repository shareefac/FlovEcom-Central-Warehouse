<?php

declare(strict_types=1);

namespace CW\Documents;

use CW\Db;

/**
 * A document type whose content is written by more people than its creator, submitter and poster (the review rule's three,
 * I19) implements this on its DocumentHandler, so the second person who reviews it is someone who wrote none of it.
 *
 * The goods receipt (IM6, I-3; docs/decisions.md I133) is the first: the goods-in bench records the stamp check and the line
 * exceptions on the desk's draft, and those findings decide what is booked where (MAIN, VERIFY, UNSTAMPED), so the person who
 * did the bench check never reviews the receipt either.
 *
 * Documents asks it when a task is decided (Documents::approve / reject: 403 own_document), when the screens ask who may decide
 * (Documents::refusalFor), and for the badge (Documents::decidableCount, through involvedSql()).
 */
interface ReviewInvolvement
{
    /**
     * The other people who wrote part of $doc: staff id => why they may not decide its tasks ("You checked this delivery at
     * the goods-in bench: another reviewer must review it.").
     *
     * @return array<int, string>
     */
    public function involved(Db $db, Document $doc): array;

    /**
     * The same rule in SQL for Documents::decidableCount: a condition on the document alias `d` with exactly one `?` (the
     * staff id) that is true when that person wrote part of the document.
     */
    public function involvedSql(): string;
}
