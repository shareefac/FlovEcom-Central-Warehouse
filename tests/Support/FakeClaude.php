<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The HTTP seam of the Claude reader in the tests (CW-1182; docs/decisions.md AI2): a PSR-18 client that answers the SDK's request with a
 * RECORDED, MADE-UP response (a stream of server-sent events as the Messages API sends them, or an error status), and keeps every request it
 * was sent (its method, URL, headers and JSON body) for the test to read. No test ever reaches the network: an unexpected request fails.
 */
final class FakeClaude implements ClientInterface
{
    /** @var list<ResponseInterface|\Throwable> */
    private array $queue = [];
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: array<string, mixed>}> */
    public array $sent = [];

    public function push(ResponseInterface|\Throwable ...$answers): self
    {
        foreach ($answers as $a) {
            $this->queue[] = $a;
        }
        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $k => $v) {
            $headers[strtolower($k)] = implode(', ', $v);
        }
        $raw = (string) $request->getBody();
        $this->sent[] = ['method' => $request->getMethod(), 'url' => (string) $request->getUri(), 'headers' => $headers,
            'body' => (array) json_decode($raw === '' ? '{}' : $raw, true, 64, JSON_THROW_ON_ERROR)];
        $next = array_shift($this->queue) ?? throw new \LogicException('FakeClaude: an unexpected request (nothing queued)');
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    /**
     * A streamed answer: message_start (the model, the input tokens), a thinking block (thinking is always on: its text is empty, as the
     * default display gives it), the answer's text in two deltas, message_delta (the stop reason, the output tokens), message_stop.
     *
     * @param array<string, mixed>|string|null $json the answer (an array is JSON-encoded; a string is sent as it is; null: no text block)
     * @param array<string, mixed> $over model, stop (stop_reason), category (a refusal's), fallback (the model that answered after a
     *   decline), cache_read, id
     */
    public static function answer(array|string|null $json, int $in, int $out, array $over = []): ResponseInterface
    {
        $model = (string) ($over['model'] ?? 'claude-opus-5-5');
        $events = [];
        $usage = ['input_tokens' => $in, 'output_tokens' => 1, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => (int) ($over['cache_read'] ?? 0)];
        $events[] = ['message_start', ['type' => 'message_start', 'message' => ['id' => (string) ($over['id'] ?? 'msg_01FakeAnswer'), 'type' => 'message',
            'role' => 'assistant', 'model' => isset($over['fallback']) ? (string) $over['fallback'] : $model, 'content' => [], 'stop_reason' => null,
            'stop_sequence' => null, 'usage' => $usage]]];
        $i = 0;
        if (isset($over['fallback'])) {
            $events[] = ['content_block_start', ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'fallback',
                'from' => ['model' => $model], 'to' => ['model' => (string) $over['fallback']], 'trigger' => ['type' => 'refusal', 'category' => 'cyber']]]];
            $events[] = ['content_block_stop', ['type' => 'content_block_stop', 'index' => $i++]];
        }
        $events[] = ['content_block_start', ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']]];
        $events[] = ['content_block_delta', ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'signature_delta', 'signature' => 'c2lnbmF0dXJl']]];
        $events[] = ['content_block_stop', ['type' => 'content_block_stop', 'index' => $i++]];
        if ($json !== null) {
            $text = is_string($json) ? $json : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $half = intdiv(strlen($text), 2);
            $events[] = ['content_block_start', ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'text', 'text' => '']]];
            $events[] = ['content_block_delta', ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'text_delta', 'text' => substr($text, 0, $half)]]];
            $events[] = ['ping', ['type' => 'ping']];
            $events[] = ['content_block_delta', ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'text_delta', 'text' => substr($text, $half)]]];
            $events[] = ['content_block_stop', ['type' => 'content_block_stop', 'index' => $i]];
        }
        $stop = (string) ($over['stop'] ?? 'end_turn');
        $deltaUsage = ['output_tokens' => $out];
        if (isset($over['fallback'])) {
            $deltaUsage['iterations'] = [['type' => 'message', 'input_tokens' => $in, 'output_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
                ['type' => 'fallback_message', 'model' => (string) $over['fallback'], 'input_tokens' => $in, 'output_tokens' => $out, 'cache_creation_input_tokens' => 0,
                    'cache_read_input_tokens' => 0]];
        }
        $events[] = ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop, 'stop_sequence' => null,
            'stop_details' => $stop === 'refusal' ? ['type' => 'refusal', 'category' => $over['category'] ?? null, 'explanation' => null] : null], 'usage' => $deltaUsage]];
        $events[] = ['message_stop', ['type' => 'message_stop']];
        $body = '';
        foreach ($events as [$name, $data]) {
            $body .= "event: {$name}\ndata: " . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n\n";
        }
        return new Response(200, ['Content-Type' => 'text/event-stream', 'request-id' => 'req_01FakeRequest'], $body);
    }

    /** An error answer as the API sends one (`{"type":"error","error":{"type":...,"message":...}}`). */
    public static function status(int $code, string $type, string $message = 'made-up error'): ResponseInterface
    {
        return new Response($code, ['Content-Type' => 'application/json', 'request-id' => 'req_01FakeError', 'x-should-retry' => 'false'],
            json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]], JSON_THROW_ON_ERROR));
    }

    /** A network failure as a PSR-18 client throws one (the host cannot be reached). */
    public static function down(RequestInterface $request): \Throwable
    {
        return new class ('could not connect (made up)', $request) extends \RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $m, private readonly RequestInterface $r)
            {
                parent::__construct($m);
            }

            public function getRequest(): RequestInterface
            {
                return $this->r;
            }
        };
    }
}
