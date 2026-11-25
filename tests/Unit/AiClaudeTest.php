<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Ai\AiUsage;
use CW\Ai\Claude;
use CW\Ai\ExpenseInvoiceKind;
use CW\Ai\RoyalMailBillKind;
use CW\Tests\Support\AiAnswers;
use CW\Tests\Support\FakeClaude;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * CW-1182 (docs/decisions.md AI2): the one call to the Claude API, through Anthropic's own PHP SDK with a FAKE HTTP client (no test reaches the
 * network): the request as the API takes it (the document BEFORE the text, base64; a photo as an image block; the kind's schema as structured
 * outputs with the effort set; the refusal fallback opted in; no thinking setting; streamed; the fixed address), and every answer read by its
 * stop reason first (refusal and max_tokens are failures, never half an answer), the tokens of every attempt counted, every error the SDK
 * raises turned into a reason code, never the key, the document or Claude's text in what comes back.
 */
final class AiClaudeTest extends TestCase
{
    private const KEY = 'sk-ant-api03-MADEUPKEYFORTESTSONLY0000000000000000';

    private static function claude(FakeClaude $f, ?\Closure $now = null, int $deadline = Claude::DEADLINE_SEC): Claude
    {
        return new Claude(self::KEY, 'claude-opus-5-5', 'medium', $f, 0, $deadline, $now);
    }

    public function testTheRequestIsWhatTheApiTakes(): void
    {
        $prev = getenv('ANTHROPIC_BASE_URL');
        putenv('ANTHROPIC_BASE_URL=https://elsewhere.example.invalid');
        try {
            $f = (new FakeClaude())->push(FakeClaude::answer(AiAnswers::invoice(), 2100, 640));
            $r = self::claude($f)->read(new ExpenseInvoiceKind(), "%PDF-1.4\nmade up\n", 'application/pdf');
        } finally {
            putenv($prev === false ? 'ANTHROPIC_BASE_URL' : 'ANTHROPIC_BASE_URL=' . $prev);
        }
        self::assertSame(['ok', 2100, 640, 'claude-opus-5-5', false], [$r['reason'], $r['input_tokens'], $r['output_tokens'], $r['served_model'], $r['fallback']]);
        self::assertSame(AiAnswers::invoice(), $r['json']);
        self::assertCount(1, $f->sent);
        $req = $f->sent[0];
        self::assertSame(['POST', 'https://api.anthropic.com/v1/messages'], [$req['method'], $req['url']], 'the fixed address: never one from the environment');
        self::assertSame('Bearer ' . self::KEY, $req['headers']['authorization'] ?? null, 'the key from the setting, as the SDK sends it');
        self::assertStringContainsString(Claude::FALLBACK_BETA, $req['headers']['anthropic-beta'] ?? '');
        $b = $req['body'];
        self::assertSame(['claude-opus-5-5', 8000, true, 'default'], [$b['model'], $b['max_tokens'], $b['stream'], $b['fallbacks']]);
        self::assertArrayNotHasKey('thinking', $b, 'thinking is always on for claude-opus-5-5: nothing sent');
        self::assertArrayNotHasKey('temperature', $b);
        self::assertSame('medium', $b['output_config']['effort']);
        self::assertSame(['type' => 'json_schema', 'schema' => (new ExpenseInvoiceKind())->schema()], $b['output_config']['format']);
        self::assertSame(Claude::SYSTEM, $b['system']);
        $content = $b['messages'][0]['content'];
        self::assertSame(['user', 'document', 'text'], [$b['messages'][0]['role'], $content[0]['type'], $content[1]['type']], 'the document BEFORE the text');
        self::assertEquals(['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode("%PDF-1.4\nmade up\n")], $content[0]['source']);
        self::assertSame((new ExpenseInvoiceKind())->instructions(), $content[1]['text']);
        self::assertCount(1, $b['messages']);
        // nothing of the key, the document or the answer's text in what comes back but the decoded answer itself
        $back = json_encode(array_diff_key($r, ['json' => 1]), JSON_THROW_ON_ERROR);
        foreach ([self::KEY, 'made up', 'Made Up Paper'] as $never) {
            self::assertStringNotContainsString($never, $back);
        }
        // a photo goes as an image block; the bill's kind its own limit
        $f->push(FakeClaude::answer(AiAnswers::invoice(), 900, 100));
        self::claude($f)->read(new RoyalMailBillKind(), 'PNGBYTES', 'image/png');
        self::assertEquals(['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode('PNGBYTES')]],
            $f->sent[1]['body']['messages'][0]['content'][0]);
        self::assertSame(64000, $f->sent[1]['body']['max_tokens']);
    }

    public function testEveryAnswerIsReadByItsStopReasonFirst(): void
    {
        $f = new FakeClaude();
        $c = self::claude($f);
        $k = new ExpenseInvoiceKind();
        $f->push(FakeClaude::answer(null, 1500, 0, ['stop' => 'refusal', 'category' => 'cyber']));
        $r = $c->read($k, 'x', 'image/jpeg');
        self::assertSame(['refusal', null, 'cyber', 1500], [$r['reason'], $r['json'], $r['refusal_category'], $r['input_tokens']]);
        $f->push(FakeClaude::answer('{"readable": true, "supplier_name": "Made', 1500, 8000, ['stop' => 'max_tokens']));
        self::assertSame(['max_tokens', null], [($r = $c->read($k, 'x', 'image/jpeg'))['reason'], $r['json']], 'never half an answer');
        $f->push(FakeClaude::answer('not json at all', 1500, 20));
        self::assertSame('bad_answer', $c->read($k, 'x', 'image/jpeg')['reason']);
        $f->push(FakeClaude::answer('["a list"]', 1500, 20));
        self::assertSame('bad_answer', $c->read($k, 'x', 'image/jpeg')['reason'], 'an object only');
        // declined, then answered by the fallback model: its answer read (after the fallback block), every attempt's tokens counted
        $f->push(FakeClaude::answer(AiAnswers::invoice(), 1500, 700, ['fallback' => 'claude-opus-5']));
        $r = $c->read($k, 'x', 'image/jpeg');
        self::assertSame(['ok', true, 'claude-opus-5', 3000, 700], [$r['reason'], $r['fallback'], $r['served_model'], $r['input_tokens'], $r['output_tokens']]);
        self::assertSame(AiAnswers::invoice(), $r['json']);
        // a cached prompt's tokens are input tokens too (priced as input: never under-counted)
        $f->push(FakeClaude::answer(AiAnswers::invoice(), 100, 10, ['cache_read' => 400]));
        self::assertSame(500, $c->read($k, 'x', 'image/jpeg')['input_tokens']);
    }

    public function testEveryErrorBecomesAReasonCode(): void
    {
        $f = new FakeClaude();
        $c = self::claude($f);
        $k = new ExpenseInvoiceKind();
        $cases = [[401, 'authentication_error', 'auth'], [403, 'permission_error', 'forbidden'], [402, 'billing_error', 'billing'], [429, 'rate_limit_error', 'rate_limited'],
            [529, 'overloaded_error', 'overloaded'], [500, 'api_error', 'server'], [503, 'api_error', 'server'], [400, 'invalid_request_error', 'bad_request'],
            [404, 'not_found_error', 'bad_request'], [413, 'request_too_large', 'too_large'], [409, 'conflict', 'other']];
        foreach ($cases as [$status, $type, $reason]) {
            $f->push(FakeClaude::status($status, $type, 'made-up message naming ' . self::KEY));
            $r = $c->read($k, 'x', 'image/jpeg');
            self::assertSame([$reason, $status, 'req_01FakeError', null, 0], [$r['reason'], $r['status'], $r['request_id'], $r['json'], $r['input_tokens']], (string) $status);
            self::assertStringNotContainsString(self::KEY, json_encode($r, JSON_THROW_ON_ERROR), 'the API\'s message is never kept');
        }
        $f->push(FakeClaude::down(new Request('POST', 'https://api.anthropic.com/v1/messages')));
        self::assertSame('connection', $c->read($k, 'x', 'image/jpeg')['reason']);
    }

    public function testACallLongerThanItsDeadlineIsAbandoned(): void
    {
        $t = 0.0;
        $f = (new FakeClaude())->push(FakeClaude::answer(AiAnswers::invoice(), 100, 10));
        $r = self::claude($f, static function () use (&$t): float {
            return $t += 4.0; // every look at the clock: 4 seconds later
        }, 10)->read(new ExpenseInvoiceKind(), 'x', 'image/jpeg');
        self::assertSame(['timeout', null], [$r['reason'], $r['json']]);
    }

    public function testTheCostIsCountedUpToTheThousandthOfAPenny(): void
    {
        // the docblock's example: a week's bill, 15,000 tokens in and 13,000 out at £3.20 / £16.00 a million = 25.6p
        self::assertSame(25_600, AiUsage::millipence(15_000, 13_000, '3.20', '16.00'));
        self::assertSame(26, AiUsage::pence(25_600), 'pence rounded up');
        // a one-page invoice: 2,100 in, 640 out = 0.672p + 1.024p = 1.696p
        self::assertSame(1_696, AiUsage::millipence(2_100, 640, '3.20', '16.00'));
        self::assertSame(1, AiUsage::millipence(1, 0, '3.20', '16.00'), 'a part of a thousandth is a whole one (rounded up)');
        self::assertSame(0, AiUsage::millipence(0, 0, '3.20', '16.00'));
        self::assertSame(0, AiUsage::millipence(100, 100, 'x', ''), 'a price that is not one counts nothing (the settings refuse it)');
        self::assertSame(3_000, \CW\Ai\AiSettings::pence('30'));
        self::assertSame(3_050, \CW\Ai\AiSettings::pence('30.5'));
        self::assertSame(0, \CW\Ai\AiSettings::pence('-1'));
    }
    /**
     * AI16: the most a call may cost, counted BEFORE it is sent (its row is written first, so a reader that dies mid-call still counts): the
     * request's own words at 2 characters a token, 2,000 tokens of framing, 10,000 tokens a page (a page's text and its picture: at most
     * 4,784 visual tokens) and the kind's max_tokens of output. A PDF's pages counted from its page objects, else its page tree's /Count,
     * else 100; a photo is one page.
     */
    public function testTheMostACallMayCostIsCountedFromItsPages(): void
    {
        $five = "%PDF-1.4\n1 0 obj << /Type /Pages /Kids [2 0 R 3 0 R 4 0 R 5 0 R 6 0 R] /Count 5 >> endobj\n"
            . implode('', array_map(static fn (int $i): string => "{$i} 0 obj << /Type /Page /Parent 1 0 R >> endobj\n", range(2, 6))) . "%%EOF\n";
        self::assertSame(5, Claude::pages($five), 'five page objects (the page tree itself is not a page)');
        self::assertSame(12, Claude::pages("%PDF-1.5\n1 0 obj << /Type/Pages /Count 12 >> endobj\n%%EOF\n"), 'no page objects to read: the page tree\'s count');
        // M2 (Codex final of 86aebbd): the larger of the two, never fewer than either (one page object visible, the other 19 packed away)
        self::assertSame(20, Claude::pages("%PDF-1.5\n1 0 obj << /Type /Pages /Count 20 >> endobj\n2 0 obj << /Type /Page >> endobj\n%%EOF\n"));
        self::assertSame(Claude::PAGES_UNKNOWN, Claude::pages("%PDF-1.5\n(compressed object streams)\n%%EOF\n"), 'nothing to count: 100');
        self::assertSame(100, Claude::PAGES_UNKNOWN);
        $bill = new RoyalMailBillKind();
        $words = strlen(Claude::SYSTEM) + strlen($bill->instructions()) + strlen((string) json_encode($bill->schema()));
        $in = Claude::maxInputTokens($bill, $five, 'application/pdf');
        self::assertSame(intdiv($words + 1, 2) + Claude::FRAME_TOKENS + 5 * Claude::PAGE_TOKENS, $in);
        self::assertSame([10_000, 2_000], [Claude::PAGE_TOKENS, Claude::FRAME_TOKENS]);
        $invoice = new ExpenseInvoiceKind();
        self::assertSame(intdiv(strlen(Claude::SYSTEM) + strlen($invoice->instructions()) + strlen((string) json_encode($invoice->schema())) + 1, 2)
            + Claude::FRAME_TOKENS + Claude::PAGE_TOKENS, Claude::maxInputTokens($invoice, 'not read', 'image/png'), 'a photo: one page');
        // £ example (docs/decisions.md AI16): a 5-page bill's most = its ~54,000 tokens in at £3.20 + 64,000 out at £16.00 a million = about £1.20
        // an attempt; M2: every attempt that may be billed is reserved: the request and the server-side fallback's (no SDK retry: 0)
        self::assertSame([2, 0, 300], [Claude::ATTEMPTS, Claude::MAX_RETRIES, Claude::DEADLINE_SEC]);
        $most = \CW\Ai\DocumentReader::most($bill, $five, 'application/pdf', '3.20', '16.00');
        self::assertSame(AiUsage::millipence(2 * $in, 2 * $bill->maxTokens(), '3.20', '16.00'), $most);
        self::assertGreaterThan(230_000, $most);
        self::assertLessThan(250_000, $most);
    }

    /**
     * H2 (Codex final of 86aebbd): only https://api.anthropic.com:443 is ever reached. A 3xx is a failure (`redirect`), never followed: the SDK's
     * own redirect loop never sees it, so the document is never replayed elsewhere; a request to any other place is refused before it leaves.
     * H4: a stream that ends before message_stop is a broken stream (`connection`), never half a message read as an answer. M2: no SDK retry.
     */
    public function testOnlyAnthropicIsReachedAndOnlyAWholeStreamIsRead(): void
    {
        $k = new ExpenseInvoiceKind();
        foreach ([307, 308, 302, 301] as $code) {
            $f = (new FakeClaude())->push(FakeClaude::redirect($code, 'https://elsewhere.example.invalid/v1/messages'), FakeClaude::answer(AiAnswers::invoice(), 10, 10));
            $r = self::claude($f)->read($k, "%PDF-1.4\nmade up\n", 'application/pdf');
            self::assertSame(['redirect', null], [$r['reason'], $r['json']], (string) $code);
            self::assertCount(1, $f->sent, "{$code}: never followed");
        }
        $f = new FakeClaude();
        $guard = new \CW\Ai\AnthropicOnly($f);
        foreach (['https://elsewhere.example.invalid/v1/messages', 'http://api.anthropic.com/v1/messages', 'https://api.anthropic.com:8443/v1/messages',
            'https://api.anthropic.com.example.invalid/v1/messages', 'https://user@api.anthropic.com/v1/messages'] as $url) {
            try {
                $guard->sendRequest(new Request('POST', $url));
                self::fail("refused: {$url}");
            } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
                self::assertInstanceOf(\CW\Ai\AnthropicOnly::class, $guard);
            }
        }
        self::assertSame([], $f->sent, 'nothing reached the network');
        $f->push(FakeClaude::answer(AiAnswers::invoice(), 10, 10));
        self::assertSame(200, $guard->sendRequest(new Request('POST', 'https://api.anthropic.com:443/v1/messages'))->getStatusCode());
        // H4: valid JSON, end_turn, then the connection closed before message_stop: not an answer; counted as a broken stream
        $f = (new FakeClaude())->push(FakeClaude::answer(AiAnswers::invoice(), 2100, 640, ['no_stop' => true]));
        $r = self::claude($f)->read($k, 'x', 'image/jpeg');
        self::assertSame(['connection', null], [$r['reason'], $r['json']]);
        // M2: overloaded twice: ONE request (no SDK retry: a retry would be billed twice under one reservation); the person tries again
        $busy = static fn (): \GuzzleHttp\Psr7\Response => new \GuzzleHttp\Psr7\Response(529, ['Content-Type' => 'application/json'],
            '{"type":"error","error":{"type":"overloaded_error","message":"made up"}}');
        $f = (new FakeClaude())->push($busy(), $busy());
        $r = (new Claude(self::KEY, 'claude-opus-5-5', 'medium', $f))->read($k, 'x', 'image/jpeg');
        self::assertSame(['overloaded', 1], [$r['reason'], count($f->sent)]);
        // M4: the HTTP client's limits: the whole transfer within the deadline (300 s: below the installer's 6-minute drain), no redirect
        $o = array_intersect_key(Claude::guzzleOptions(), array_flip(['timeout', 'connect_timeout', 'read_timeout', 'allow_redirects', 'http_errors']));
        ksort($o);
        self::assertSame(['allow_redirects' => false, 'connect_timeout' => 15, 'http_errors' => false, 'read_timeout' => 120, 'timeout' => 300], $o);
    }
}
