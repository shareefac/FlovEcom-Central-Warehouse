<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\CwException;

/**
 * The words of "Claude reads the upload" (CW-1182; docs/decisions.md AI12): why a reading failed, in the staff's words, from its reason code
 * and the field it names (never Claude's own text, never what a field holds), with what to do next for that kind of document; and a refusal
 * of DocumentReader::start in words.
 */
final class AiReadWords
{
    /** A failed (or expired) reading in words: "CW could not read this document: <why> <what to do next>". */
    public static function failure(string $kind, ?string $reason, ?string $detail): string
    {
        $why = match ($reason) {
            'refusal' => Words::AIREAD['why_refusal'],
            'not_readable' => Words::has('AIREAD', 'why_not_readable_' . (string) $detail) ? Words::AIREAD['why_not_readable_' . (string) $detail]
                : Words::AIREAD['why_not_readable'],
            'bad_answer' => Words::say('AIREAD', 'why_bad_answer', (string) ($detail ?? '')),
            'count' => Words::AIREAD['why_count'],
            'not_gbp' => Words::AIREAD['why_not_gbp'],
            'max_tokens' => Words::AIREAD['why_max_tokens'],
            'auth' => Words::AIREAD['why_auth'],
            'forbidden' => Words::AIREAD['why_forbidden'],
            'billing' => Words::AIREAD['why_billing'],
            'spend_limit' => Words::AIREAD['why_spend_limit'],
            'rate_limited', 'overloaded', 'server', 'timeout', 'connection' => Words::AIREAD['why_busy'],
            'off' => Words::AIREAD['why_off'],
            'limit' => Words::AIREAD['why_limit'],
            'not_allowed' => Words::AIREAD['why_not_allowed'],
            'file_gone', 'stopped', 'too_old', 'expired' => Words::AIREAD['why_late'],
            'error' => Words::AIREAD['why_error'],
            default => Words::AIREAD['why_refused_request'],
        };
        $next = Words::has('AIREAD', 'next_' . $kind) ? ' ' . Words::AIREAD['next_' . $kind] : '';
        return Words::say('AIREAD', 'failed', $why) . $next;
    }

    /** DocumentReader::start's refusal in words (its code); null for a refusal that is not the reader's own (permission: the caller's words). */
    public static function refusal(CwException $e): ?string
    {
        return match ($e->errorCode) {
            'ai_off' => Words::AIREAD[$e->getMessage() === 'off_key' ? 'off_key' : 'off_switch'],
            'ai_limit' => Words::say('AIREAD', 'limit', '£' . number_format(((int) ($e->detail['used'] ?? 0)) / 100, 2),
                '£' . number_format(((int) ($e->detail['limit'] ?? 0)) / 100, 2)),
            'ai_heic' => Words::AIREAD['heic'],
            'ai_type' => Words::AIREAD['type'],
            'ai_type_expense' => Words::AIREAD['type_expense'], // AI15: an expense keeps a PDF, a JPEG or a PNG
            'ai_spool' => Words::AIREAD['spool'],
            'too_large' => Words::say('AIREAD', 'too_large', (string) intdiv(\CW\Ai\DocumentReader::MAX_BYTES, 1_048_576)),
            'no_file' => Words::AIREAD['read_no_file'],
            default => null,
        };
    }
}
