<?php

declare(strict_types=1);

namespace CW\Ai;

use Anthropic\Beta\Messages\BetaBase64ImageSource;
use Anthropic\Beta\Messages\BetaBase64PDFSource;
use Anthropic\Beta\Messages\BetaFallbackParam;
use Anthropic\Beta\Messages\BetaImageBlockParam;
use Anthropic\Beta\Messages\BetaJSONOutputFormat;
use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaOutputConfig;
use Anthropic\Beta\Messages\BetaRequestDocumentBlock;
use Anthropic\Beta\Messages\BetaTextBlockParam;
use Anthropic\Client;
use Anthropic\ContractSpendLimitReachedException;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\ContentTooLargeException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\OverloadedException;
use Anthropic\Core\Exceptions\PaymentRequiredException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\Exceptions\UnprocessableEntityException;
use Anthropic\EnforcedSpendLimitReachedException;
use Anthropic\LinkedOrganizationSpendLimitReachedException;
use Anthropic\Lib\Streaming\MessageAccumulator;
use Anthropic\RequestOptions;
use Psr\Http\Client\ClientInterface;

/**
 * The one place CW calls the Claude API (CW-1182; docs/decisions.md AI2), through Anthropic's own PHP SDK (anthropic-ai/sdk): one document
 * (a PDF as a `document` block, a photo as an `image` block, base64, BEFORE the text block that says what to do with it), the kind's fixed
 * JSON shape as structured outputs (output_config.format) with the effort set explicitly (claude-opus-5-5's default is medium; thinking is
 * always on for it, so no `thinking` is sent), and the server-side refusal fallback opted in (beta server-side-fallback-2026-07-01,
 * fallbacks "default": a policy decline is retried by the API on the model it names; a refusal on the final answer stays a refusal).
 *
 * The answer is streamed (a five-page bill is minutes of output; a stream keeps the connection busy) and folded with the SDK's own
 * MessageAccumulator; this class's own deadline bounds the whole call. stop_reason is read before any content: `refusal` and `max_tokens` are
 * failures, never half an answer. The answer's text is read only after the last `fallback` block (the model that answered).
 *
 * The HTTP seam (docs/decisions.md AI2): the SDK sends through a PSR-18 client: Guzzle on the servers, a fake in the tests (no test ever calls
 * the API). The base URL is fixed (an ANTHROPIC_BASE_URL in the environment never redirects the key), OpenTelemetry is off, and the key is
 * passed in (never read from the environment). Nothing here logs: the result carries numbers and a reason code only, never the key, the
 * document or Claude's text (the caller keeps the decoded answer only as long as the person needs it).
 */
final class Claude
{
    public const BASE_URL = 'https://api.anthropic.com';
    /** The server-side refusal fallback (claude-api skill: new claude-opus-5-5 code opts in by default). */
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    /**
     * The ONE fallback model named (M2, the re-check of ca8987b: "default" is server-defined and could try more than one): a declined request
     * is read once more by it, with the same output limit, so a call is billed for at most ATTEMPTS attempts.
     */
    public const FALLBACK_MODEL = 'claude-opus-4-8';
    /**
     * What one call may take in all (seconds; M4, Codex final of 86aebbd): the HTTP client's whole transfer (connect, the stream, every byte)
     * is cut at it, so a reading never outlives the installer's 6-minute drain; the stream is abandoned after it too (reason `timeout`).
     */
    public const DEADLINE_SEC = 300;
    /** No SDK retry (M2): a retry would be a second billable attempt under one reservation; a busy API is said and the person sends again. */
    public const MAX_RETRIES = 0;
    /** The attempts a call may be billed for (M2): the request and the server-side fallback's (a declined request read by another model). */
    public const ATTEMPTS = 2;
    /** Guzzle's limits: to connect, and with no byte read (a stream sends a ping at least every few seconds). */
    public const CONNECT_SEC = 15;
    public const READ_IDLE_SEC = 120;
    /** The few words the system prompt says (the kind's own instructions come after the document). */
    public const SYSTEM = 'You copy what a business document prints into a fixed JSON form for a warehouse\'s accounts. Copy exactly what is printed. '
        . 'Never work a figure out, never correct one, never guess: when something cannot be read with certainty, say the document is not readable.';
    /** The reason codes of a result (ai_call.reason): ok, then every way a call fails. */
    public const REASONS = ['ok', 'refusal', 'max_tokens', 'bad_answer', 'auth', 'forbidden', 'billing', 'spend_limit', 'rate_limited', 'overloaded', 'server',
        'timeout', 'connection', 'redirect', 'bad_request', 'too_large', 'other'];

    /** @var \Closure(): float seconds, monotonic */
    private readonly \Closure $now;
    /** When this reading began (its count, then its call): ONE wall clock of DEADLINE_SEC over both (M4, AI20). */
    private ?float $begun = null;

    /**
     * @param ClientInterface|null $transport the PSR-18 client (null: Guzzle with CONNECT_SEC / READ_IDLE_SEC)
     * @param (\Closure(): float)|null $now a monotonic clock in seconds (tests)
     */
    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $model,
        private readonly string $effort,
        private readonly ?ClientInterface $transport = null,
        private readonly int $maxRetries = self::MAX_RETRIES,
        private readonly int $deadlineSec = self::DEADLINE_SEC,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): float => hrtime(true) / 1e9;
    }

    /**
     * A PDF's pages as it shows them: the larger of its page objects (/Type /Page, never the page tree's /Pages) and the largest /Count of its
     * page tree; never fewer than one. A note for the runner's log only (AI20): the reservation is the API's own count (count()).
     */
    public static function pages(string $pdf): int
    {
        $objects = (int) preg_match_all('#/Type\s*/Page(?![A-Za-z])#', $pdf);
        $count = preg_match_all('#/Count\s+(\d{1,6})#', $pdf, $m) > 0 ? max(array_map('intval', $m[1])) : 0;
        return max(1, $objects, $count);
    }

    /**
     * The EXACT input of the reading read() will send (AI20, the lead, 25 Nov ~22:15 UK): the API's token-counting endpoint asked for the very
     * request (the same document block, instructions, system prompt, model and output schema), through the same guard (AnthropicOnly) and
     * within the same wall clock. Free; nothing is read. Anything but a whole count (a failure, a refusal elsewhere, an answer of another
     * form) is `count_failed`: the caller sends nothing.
     *
     * @return array{reason: string, tokens: ?int, seconds: float}
     */
    public function count(Kind $kind, string $bytes, string $mediaType): array
    {
        $this->begun ??= ($this->now)();
        $started = ($this->now)();
        $out = ['reason' => 'count_failed', 'tokens' => null, 'seconds' => 0.0];
        try {
            $r = $this->client()->beta->messages->countTokens(
                messages: $this->messages($kind, $bytes, $mediaType),
                model: $this->model,
                outputConfig: $this->outputConfig($kind),
                system: self::SYSTEM,
                betas: [self::FALLBACK_BETA],
            );
            if (is_int($r->inputTokens) && $r->inputTokens > 0) {
                $out = ['reason' => 'ok', 'tokens' => $r->inputTokens] + $out;
            }
        } catch (\Throwable) {
            // count_failed: never a message kept (it could quote)
        }
        return self::done($out, $started, $this->now);
    }

    /**
     * One document read into the kind's shape.
     *
     * @return array{reason: string, json: ?array<string, mixed>, input_tokens: int, output_tokens: int, served_model: ?string, fallback: bool,
     *   refusal_category: ?string, status: ?int, request_id: ?string, seconds: float}
     */
    public function read(Kind $kind, string $bytes, string $mediaType): array
    {
        $this->begun ??= ($this->now)();
        $started = ($this->now)();
        $out = ['reason' => 'other', 'json' => null, 'input_tokens' => 0, 'output_tokens' => 0, 'served_model' => null, 'fallback' => false, 'refusal_category' => null,
            'status' => null, 'request_id' => null, 'seconds' => 0.0];
        try {
            $stream = $this->client()->beta->messages->createStream(
                maxTokens: $kind->maxTokens(),
                messages: $this->messages($kind, $bytes, $mediaType),
                model: $this->model,
                fallbacks: [BetaFallbackParam::with(model: self::FALLBACK_MODEL, maxTokens: $kind->maxTokens())],
                outputConfig: $this->outputConfig($kind),
                system: self::SYSTEM,
                betas: [self::FALLBACK_BETA],
            );
            $acc = MessageAccumulator::forBetaMessages();
            $late = false;
            foreach ($stream as $event) {
                $acc->accumulate($event);
                if (($this->now)() - $this->begun > $this->deadlineSec) {
                    $late = true;
                    break;
                }
            }
            if ($late) {
                $out['reason'] = 'timeout';
                return self::done($out, $started, $this->now);
            }
            if (!$acc->isComplete()) {
                // H4: the stream ended before message_stop (a connection closed early): never half a message read as an answer
                $out['reason'] = 'connection';
                return self::done($out, $started, $this->now);
            }
            $message = $acc->message();
            if (!$message instanceof BetaMessage) {
                $out['reason'] = 'bad_answer';
                return self::done($out, $started, $this->now);
            }
            return self::done(self::answer($message) + $out, $started, $this->now);
        } catch (EnforcedSpendLimitReachedException | ContractSpendLimitReachedException | LinkedOrganizationSpendLimitReachedException $e) {
            $out['reason'] = 'spend_limit';
        } catch (RateLimitException $e) {
            $out['reason'] = 'rate_limited';
        } catch (OverloadedException $e) {
            $out['reason'] = 'overloaded';
        } catch (InternalServerException $e) {
            $out['reason'] = 'server';
        } catch (AuthenticationException $e) {
            $out['reason'] = 'auth';
        } catch (PermissionDeniedException $e) {
            $out['reason'] = 'forbidden';
        } catch (PaymentRequiredException $e) {
            $out['reason'] = 'billing';
        } catch (ContentTooLargeException $e) {
            $out['reason'] = 'too_large';
        } catch (BadRequestException | UnprocessableEntityException | NotFoundException $e) {
            $out['reason'] = 'bad_request';
        } catch (APIStatusException $e) {
            $out['reason'] = 'other';
        } catch (APITimeoutException $e) {
            $out['reason'] = 'timeout';
        } catch (APIConnectionException $e) {
            $out['reason'] = AnthropicOnly::isRefusal($e) ? 'redirect' : (AnthropicOnly::isLate($e) ? 'timeout' : 'connection');
        } catch (AnthropicException $e) {
            $out['reason'] = 'other';
        } catch (\LogicException | \JsonException | \UnexpectedValueException $e) {
            $out['reason'] = 'bad_answer'; // a stream CW cannot fold, or an answer not of the SDK's shape
        } catch (\RuntimeException $e) {
            // the stream broke while it was read (Guzzle's read timeout, a dropped connection); the guard's refusal said apart
            $out['reason'] = AnthropicOnly::isRefusal($e) ? 'redirect' : (AnthropicOnly::isLate($e) ? 'timeout' : 'connection');
        }
        if ($e instanceof APIException) {
            $out['status'] = $e->status ?? $e->response?->getStatusCode();
            $rid = $e->getRequestID();
            $out['request_id'] = $rid !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $rid) === 1 ? $rid : null;
        }
        return self::done($out, $started, $this->now);
    }

    /**
     * What a whole message says: the stop reason first, the tokens of every attempt (usage.iterations when there are several: a declined
     * attempt before a fallback is billed too), the model that answered, and the JSON of the text after the last fallback block.
     *
     * @return array{reason: string, json: ?array<string, mixed>, input_tokens: int, output_tokens: int, served_model: string, fallback: bool, refusal_category: ?string,
     *   request_id: ?string}
     */
    private static function answer(BetaMessage $m): array
    {
        $in = 0;
        $outTokens = 0;
        $fallback = false;
        $iterations = $m->usage->iterations ?? null;
        if (is_array($iterations) && $iterations !== []) {
            foreach ($iterations as $it) {
                $in += (int) ($it->inputTokens ?? 0) + (int) ($it->cacheCreationInputTokens ?? 0) + (int) ($it->cacheReadInputTokens ?? 0);
                $outTokens += (int) ($it->outputTokens ?? 0);
                $fallback = $fallback || ($it->type ?? '') === 'fallback_message';
            }
        } else {
            $in = $m->usage->inputTokens + (int) ($m->usage->cacheCreationInputTokens ?? 0) + (int) ($m->usage->cacheReadInputTokens ?? 0);
            $outTokens = $m->usage->outputTokens;
        }
        $text = '';
        foreach ($m->content as $block) {
            $type = $block->type ?? '';
            if ($type === 'fallback') {
                $fallback = true;
                $text = ''; // only what the model that answered wrote
            } elseif ($type === 'text') {
                $text .= (string) $block->text;
            }
        }
        $rid = preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $m->id) === 1 ? $m->id : null;
        $base = ['input_tokens' => $in, 'output_tokens' => $outTokens, 'served_model' => $m->model, 'fallback' => $fallback, 'refusal_category' => null, 'request_id' => $rid];
        $stop = (string) ($m->stopReason ?? '');
        if ($stop === 'refusal') {
            $cat = $m->stopDetails?->category ?? null;
            return ['reason' => 'refusal', 'json' => null, 'refusal_category' => is_string($cat) && preg_match('/^[a-z_]{1,32}$/D', $cat) === 1 ? $cat : null] + $base;
        }
        if ($stop === 'max_tokens') {
            return ['reason' => 'max_tokens', 'json' => null] + $base;
        }
        if ($stop !== 'end_turn' && $stop !== 'stop_sequence') {
            return ['reason' => 'bad_answer', 'json' => null] + $base;
        }
        try {
            $json = json_decode($text, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            $json = null;
        }
        return is_array($json) && !array_is_list($json) ? ['reason' => 'ok', 'json' => $json] + $base : ['reason' => 'bad_answer', 'json' => null] + $base;
    }

    /**
     * @param array<string, mixed> $out
     * @param \Closure(): float $now
     * @return array<string, mixed>
     */
    private static function done(array $out, float $started, \Closure $now): array
    {
        $out['seconds'] = round(max(0.0, $now() - $started), 2);
        return $out;
    }

    /**
     * Guzzle's limits (M4): CONNECT_SEC to connect, READ_IDLE_SEC with nothing read, and the WHOLE transfer within DEADLINE_SEC (the response
     * is taken whole by the client, so this is a wall clock over the stream); never a redirect, never an exception for a status (the SDK reads
     * it).
     *
     * @return array<string, mixed>
     */
    public static function guzzleOptions(int $deadlineSec = self::DEADLINE_SEC): array
    {
        // '_curl_retries' at Guzzle's own last (2): curl never replays the request below the SDK after a failed rewind (M4, the re-check of ca8987b)
        return ['connect_timeout' => min(self::CONNECT_SEC, $deadlineSec), 'read_timeout' => self::READ_IDLE_SEC, 'timeout' => $deadlineSec, 'http_errors' => false,
            'allow_redirects' => false, '_curl_retries' => 2];
    }

    private static function guzzle(int $deadlineSec): ClientInterface
    {
        return new \GuzzleHttp\Client(self::guzzleOptions($deadlineSec));
    }

    /**
     * The SDK's client for this reading. H2: every request through AnthropicOnly (https://api.anthropic.com:443 only, a redirect never
     * followed); M4: ONE wall clock over the reading's count and call (a request after it is never sent; the HTTP client's own timeout ends
     * the transfer at what is left of it; curl replays nothing).
     */
    private function client(): Client
    {
        $deadline = (float) $this->begun + $this->deadlineSec;
        $left = max(1, (int) ceil($deadline - ($this->now)()));
        return new Client(apiKey: $this->apiKey, baseUrl: self::BASE_URL, requestOptions: RequestOptions::with(maxRetries: $this->maxRetries,
            transporter: new AnthropicOnly($this->transport ?? self::guzzle(min($left, $this->deadlineSec)), $deadline, $this->now),
            timeout: (float) $this->deadlineSec), openTelemetry: false);
    }

    /**
     * The one user message: the document (a PDF block, or a photo) BEFORE the kind's instructions; the same for the count and the call.
     *
     * @return list<array<string, mixed>>
     */
    private function messages(Kind $kind, string $bytes, string $mediaType): array
    {
        $data = base64_encode($bytes);
        $doc = $mediaType === 'application/pdf'
            ? BetaRequestDocumentBlock::with(source: BetaBase64PDFSource::with(data: $data))
            : BetaImageBlockParam::with(source: BetaBase64ImageSource::with(data: $data, mediaType: $mediaType));
        return [['role' => 'user', 'content' => [$doc, BetaTextBlockParam::with(text: $kind->instructions())]]];
    }

    /** The effort and the answer's fixed shape (structured outputs): the same for the count and the call. */
    private function outputConfig(Kind $kind): BetaOutputConfig
    {
        return BetaOutputConfig::with(effort: $this->effort, format: BetaJSONOutputFormat::with(schema: $kind->schema()));
    }
}
