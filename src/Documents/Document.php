<?php

declare(strict_types=1);

namespace CW\Documents;

/**
 * One `document` row (0008), as read: the header every document type shares (I17). Immutable: a change is a new
 * read. Lines are separate (`document_line`, Documents::lines()); module data lives in extension tables keyed
 * (document_id, line_no).
 *
 * Status machine: draft -> posted | awaiting_approval | cancelled; awaiting_approval -> posted (approved) | draft
 * (withdrawn) | cancelled (rejected); posted -> reversed (by its reversal document). A reversal is itself a posted
 * document that is never reversed; a reversal that puts stock back above the approval limit starts as
 * awaiting_approval and is posted (approved) or cancelled (rejected, withdrawn, or superseded by the rejection of its
 * original), never a draft (I32).
 */
final class Document
{
    public const STATUSES = ['draft', 'awaiting_approval', 'posted', 'reversed', 'cancelled'];
    public const REVIEW_STATES = ['not_required', 'pending', 'approved', 'rejected'];

    public function __construct(
        public readonly int $id,
        public readonly string $docType,
        public readonly ?string $number,
        public readonly string $status,
        public readonly int $version,
        public readonly ?string $externalRef,
        public readonly ?string $docDate,
        public readonly ?int $warehouseId,
        public readonly ?string $reasonCode,
        public readonly ?string $note,
        public readonly ?int $createdBy,
        public readonly string $createdActor,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?int $submittedBy,
        public readonly ?string $submittedAt,
        public readonly ?int $postedBy,
        public readonly ?string $postedActor,
        public readonly ?string $postedAt,
        public readonly ?string $postedHash,
        public readonly ?int $reversesId,
        public readonly ?int $cancelledBy,
        public readonly ?string $cancelledAt,
        public readonly ?string $cancelReason,
        public readonly ?string $reviewState,
    ) {
    }

    /** @param array<string, mixed> $r a `SELECT * FROM document` row */
    public static function fromRow(array $r): self
    {
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $str = static fn (mixed $v): ?string => $v === null ? null : (string) $v;
        return new self(
            (int) $r['id'], (string) $r['doc_type'], $str($r['number']), (string) $r['status'], (int) $r['version'],
            $str($r['external_ref']), $str($r['doc_date']), $int($r['warehouse_id']), $str($r['reason_code']), $str($r['note']),
            $int($r['created_by']), (string) $r['created_actor'], (string) $r['created_at'], (string) $r['updated_at'],
            $int($r['submitted_by']), $str($r['submitted_at']), $int($r['posted_by']), $str($r['posted_actor']), $str($r['posted_at']),
            $str($r['posted_hash']), $int($r['reverses_id']), $int($r['cancelled_by']), $str($r['cancelled_at']), $str($r['cancel_reason']),
            $str($r['review_state']),
        );
    }

    /** Posted or reversed: it has a number, a posted_hash, and its effects are booked. */
    public function isPosted(): bool
    {
        return $this->status === 'posted' || $this->status === 'reversed';
    }

    public function isReversal(): bool
    {
        return $this->reversesId !== null;
    }

    /** The number, or "draft #12" / "awaiting approval #12" / "cancelled #12" without one (screens, file names, messages). */
    public function label(): string
    {
        return $this->number ?? str_replace('_', ' ', $this->status) . ' #' . $this->id;
    }

    /**
     * The header fields posted_hash covers (Documents::fingerprint), with $number when the row does not carry it yet
     * (the posting computes the hash in the same UPDATE that sets the number).
     *
     * @return array<string, mixed>
     */
    public function fingerprintHeader(?string $number = null): array
    {
        return ['id' => $this->id, 'doc_type' => $this->docType, 'number' => $number ?? $this->number, 'external_ref' => $this->externalRef,
            'doc_date' => $this->docDate, 'warehouse_id' => $this->warehouseId, 'reason_code' => $this->reasonCode, 'note' => $this->note,
            'reverses_id' => $this->reversesId];
    }
}
