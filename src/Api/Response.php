<?php

declare(strict_types=1);

namespace CW\Api;

use CW\CwException;
use CW\Idempotency;
use CW\OpResult;

/**
 * The JSON envelope every answer uses: {"ok": bool, "data": object|null, "error": null|{code, message}}.
 *
 * `data` is canonical (object keys sorted, lists kept in order), so an answer replayed from the
 * idempotency table is byte-identical to the first one (MySQL's JSON type re-orders keys).
 * An error never carries internals: codes and messages come from the domain (OpResult,
 * CwException) or from fixed texts; unexpected failures are only "internal" + a request id.
 */
final class Response
{
    /** Messages for domain error codes whose body has none. */
    private const DEFAULT_MESSAGES = [
        'refused' => 'the order cannot be held; see data.lines',
        'released' => 'this order was released before it was reserved',
        'lines_changed' => 'the order is held with other lines',
        'use_cancel' => 'the order is paid; cancel its units instead',
        'opening_orders_done' => 'opening orders were already accepted',
        'unresolved_line' => 'some lines name no known item; nothing was booked',
        'not_sellable' => 'a channel can only sell from a sellable warehouse',
    ];

    /** @var array<string, string> */
    private array $headers = [];

    /** @param array<string, mixed>|null $data */
    private function __construct(
        public readonly int $status,
        public readonly bool $ok,
        public readonly ?array $data,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
    ) {
    }

    /** @param array<string, mixed>|null $data */
    public static function ok(?array $data, int $status = 200): self
    {
        return new self($status, true, $data, null, null);
    }

    /** @param array<string, mixed>|null $data */
    public static function error(int $status, string $code, string $message, ?array $data = null): self
    {
        return new self($status, false, $data, $code, $message);
    }

    /** A domain outcome (possibly replayed from the idempotency table). */
    public static function fromOpResult(OpResult $r): self
    {
        if ($r->ok()) {
            $resp = self::ok($r->body, $r->status);
        } else {
            $body = $r->body;
            $code = is_string($body['error'] ?? null) ? $body['error'] : 'refused';
            $message = is_string($body['message'] ?? null) ? $body['message'] : (self::DEFAULT_MESSAGES[$code] ?? 'the request was refused');
            unset($body['error'], $body['message']);
            $resp = self::error($r->status, $code, $message, $body === [] ? null : $body);
        }
        if ($r->replayed) {
            $resp->headers['Idempotent-Replayed'] = 'true';
        }
        return $resp;
    }

    public static function fromException(CwException $e): self
    {
        return self::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->detail === [] ? null : $e->detail);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array{ok: bool, data: mixed, error: array{code: string, message: string}|null} */
    public function envelope(): array
    {
        return [
            'ok' => $this->ok,
            'data' => $this->data === null ? null : Idempotency::canonical($this->data),
            'error' => $this->ok ? null : ['code' => (string) $this->errorCode, 'message' => (string) $this->errorMessage],
        ];
    }

    public function json(): string
    {
        $env = $this->envelope();
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        // An empty data object stays an object ({}), never a list.
        if (is_array($env['data']) && $env['data'] === []) {
            $env['data'] = new \stdClass();
        }
        return json_encode($env, $flags);
    }

    /** Emits the response (front controller only). */
    public function send(): void
    {
        $body = $this->json();
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
        }
        echo $body;
    }
}
