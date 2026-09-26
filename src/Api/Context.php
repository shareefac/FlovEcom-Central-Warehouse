<?php

declare(strict_types=1);

namespace CW\Api;

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Idempotency;

/** What a controller gets: the request, the authenticated channel, the database, path params. */
final class Context
{
    /** @var array<string, mixed>|null */
    private ?array $json = null;

    /** @param array<string, string> $params raw (still percent-encoded) path parameters */
    public function __construct(
        public readonly Request $request,
        public readonly ApiChannel $channel,
        public readonly Db $db,
        private readonly array $params,
    ) {
    }

    public function caller(): Caller
    {
        return Caller::channel($this->channel->id, $this->channel->code, $this->request->remoteAddr);
    }

    /** The Idempotency-Key header; every POST needs one (400 when missing or malformed). */
    public function idemKey(): string
    {
        $key = $this->request->header('idempotency-key');
        if ($key === null || $key === '') {
            throw new CwException('idempotency_key_required', 'this call needs an Idempotency-Key header', 400);
        }
        Idempotency::checkKey($key);
        return $key;
    }

    /** A path parameter, percent-decoded (the core validates its content). */
    public function param(string $name): string
    {
        return rawurldecode($this->params[$name] ?? '');
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->request->query;
    }

    /**
     * The JSON object in the body (Content-Type: application/json). With $emptyIsObject an
     * empty body counts as {} (e.g. a release without an attempt).
     *
     * @return array<string, mixed>
     */
    public function json(bool $emptyIsObject = false): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        $raw = $this->request->body();
        if (trim($raw) === '') {
            if ($emptyIsObject) {
                return $this->json = [];
            }
            throw new CwException('bad_json', 'the request body must be a JSON object', 400);
        }
        $type = strtolower(trim(explode(';', $this->request->header('content-type') ?? '')[0]));
        if ($type !== 'application/json') {
            throw new CwException('unsupported_media_type', 'send the body as Content-Type: application/json', 415);
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CwException('bad_json', 'the request body is not valid JSON', 400);
        }
        if (!is_array($data) || ltrim($raw)[0] !== '{') {
            throw new CwException('bad_json', 'the request body must be a JSON object', 400);
        }
        return $this->json = $data;
    }
}
