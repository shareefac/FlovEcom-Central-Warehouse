<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Config;
use CW\Db;
use CW\Invoices\ErpTransport;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Kernel;
use CW\Ui\Words;

/**
 * Settings › APIs as served (0063; docs/decisions.md AK1-AK12), as the app login: the page in the Settings list; a save seals the key and
 * the secret (the page then says "Key ends …ABCD" and "Secret: saved (hidden)" and never holds either, not even in a field); an empty
 * secret keeps the saved one; the same form sent twice is one save; a form without its CSRF token, a person who may only look, and a form
 * drawn before someone else's save are refused with nothing saved; "Check the connection" makes one read through a fake ERPNext (no
 * network) and says how it went in a fixed sentence (connected / wrong key / not allowed / cannot reach), never echoing the key, the
 * secret or ERPNext's answer, and is audited without them; Remove needs its tick. Fake keys only.
 */
final class ApisScreensTest extends KernelUiTestCase
{
    private const KEY = 'fakeKey0000ABCD';
    private const SECRET = 'fakeSecret000099';
    private const PATH = '/ui/system/apis';

    /** How the fake ERPNext answers the next read: [status, body] or a \RuntimeException to throw. */
    private mixed $answer = [200, '{"data":[]}'];
    /** @var list<array{url: string, headers: list<string>, timeout: int}> */
    private array $calls = [];
    /** app.env as the page reads it (no ERPNext key unless a test puts one). */
    private Config $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = Config::fromArrays([], []);
    }

    /** The kernel as production builds it, with a fake ERPNext and this test's app.env for Settings › APIs. */
    protected function kernel(): Kernel
    {
        $db = self::$appDb;
        $key = self::$uiKey;
        $test = $this;
        $erp = new class ($test) implements ErpTransport {
            public function __construct(private readonly ApisScreensTest $test)
            {
            }

            public function get(string $url, array $headers, int $timeoutSec): array
            {
                return $this->test->erpAnswer($url, $headers, $timeoutSec);
            }
        };
        return new Kernel(static fn (): Db => $db, static fn (): string => $key, static function (string $m): void {
            self::$log[] = $m;
        }, fn (Db $db): array => $this->handlers($db), null, $erp, fn (): Config => $this->env);
    }

    /** @param list<string> $headers @return array{status: int, body: string} */
    public function erpAnswer(string $url, array $headers, int $timeoutSec): array
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'timeout' => $timeoutSec];
        if ($this->answer instanceof \RuntimeException) {
            throw $this->answer;
        }
        return ['status' => $this->answer[0], 'body' => $this->answer[1]];
    }

    /** @return array<string, mixed> */
    private static function row(): array
    {
        return (array) self::$db->one("SELECT * FROM api_credential WHERE name = 'erp_read'");
    }

    private static function xp(UiResponse $r): \DOMXPath
    {
        return new \DOMXPath($r->dom());
    }

    /** Neither the key nor the secret anywhere in the page (its text, its fields, its links). */
    private static function noSecrets(UiResponse $r, string $where): void
    {
        foreach ([self::KEY, self::SECRET, 'fakeSecretNEW222'] as $s) {
            self::assertStringNotContainsString($s, $r->body, "{$where}: never in the page");
        }
    }

    private function save(KernelBrowser $web, string $key, string $secret, string $url = 'https://vpg-erp.floverfy.com'): UiResponse
    {
        $page = $web->get(self::PATH);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $form = $page->form(self::PATH);
        self::assertNotSame([], $form);
        return $web->post(self::PATH, ['url' => $url, 'api_key' => $key, 'api_secret' => $secret] + $form);
    }

    public function testTheOwnerSavesAKeyAndThePageNeverShowsIt(): void
    {
        $web = $this->signIn($this->uiUser('reviewer'));
        $page = $web->get(self::PATH);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $xp = self::xp($page);
        self::assertSame(Words::RD_SETTINGS['apis'], trim((string) $xp->evaluate('string(//nav[@class="set-nav"]/a[@aria-current])')), 'in the Settings list, shown');
        self::assertSame('Settings', self::currentSection($page));
        self::assertSame(Words::APIS['erp_title'], trim((string) $xp->evaluate('string(//section[@id="erp"]/h2)')));
        self::assertStringContainsString(Words::APIS['use_none'], $page->text());
        // The form: the address filled in, the key and the secret empty; the secret a password field the browser does not fill in.
        $form = $page->form(self::PATH);
        self::assertSame(['csrf', 'form_key', 'version', 'url', 'api_key', 'api_secret'], array_keys($form));
        self::assertSame(['https://vpg-erp.floverfy.com', '', '', '0'], [$form['url'], $form['api_key'], $form['api_secret'], $form['version']]);
        $secret = $xp->query('//input[@name="api_secret"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $secret);
        self::assertSame(['password', 'new-password', ''], [$secret->getAttribute('type'), $secret->getAttribute('autocomplete'), $secret->getAttribute('value')]);
        self::assertSame('off', $xp->evaluate('string(//input[@name="api_key"]/@autocomplete)'));
        self::assertSame('off', $xp->evaluate('string(//form[@action="/ui/system/apis"]/@autocomplete)'));
        self::assertSame(1, $xp->query('//form[@action="/ui/system/apis"]//button[@type="submit" and contains(@class, "primary")]')->length, 'Save is the main button');
        self::assertFalse($page->hasForm(self::PATH . '/check'), 'nothing to check yet');
        self::assertFalse($page->hasForm(self::PATH . '/remove'), 'nothing to remove yet');
        self::assertSame(0, $xp->query('//script[not(@src)] | //*[@style] | //*[@onclick] | //*[@onsubmit]')->length, 'no inline script or style (CSP)');

        $r = $web->post(self::PATH, ['url' => 'https://vpg-erp.floverfy.com', 'api_key' => self::KEY, 'api_secret' => self::SECRET] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(self::PATH . '?notice=saved', $r->location());
        $row = self::row();
        self::assertSame(['https://vpg-erp.floverfy.com', 'ABCD', 1], [$row['url'], $row['key_last4'], (int) $row['version']]);
        self::assertSame(self::SECRET, self::$box->decrypt((string) $row['secret_enc']), 'sealed with the 2-step codes\' key');
        self::assertStringNotContainsString(self::SECRET, (string) $row['secret_enc']);
        self::assertStringNotContainsString(self::KEY, (string) $row['key_enc']);

        $after = $web->follow($r);
        self::assertSame(200, $after->status, $after->describe());
        $text = $after->text();
        self::assertStringContainsString(Words::APIS_NOTICE['saved'], $text);
        self::assertStringContainsString(Words::APIS['use_saved'], $text);
        self::assertStringContainsString(Words::say('APIS', 'key_ends', 'ABCD'), $text);
        self::assertStringContainsString(Words::APIS['secret_saved'], $text);
        self::assertStringContainsString('Saved by Reviewer 1 on ', $text);
        self::noSecrets($after, 'after the save');
        $form = $after->form(self::PATH);
        self::assertSame(['https://vpg-erp.floverfy.com', '', '', '1'], [$form['url'], $form['api_key'], $form['api_secret'], $form['version']]);
        self::assertSame([], array_filter([$form['api_key'], $form['api_secret']]), 'the fields never hold the saved values');
        self::assertTrue($after->hasForm(self::PATH . '/check'));
        self::assertTrue($after->hasForm(self::PATH . '/remove'));

        // The same form sent twice (a double click): one save.
        $again = $web->post(self::PATH, ['url' => 'https://vpg-erp.floverfy.com', 'api_key' => self::KEY, 'api_secret' => self::SECRET] + $page->form(self::PATH));
        self::assertSame([303, self::PATH . '?notice=saved'], [$again->status, $again->location()]);
        self::assertSame(1, (int) self::row()['version']);
        // ... and sent again with another secret (the back button): refused, nothing saved, a fresh form; the secret not echoed.
        $back = $web->post(self::PATH, ['url' => 'https://vpg-erp.floverfy.com', 'api_key' => self::KEY, 'api_secret' => 'fakeSecretNEW222'] + $page->form(self::PATH));
        self::assertSame(409, $back->status, $back->describe());
        self::assertStringContainsString(Words::APIS_ERROR['saved_twice'], $back->text());
        self::noSecrets($back, 'a refused form');
        self::assertSame(self::SECRET, self::$box->decrypt((string) self::row()['secret_enc']));

        // A later save with the secret left empty keeps it; a new key replaces the old one.
        $sealed = self::row()['secret_enc'];
        $r = $this->save($web, 'fakeKeyNEW0WXYZ', '');
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([$sealed, 'WXYZ', 2], [self::row()['secret_enc'], self::row()['key_last4'], (int) self::row()['version']]);
        $r = $this->save($web, '', '');
        self::assertSame(self::PATH . '?notice=unchanged', $r->location(), 'nothing typed, the same address: nothing changed');
        self::assertSame(2, (int) self::row()['version']);

        // Nothing of either in the audit log (the save's rows say "key ends", the address and who).
        $log = (string) json_encode(self::$db->all('SELECT * FROM audit_log'));
        foreach ([self::KEY, self::SECRET, 'fakeKeyNEW0WXYZ', 'fakeSecretNEW222'] as $s) {
            self::assertStringNotContainsString($s, $log);
        }
        self::assertSame(['api.save', 'api.save'], self::$db->column("SELECT action FROM audit_log WHERE action LIKE 'api.%' ORDER BY id"));
        $audit = $web->get('/ui/system/audit');
        self::assertSame(200, $audit->status, $audit->describe());
        self::assertStringContainsString(Words::AUDIT_ACTION['api.save'], $audit->text());
    }

    public function testRefusalsSaveNothingAndNeverEchoTheSecret(): void
    {
        $rev = $this->uiUser('reviewer');
        $web = $this->signIn($rev);
        // No CSRF token: refused before anything is read.
        $form = $web->get(self::PATH)->form(self::PATH);
        $r = $web->post(self::PATH, ['url' => 'https://vpg-erp.floverfy.com', 'api_key' => self::KEY, 'api_secret' => self::SECRET, 'csrf' => 'x'] + $form);
        self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()], $r->describe());
        foreach (['/check', '/remove'] as $p) {
            self::assertSame(403, $web->post(self::PATH . $p, ['csrf' => 'x', 'confirm' => '1', 'version' => '0', 'form_key' => str_repeat('a', 32)])->status, $p);
        }
        self::assertSame(0, (int) self::row()['version']);

        // A bad address, a bad key, nothing typed the first time: the address kept, the key and the secret not, nothing saved.
        foreach ([['http://vpg-erp.floverfy.com', self::KEY, self::SECRET, 'api_bad_url'], ['https://10.0.0.5', self::KEY, self::SECRET, 'api_bad_url'],
            ['https://vpg-erp.floverfy.com', 'fake:key', self::SECRET, 'api_bad_key'], ['https://vpg-erp.floverfy.com', self::KEY, '', 'api_need_secret']] as [$url, $k, $s, $code]) {
            $r = $this->save($web, $k, $s, $url);
            self::assertSame([422, $code], [$r->status, $r->errorCode()], $r->describe());
            self::assertStringContainsString(Words::APIS_ERROR[$code], $r->text());
            self::assertStringContainsString(Words::APIS['retype'], $r->text());
            self::assertSame($url, $r->form(self::PATH)['url'], 'the address as typed');
            self::assertSame(['', ''], [$r->form(self::PATH)['api_key'], $r->form(self::PATH)['api_secret']]);
            self::noSecrets($r, $code);
        }
        self::assertSame(0, (int) self::row()['version']);

        // A form drawn before another person's save: refused, naming them; nothing saved.
        $old = $web->get(self::PATH)->form(self::PATH);
        $admin = $this->signIn($this->uiUser('admin'));
        self::assertSame(303, $this->save($admin, self::KEY, self::SECRET)->status, 'an Admin may save too (settings.manage)');
        $r = $web->post(self::PATH, ['url' => 'https://vpg-erp.floverfy.com', 'api_key' => 'fakeKeyNEW0WXYZ', 'api_secret' => 'fakeSecretNEW222'] + $old);
        self::assertSame([409, 'api_changed'], [$r->status, $r->errorCode()], $r->describe());
        self::assertStringContainsString('Nothing was saved: Admin 2 saved or removed this key on ', $r->text());
        self::noSecrets($r, 'changed meanwhile');
        self::assertSame(['https://vpg-erp.floverfy.com', 'ABCD', 1], [self::row()['url'], self::row()['key_last4'], (int) self::row()['version']]);

        // Another address (AK12, the Codex review of 9ddf7a0, HIGH): a name the server does not trust is refused; a trusted one needs the key
        // and the secret typed again (a saved key is never sent to another address).
        $r = $this->save($web, '', '', 'https://evil.example.com');
        self::assertSame([422, 'api_host_not_allowed'], [$r->status, $r->errorCode()], $r->describe());
        self::assertStringContainsString(Words::APIS_ERROR['api_host_not_allowed'], $r->text());
        $this->env = Config::fromArrays([], ['erp_read_hosts' => 'erp2.example.test']);
        $r = $this->save($web, '', '', 'https://erp2.example.test');
        self::assertSame([422, 'api_new_address'], [$r->status, $r->errorCode()], $r->describe());
        self::assertSame('true', self::xp($r)->evaluate('string(//input[@name="api_secret"]/@aria-invalid)'));
        self::assertSame(['https://vpg-erp.floverfy.com', 1], [self::row()['url'], (int) self::row()['version']], 'nothing saved');
        $this->env = Config::fromArrays([], []);

        // Who may only look (system.view, not settings.manage): the facts, never a form, and every POST refused.
        $auditor = $this->signIn($this->uiUser('auditor'));
        $look = $auditor->get(self::PATH);
        self::assertSame(200, $look->status, $look->describe());
        self::assertStringContainsString(Words::say('APIS', 'key_ends', 'ABCD'), $look->text());
        self::assertSame([false, false, false], [$look->hasForm(self::PATH), $look->hasForm(self::PATH . '/check'), $look->hasForm(self::PATH . '/remove')]);
        self::assertStringContainsString(Words::APIS['look_only'], $look->body);
        self::noSecrets($look, 'look only');
        $token = $this->token($auditor);
        foreach (['' => ['url' => 'https://evil.example.com', 'api_key' => 'fakeKeyNEW0WXYZ', 'api_secret' => 'fakeSecretNEW222', 'version' => '1', 'form_key' => str_repeat('b', 32)],
            '/check' => [], '/remove' => ['version' => '1', 'confirm' => '1', 'form_key' => str_repeat('c', 32)]] as $p => $fields) {
            $r = $auditor->post(self::PATH . $p, ['csrf' => $token] + $fields);
            self::assertSame([403, 'role_not_allowed'], [$r->status, $r->errorCode()], "{$p}: " . $r->describe());
        }
        self::assertSame([], $this->calls, 'no read of ERPNext for who may only look');
        self::assertSame(['https://vpg-erp.floverfy.com', 1], [self::row()['url'], (int) self::row()['version']]);
        // A job without system.view does not even see the page.
        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertSame(403, $buyer->get(self::PATH)->status);
    }

    public function testCheckTheConnectionSaysHowItWentInWordsAndIsAudited(): void
    {
        $web = $this->signIn($this->uiUser('reviewer'));
        // Nothing saved, nothing in app.env: nothing to check (no read).
        $r = $web->post(self::PATH . '/check', ['csrf' => $this->token($web)]);
        self::assertSame([303, self::PATH . '?checked=nothing#erp'], [$r->status, $r->location()], $r->describe());
        self::assertSame([], $this->calls);
        self::assertStringContainsString(Words::APIS_CHECK['nothing'], $web->get(self::PATH, ['checked' => 'nothing'])->text());

        self::assertSame(303, $this->save($web, self::KEY, self::SECRET)->status);
        foreach ([
            'ok' => [200, '{"data":[{"name":"ACC-PINV-2026-00001"}]}'],
            'wrong_key' => [401, '{"exc_type":"AuthenticationError"}'],
            'not_allowed' => [403, '{"exc_type":"PermissionError","exception":"' . self::SECRET . '"}'],
            'unreachable' => new \RuntimeException('ERPNext could not be reached (curl error 7)'),
            'other' => [502, '<html>bad gateway ' . self::SECRET . '</html>'],
        ] as $outcome => $answer) {
            $this->answer = $answer;
            $this->calls = [];
            $page = $web->get(self::PATH);
            $check = $page->form(self::PATH . '/check');
            self::assertSame(['csrf'], array_keys($check));
            $r = $web->post(self::PATH . '/check', $check);
            self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
            self::assertSame(self::PATH . '?checked=' . $outcome . ($outcome === 'other' ? '&code=502' : '') . '#erp', $r->location());
            self::assertCount(1, $this->calls, "{$outcome}: ONE read");
            self::assertSame('https://vpg-erp.floverfy.com/api/resource/Purchase%20Invoice?fields=%5B%22name%22%5D&limit_page_length=1', $this->calls[0]['url']);
            self::assertSame('Authorization: token ' . self::KEY . ':' . self::SECRET, $this->calls[0]['headers'][0], 'the saved key, in the header only');
            self::assertSame(10, $this->calls[0]['timeout']);
            $shown = $web->get(self::PATH, ['checked' => $outcome] + ($outcome === 'other' ? ['code' => '502'] : []));
            self::assertSame(200, $shown->status);
            $note = trim((string) self::xp($shown)->evaluate('string(//p[contains(@class, "api-check")])'));
            self::assertSame($outcome === 'other' ? Words::say('APIS_CHECK', 'other', '502') : Words::APIS_CHECK[$outcome], $note, $outcome);
            self::assertSame($outcome === 'ok' ? 1 : 0, self::xp($shown)->query('//p[contains(@class, "api-check") and contains(@class, "done")]')->length);
            self::noSecrets($shown, $outcome);
            self::assertStringNotContainsString('bad gateway', $shown->body, 'never ERPNext\'s answer');
        }
        // A word that is not an outcome, or a code that is not one, is not drawn as one.
        self::assertSame(0, self::xp($web->get(self::PATH, ['checked' => '<b>x</b>']))->query('//p[contains(@class, "api-check")]')->length);
        self::assertSame(Words::APIS_CHECK['other_no_code'], trim((string) self::xp($web->get(self::PATH, ['checked' => 'other', 'code' => 'x1']))
            ->evaluate('string(//p[contains(@class, "api-check")])')));

        $rows = self::$db->all("SELECT CAST(detail AS CHAR) AS d FROM audit_log WHERE action = 'api.check' ORDER BY id");
        self::assertSame(['nothing', 'ok', 'wrong_key', 'not_allowed', 'unreachable', 'other'], array_map(static fn (array $r): string => json_decode((string) $r['d'], true)['outcome'], $rows));
        self::assertSame(['none', 'saved'], array_values(array_unique(array_map(static fn (array $r): string => json_decode((string) $r['d'], true)['source'], $rows))));
        $log = (string) json_encode(self::$db->all('SELECT * FROM audit_log'));
        self::assertStringNotContainsString(self::SECRET, $log);
        self::assertStringNotContainsString(self::KEY, $log);

        // Nothing saved here but a key in app.env: that is the one in use and checked.
        $this->env = Config::fromArrays([], ['erp_read_url' => 'https://env-erp.example.test', 'erp_read_key' => 'envFakeKey123', 'erp_read_secret' => 'envFakeSecret456']);
        $remove = $web->get(self::PATH)->form(self::PATH . '/remove');
        self::assertSame(303, $web->post(self::PATH . '/remove', ['confirm' => '1'] + $remove)->status);
        $page = $web->get(self::PATH);
        self::assertStringContainsString(Words::APIS['use_env'], $page->text());
        $this->answer = [200, '{"data":[]}'];
        $this->calls = [];
        self::assertSame(self::PATH . '?checked=ok#erp', $web->post(self::PATH . '/check', $page->form(self::PATH . '/check'))->location());
        self::assertSame('Authorization: token envFakeKey123:envFakeSecret456', $this->calls[0]['headers'][0]);
        self::assertSame('app_env', json_decode((string) self::$db->value("SELECT CAST(detail AS CHAR) FROM audit_log WHERE action = 'api.check' ORDER BY id DESC LIMIT 1"), true)['source']);
    }

    public function testRemoveNeedsItsTickAndEmptiesTheKey(): void
    {
        $web = $this->signIn($this->uiUser('reviewer'));
        self::assertSame(303, $this->save($web, self::KEY, self::SECRET)->status);
        $page = $web->get(self::PATH);
        $xp = self::xp($page);
        self::assertSame(1, $xp->query('//details[contains(@class, "set-fold")]/form[@action="/ui/system/apis/remove"]//input[@type="checkbox" and @name="confirm" and @required]')->length,
            'the confirm step is in the page: a fold and a tick, no script');
        $remove = $page->form(self::PATH . '/remove');
        self::assertSame(['csrf', 'form_key', 'version'], array_keys($remove), 'the tick starts off');
        $r = $web->post(self::PATH . '/remove', $remove);
        self::assertSame([422, 'api_confirm'], [$r->status, $r->errorCode()], $r->describe());
        self::assertSame(1, self::xp($r)->query('//details[contains(@class, "set-fold") and @open]')->length, 'the fold open again');
        self::assertSame('ABCD', self::row()['key_last4'], 'nothing removed');

        $r = $web->post(self::PATH . '/remove', ['confirm' => '1'] + $remove);
        self::assertSame([303, self::PATH . '?notice=removed'], [$r->status, $r->location()], $r->describe());
        $row = self::row();
        self::assertSame([null, null, null, null, 2], [$row['url'], $row['key_enc'], $row['key_last4'], $row['secret_enc'], (int) $row['version']]);
        $after = $web->follow($r);
        self::assertStringContainsString(Words::APIS_NOTICE['removed'], $after->text());
        self::assertStringContainsString('Removed by Reviewer 1 on ', $after->text());
        self::assertStringContainsString(Words::APIS['use_none'], $after->text());
        self::assertSame('https://vpg-erp.floverfy.com', $after->form(self::PATH)['url'], 'the form starts again from the usual address');
        self::assertFalse($after->hasForm(self::PATH . '/remove'));
        self::assertSame(['api.save', 'api.remove'], self::$db->column("SELECT action FROM audit_log WHERE action LIKE 'api.%' ORDER BY id"));
        // The same removal form again (a double click): one removal.
        self::assertSame(303, $web->post(self::PATH . '/remove', ['confirm' => '1'] + $remove)->status);
        self::assertSame(2, (int) self::row()['version']);
    }
}
