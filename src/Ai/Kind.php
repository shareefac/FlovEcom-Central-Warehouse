<?php

declare(strict_types=1);

namespace CW\Ai;

/**
 * One kind of document Claude reads for CW (CW-1182; docs/decisions.md AI1): what it is told (instructions()), the fixed JSON shape it must
 * answer in (schema(), sent as structured outputs: every object closed, every field required), how long its answer may be (maxTokens(): the
 * thinking counts in it), and CW's own strict reading of the answer (shape()): every field checked for its form, the whole answer refused when
 * one is not (naming the row and the field, never what it holds), nothing worked out or corrected. A later kind (a supplier invoice with lines,
 * a goods-in or count file) is one more class.
 */
interface Kind
{
    /** The kind's code (ai_read.kind, ai_call.kind): royal_mail_bill, expense_invoice. */
    public function name(): string;

    /** What Claude is told after the document (a text block: the document comes first). */
    public function instructions(): string;

    /** @return array<string, mixed> the JSON schema of the answer (output_config.format) */
    public function schema(): array;

    /** The answer's limit in tokens (thinking included). */
    public function maxTokens(): int;

    /**
     * The answer read strictly: the fields CW keeps, in CW's forms. Throws CwException 422 `ai_bad_answer` (a field not in its form; the words
     * name the row and the field) or `ai_not_readable` (Claude said the document is not one of this kind, or not readable whole).
     *
     * @param array<string, mixed> $answer the decoded JSON
     * @return array<string, mixed>
     */
    public function shape(array $answer): array;
}
