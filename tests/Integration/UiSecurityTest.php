<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Tests\Support\UiTestCase;

/**
 * What keeps the staff screens safe (plan §11): every route needs a session and its role, every POST
 * needs a same-origin request and the CSRF token, every answer carries the hard headers, the two
 * assets are the only files served, and hostile text (product titles come from the internet) can
 * only ever appear as text.
 */
final class UiSecurityTest extends UiTestCase
{
    private const GETS = ['/ui', '/ui/', '/ui/review?queue=Key', '/ui/review?queue=pending', '/ui/review/listing/1', '/ui/items/1', '/ui/search', '/ui/search?q=abc', '/ui/password'];
    private const POSTS = ['/ui/logout', '/ui/password', '/ui/review/listing/1/decide', '/ui/review/decision/1/approve', '/ui/review/decision/1/withdraw'];

    public function testEveryScreenNeedsASignIn(): void
    {
        $web = $this->browser();
        foreach (self::GETS as $path) {
            $r = $web->get($path);
            self::assertSame(303, $r->status, $path);
            self::assertSame('/ui/login', $r->location(), $path);
            self::assertHardened($r, $path);
            self::assertStringNotContainsString('Dashboard', $r->body, $path);
        }
        foreach (self::POSTS as $path) {
            $r = $web->post($path, ['csrf' => 'x', 'action' => 'link']);
            self::assertSame(303, $r->status, "POST {$path}");
            self::assertSame('/ui/login', $r->location(), "POST {$path}");
        }

        // A cookie nobody issued (or one that is not even a token) is the same as none, and is cleared.
        foreach (['A' . str_repeat('b', 42), 'short', str_repeat('x', 5000), '../../etc/passwd', "a\tb"] as $junk) {
            $stranger = $this->browser();
            $stranger->cookies['cw_session'] = $junk;
            $r = $stranger->get('/ui/');
            self::assertSame(303, $r->status, substr($junk, 0, 20));
            self::assertSame('/ui/login', $r->location());
            self::assertStringContainsString('cw_session=;', (string) $r->setCookie('cw_session'));
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_session'));
    }

    public function testUnknownPagesAreNotFoundAndWrongMethodsAreRefused(): void
    {
        $web = $this->signIn($this->uiUser('mapper'));
        foreach (['/ui/nope', '/ui/review/listing/abc', '/ui/review/listing/0', '/ui/review/listing/-1', '/ui/review/listing/1.5', '/ui/items/0', '/ui/items/1/x',
            '/ui/review/listing/99999', '/ui/items/99999', '/ui/review/listing/99999999999999999999999', '/ui/loginx'] as $path) {
            $r = $web->get($path);
            self::assertSame(404, $r->status, $path);
            self::assertHardened($r, $path);
        }
        self::assertSame(404, $web->get('/ui/review', ['queue' => 'Nonsense'])->status);
        self::assertSame('/ui/', $web->get('/ui/review')->location(), 'no queue named: back to the dashboard');

        $r = $web->post('/ui/search', ['csrf' => $this->token($web)]);
        self::assertSame(405, $r->status);
        self::assertSame('GET', $r->header('allow'));
        $r = $web->get('/ui/review/listing/1/decide');
        self::assertSame(405, $r->status);
        self::assertSame('POST', $r->header('allow'));
        self::assertSame(405, $web->send('PUT', '/ui/', 'x=1', ['Content-Type' => 'application/x-www-form-urlencoded'])->status);
        self::assertSame(405, $web->send('DELETE', '/ui/items/1', null)->status);

        // Odd shapes of parameters are answered, never a 500.
        foreach ([['/ui/review', ['queue' => ['Key']]], ['/ui/search', ['q' => ['a', 'b']]], ['/ui/review', ['queue' => 'Key', 'page' => '-3', 'min' => 'x', 'channel' => ['x']]],
            ['/ui/review', ['queue' => 'Key', 'page' => '999999999999999999999']], ['/ui/search', ['q' => str_repeat('é', 2000)]]] as [$path, $query]) {
            $r = $web->get($path, $query);
            self::assertLessThan(500, $r->status, $path . ' ' . json_encode($query) . ' ' . $r->describe());
        }
    }

    public function testEachRouteChecksTheRoleOfThePerson(): void
    {
        $site = $this->site('vpg');
        $sku = $this->item('legacy', 1, 'Target');
        $listing = $this->queued($site, 'V1', 'Key', $sku, ['product_title' => 'A title', 'units_30d' => 5]);
        $conflict = $this->queued($site, 'V2', 'Conflict', $sku, ['product_title' => 'Conflicting', 'units_30d' => 4]);
        $decider = $this->staffUser('mapper');
        $pending = $this->decide($decider, 'link', $this->profiled($site, 'V3', ['product_title' => 'Two people']), ['sku_id' => $sku, 'units_per_item' => 3])['decision_id'];

        $base = $this->decisions();
        $viewer = $this->signIn($this->uiUser('viewer'));
        $mapper = $this->signIn($this->uiUser('mapper'));
        $lead = $this->signIn($this->uiUser('mapping_lead'));

        // A viewer looks but cannot decide: no form on the page, 403 on the POST.
        $page = $viewer->get('/ui/review/listing/' . $listing);
        self::assertSame(200, $page->status);
        self::assertFalse($page->hasForm('/decide'));
        self::assertStringContainsString('Your role (viewer) can look at listings but not decide them.', $page->text());
        $token = $this->token($viewer);
        $r = $viewer->post("/ui/review/listing/{$listing}/decide", ['csrf' => $token, 'action' => 'link', 'sku_id' => (string) $sku, 'units_per_item' => '1', 'expected_map_version' => '1']);
        self::assertSame(403, $r->status);
        self::assertStringContainsString('role_not_allowed', $r->text());
        $r = $viewer->post("/ui/review/decision/{$pending}/withdraw", ['csrf' => $token]);
        self::assertSame(403, $r->status);
        self::assertStringContainsString('role_not_allowed', $r->text());
        $r = $viewer->post("/ui/review/decision/{$pending}/approve", ['csrf' => $token]);
        self::assertSame(403, $r->status);
        self::assertStringContainsString('lead_required', $r->text());
        self::assertSame(200, $viewer->get('/ui/review', ['queue' => 'pending'])->status, 'a viewer may look at the second-approval list');
        self::assertSame(200, $viewer->get('/ui/search', ['q' => 'title'])->status);

        // A mapper decides but cannot approve.
        $r = $mapper->post("/ui/review/decision/{$pending}/approve", ['csrf' => $this->token($mapper)]);
        self::assertSame(403, $r->status);
        self::assertStringContainsString('lead_required', $r->text());
        $list = $mapper->get('/ui/review', ['queue' => 'pending']);
        self::assertStringNotContainsString('>Approve<', $list->body, 'no approve button for a mapper');
        self::assertStringContainsString('Waiting for a mapping lead', $list->text());

        // A Conflict proposal is for a lead: no form for the mapper, refused if forced, a form for the lead.
        $page = $mapper->get('/ui/review/listing/' . $conflict);
        self::assertFalse($page->hasForm('/decide'));
        self::assertStringContainsString('A Conflict proposal can only be decided by a mapping lead.', $page->text());
        $r = $mapper->post("/ui/review/listing/{$conflict}/decide", ['csrf' => $this->token($mapper), 'action' => 'link', 'sku_id' => (string) $sku, 'units_per_item' => '1', 'expected_map_version' => (string) $this->version($conflict)]);
        self::assertSame(403, $r->status);
        self::assertStringContainsString('is decided by a mapping_lead', $r->text());
        self::assertSame('suggested', $this->link($conflict)['status']);
        self::assertTrue($lead->get('/ui/review/listing/' . $conflict)->hasForm('/decide'));
        self::assertStringContainsString('>Approve<', $lead->get('/ui/review', ['queue' => 'pending'])->body);

        self::assertSame($base, $this->decisions(), 'nothing was decided by any of the refusals');
        self::assertSame('suggested', $this->link($listing)['status']);
    }

    public function testEveryFormNeedsItsOwnSessionsToken(): void
    {
        $site = $this->site('vpg');
        $sku = $this->item('legacy', 1);
        $listing = $this->queued($site, 'V1', 'Key', $sku, ['units_30d' => 5]);
        $mine = $this->signIn($this->uiUser('mapper'));
        $theirs = $this->signIn($this->uiUser('mapper'));
        $form = $this->decideForm($mine, $listing, ['queue' => 'Key']);
        $base = $this->decisions();
        self::assertSame('link', $form['action']);
        $theirToken = $this->token($theirs);
        $loginToken = $this->loginFields($this->browser())['csrf'];

        $bad = [
            'no token' => array_diff_key($form, ['csrf' => 1]),
            'empty token' => ['csrf' => ''] + $form,
            'made-up token' => ['csrf' => 'x'] + $form,
            'array token' => ['csrf' => ['a']] + $form,
            "another person's token" => ['csrf' => $theirToken] + $form,
            "the login form's token" => ['csrf' => $loginToken] + $form,
            'token with junk' => ['csrf' => $form['csrf'] . 'x'] + $form,
            'token cut short' => ['csrf' => substr($form['csrf'], 0, -1)] + $form,
        ];
        foreach ($bad as $what => $fields) {
            $r = $mine->send('POST', "/ui/review/listing/{$listing}/decide", http_build_query($fields), ['Content-Type' => 'application/x-www-form-urlencoded']);
            self::assertSame(403, $r->status, $what);
            self::assertStringContainsString('csrf', $r->text(), $what);
            self::assertSame($base, $this->decisions(), $what);
            self::assertSame('suggested', $this->link($listing)['status'], $what);
        }
        // The same goes for the other forms.
        self::assertSame(403, $mine->post('/ui/password', ['current' => 'x', 'new' => str_repeat('n', 14), 'again' => str_repeat('n', 14)])->status);
        self::assertSame(403, $mine->post('/ui/logout', ['csrf' => $theirToken])->status);
        self::assertSame(200, $mine->get('/ui/')->status, 'still signed in');

        // The right token works, and the person's token is the same on every page of the session.
        self::assertSame($form['csrf'], $this->token($mine));
        self::assertNotSame($form['csrf'], $theirToken, 'a token belongs to one session');
        $r = $mine->post("/ui/review/listing/{$listing}/decide", $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('mapped', $this->link($listing)['status']);
    }

    public function testTheLoginFormIsProtectedByItsOwnCookie(): void
    {
        $user = $this->uiUser('mapper');
        $a = $this->browser();
        $fields = $this->loginFields($a);
        $good = ['email' => $user['email'], 'password' => $user['password'], 'code' => self::code($user['secret'])];

        // No pre-login cookie at all (a form posted from somewhere else).
        $b = $this->browser();
        $r = $b->post('/ui/login', $good + ['csrf' => $fields['csrf']]);
        self::assertSame(403, $r->status);
        // A cookie of its own, but the token of another browser's form.
        $c = $this->browser();
        $this->loginFields($c);
        self::assertSame(403, $c->post('/ui/login', $good + ['csrf' => $fields['csrf']])->status);
        // A session-style token is no login token, and vice versa.
        self::assertSame(403, $a->post('/ui/login', $good + ['csrf' => 'x'])->status);
        self::assertSame(403, $a->post('/ui/login', $good)->status);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_session'));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM login_attempt'), 'a forged form is not a login attempt');

        // The genuine form, right code: in.
        self::assertSame(303, $a->post('/ui/login', $good + ['csrf' => $fields['csrf']])->status);
        // The token of the pre-login cookie does not work as a session token afterwards.
        $signed = $this->token($a);
        self::assertNotSame($fields['csrf'], $signed);
        self::assertSame(403, $a->post('/ui/logout', ['csrf' => $fields['csrf']])->status);
    }

    public function testAPostFromAnotherSiteIsRefusedEvenWithAValidToken(): void
    {
        $site = $this->site('vpg');
        $sku = $this->item('legacy', 1);
        $listing = $this->queued($site, 'V1', 'Key', $sku, ['units_30d' => 5]);
        $web = $this->signIn($this->uiUser('mapper'));
        $form = $this->decideForm($web, $listing, ['queue' => 'Key']);
        $base = $this->decisions();
        $path = "/ui/review/listing/{$listing}/decide";

        $foreign = [
            'Origin from another site' => ['Origin' => 'http://evil.example'],
            'Origin with another port' => ['Origin' => 'http://cw-ui.staging.invalid:81'],
            'Origin of a look-alike host' => ['Origin' => 'http://cw-ui.staging.invalid.evil.example'],
            'Origin "null" (sandboxed frame)' => ['Origin' => 'null'],
            'Sec-Fetch-Site cross-site' => ['Sec-Fetch-Site' => 'cross-site'],
            'Sec-Fetch-Site same-site' => ['Sec-Fetch-Site' => 'same-site'],
            'Origin ok but Sec-Fetch-Site cross-site' => ['Origin' => 'http://cw-ui.staging.invalid', 'Sec-Fetch-Site' => 'cross-site'],
        ];
        foreach ($foreign as $what => $headers) {
            $r = $web->post($path, $form, $headers);
            self::assertSame(403, $r->status, $what);
            self::assertStringContainsString('csrf', $r->text(), $what);
            self::assertSame($base, $this->decisions(), $what);
        }
        $r = $this->browser();
        $login = $this->loginFields($r);
        self::assertSame(403, $r->post('/ui/login', ['email' => 'a@b.invalid', 'password' => 'x', 'code' => '123456', 'csrf' => $login['csrf']], ['Origin' => 'http://evil.example'])->status);
        self::assertSame(0, self::auditCount('login.fail'), 'a cross-site post is refused before it counts as a failed sign-in');

        // A browser that says "same origin" (or a navigation typed by the person: none) is fine.
        $ok = $web->post($path, $form, ['Origin' => 'http://cw-ui.staging.invalid', 'Sec-Fetch-Site' => 'same-origin']);
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame($base + 1, $this->decisions());
    }

    public function testEveryAnswerCarriesTheHardHeaders(): void
    {
        $user = $this->uiUser('mapper');
        $anon = $this->browser();
        $web = $this->signIn($user);
        $site = $this->site('vpg');
        $sku = $this->item('legacy', 1);
        $listing = $this->queued($site, 'V1', 'Key', $sku);

        $answers = [
            'login page' => $anon->get('/ui/login'),
            'failed login' => $this->attemptLogin($this->browser(), $user['email'], 'wrong', '000000'),
            'redirect to login' => $this->browser()->get('/ui/'),
            'dashboard' => $web->get('/ui/'),
            'queue' => $web->get('/ui/review', ['queue' => 'Key']),
            'listing' => $web->get('/ui/review/listing/' . $listing),
            'item' => $web->get('/ui/items/' . $sku),
            'search' => $web->get('/ui/search', ['q' => 'item']),
            '404 page' => $web->get('/ui/nothing'),
            '403 page' => $web->post('/ui/review/decision/1/approve', ['csrf' => $this->token($web)]),
            '405 page' => $web->get('/ui/logout'),
            'stylesheet' => $anon->get('/ui/assets/app.css'),
            'script' => $anon->get('/ui/assets/app.js'),
            'missing asset' => $anon->get('/ui/assets/nope.css'),
        ];
        foreach ($answers as $what => $r) {
            self::assertHardened($r, $what);
            if (!in_array($what, ['stylesheet', 'script', 'missing asset'], true)) {
                self::assertSame('no-store', $r->header('cache-control'), "{$what}: pages are never cached");
                self::assertSame('same-origin', $r->header('cross-origin-opener-policy'), $what);
                self::assertSame('same-origin', $r->header('cross-origin-resource-policy'), $what);
            }
        }
        self::assertStringContainsString('text/html', (string) $answers['404 page']->header('content-type'));
        // Nothing on a page comes from another origin.
        foreach (['login page', 'dashboard', 'queue', 'listing', 'item', 'search'] as $what) {
            self::assertDoesNotMatchRegularExpression('#(?:src|href|action)\s*=\s*["\']?(?:https?:)?//#i', $answers[$what]->body, $what);
        }
    }

    public function testOnlyTheTwoAssetsAreServedAndTheyRevalidate(): void
    {
        $web = $this->browser();
        $css = $web->get('/ui/assets/app.css');
        self::assertSame(200, $css->status);
        self::assertSame('text/css; charset=utf-8', $css->header('content-type'));
        self::assertSame('nosniff', $css->header('x-content-type-options'));
        self::assertNotSame('', $css->body);
        $js = $web->get('/ui/assets/app.js');
        self::assertSame(200, $js->status);
        self::assertSame('text/javascript; charset=utf-8', $js->header('content-type'));
        self::assertNotSame('', $js->body);
        self::assertStringNotContainsString('http://', $js->body . $css->body, 'no third-party URL in the assets');
        self::assertStringNotContainsString('https://', $js->body . $css->body);
        self::assertDoesNotMatchRegularExpression('/@import|url\(\s*[\'"]?(?:https?:)?\/\//i', $css->body);
        self::assertStringNotContainsString('eval(', $js->body);
        self::assertStringNotContainsString('innerHTML', $js->body, 'the script never builds markup from strings');

        foreach ([[$css, '/ui/assets/app.css'], [$js, '/ui/assets/app.js']] as [$first, $path]) {
            $etag = (string) $first->header('etag');
            self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);
            $again = $web->get($path, [], ['If-None-Match' => $etag]);
            self::assertSame(304, $again->status, $path);
            self::assertSame('', $again->body);
            self::assertSame($etag, $again->header('etag'));
            self::assertSame(200, $web->get($path, [], ['If-None-Match' => '"other"'])->status);
            $post = $web->post($path, []);
            self::assertSame(405, $post->status, $path);
            self::assertSame('GET, HEAD', $post->header('allow'));
        }

        foreach (['/ui/assets/', '/ui/assets', '/ui/assets/APP.CSS', '/ui/assets/app.css.map', '/ui/assets/app.css/', '/ui/assets/app.css/x', '/ui/assets/index.php',
            '/ui/assets/app.php', '/ui/assets/.htaccess', '/ui/assets/nope.js', '/ui/assets/..%2f..%2fcomposer.json', '/ui/assets/%2e%2e/%2e%2e/composer.json',
            '/ui/assets/../../composer.json', '/ui/assets/app.css%00.js', '/ui/assets/sub/app.css', '/ui/assets/app.css%2f..%2f..%2fcomposer.json'] as $path) {
            $r = $web->send('GET', $path, null);
            self::assertContains($r->status, [400, 403, 404], $path . ' -> ' . $r->status);
            self::assertStringNotContainsString('"require"', $r->body, $path);
            self::assertStringNotContainsString('phpunit', $r->body, $path);
            self::assertStringNotContainsString('<?php', $r->body, $path);
        }
        // Other files under public/ are not reachable by URL either.
        foreach (['/index.php', '/composer.json', '/src/Ui/Kernel.php', '/ui/index.php', '/ui/assets/../index.php', '/.env'] as $path) {
            $r = $web->send('GET', $path, null);
            self::assertStringNotContainsString('<?php', $r->body, $path);
            self::assertStringNotContainsString('namespace CW', $r->body, $path);
        }
    }

    /** A hostile value for one field: markup, quotes and an ampersand, named so a page can be searched for it. */
    private static function hostile(string $field): string
    {
        return "<script>{$field}</script>'\"&";
    }

    /** What the page must contain when it shows that value safely. */
    private static function shown(string $field): string
    {
        return htmlspecialchars(self::hostile($field), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public function testHostileTextIsShownAsTextOnEveryScreen(): void
    {
        $h = self::hostile(...);
        $site = $this->site('vpg');
        $alt = $this->site('alt');
        self::$db->exec('UPDATE channel SET name = ? WHERE id = ?', [$h('chname'), $site->channelId]);
        self::$db->exec('UPDATE channel SET name = ? WHERE id = ?', [$h('chname2'), $alt->channelId]);

        $legacy = $this->item('legacy', 5, $h('sname1'));
        $this->card($legacy, ['brand' => $h('sbrand1'), 'line' => $h('sline1'), 'flavour' => $h('sflav1')]);
        $this->barcode($legacy, $h('tbc1'));
        $strict = $this->item('strict', 2, $h('sname2'));
        $this->card($strict, ['brand' => $h('sbrand2'), 'flavour' => $h('sflav2')]);
        $this->barcode($strict, $h('tbc2'));

        $profile = [
            'product_title' => $h('title'), 'variant_title' => $h('vtitle'), 'brand' => $h('brand'), 'barcodes' => [$h('lbc1'), $h('lbc2')], 'price' => '9.99',
            'attributes' => [['name' => $h('an1'), 'value' => $h('av1')], ['name' => $h('an2'), 'value' => $h('av2')]],
            'perma_link' => 'javascript:alert(document.cookie)', 'units_30d' => 40, 'units_365d' => 400,
        ];
        $evidence = [
            'ai' => ['reason' => $h('reason'), 'fields_not_agree' => [$h('fna')], 'warnings' => [$h('warn')], 'vetoes_on_chosen' => [$h('veto')], 'soft_flags_on_chosen' => [$h('soft')]],
            'band_reasons' => [$h('br1')], 'lane_flags' => [$h('lf')],
            'candidates' => [
                ['sku_id' => $strict, 'cw_id' => $h('cw1'), 'role' => $h('role1'), 'prescore' => $h('pre1'), 'vetoes' => [$h('cveto')], 'soft_flags' => [$h('csoft')]],
                ['sku_id' => 987654, 'cw_id' => $h('cw2'), 'role' => $h('role2'), 'prescore' => $h('pre2'), 'vetoes' => [], 'soft_flags' => []],
            ],
        ];
        $l1 = $this->queued($site, $h('variant'), 'Check', $legacy, $profile, [
            'ai_outcome' => $h('outcome'), 'ai_confidence' => 77, 'ai_model' => $h('model'), 'lane' => $h('lane'), 'flags' => [$h('flag1')],
            'closest_sku_id' => $strict, 'evidence' => $evidence,
        ], 'ui-run');
        $l2 = $this->queued($alt, $h('variant2'), 'Check', null, [
            'product_title' => $h('title2'), 'variant_title' => $h('vtitle2'), 'perma_link' => 'https://shop.example/p?a=1&b=x', 'units_30d' => 3,
        ], ['proposed_new_item' => true, 'flags' => [$h('flag2')]]);

        // History and a waiting decision, written by a person whose name is hostile too.
        $decider = $this->staffUser('mapper');
        self::$db->exec('UPDATE staff_user SET display_name = ? WHERE id = ?', [$h('decider'), $decider->staffUserId]);
        $l3 = $this->profiled($site, 'V3', ['product_title' => $h('title3'), 'variant_title' => $h('vtitle3'), 'units_30d' => 2]);
        $this->decide($decider, 'link', $l3, ['sku_id' => $legacy, 'reason' => $h('reason3')]);
        $l4 = $this->profiled($alt, 'V4', ['product_title' => $h('title4'), 'units_30d' => 1]);
        $this->decide($decider, 'link', $l4, ['sku_id' => $legacy, 'units_per_item' => 3, 'reason' => $h('reason4')]);

        $me = $this->uiUser('mapping_lead');
        self::$db->exec('UPDATE staff_user SET display_name = ? WHERE id = ?', [$h('me'), $me['id']]);
        $web = $this->signIn($me);
        $evil = self::EVIL;
        $attr = self::EVIL_ATTR;

        // page => [path, query, the fields that page must show (escaped)]
        $pages = [
            'dashboard' => ['/ui/', [], []],
            'queue' => ['/ui/review', ['queue' => 'Check'], ['title', 'vtitle', 'brand', 'variant', 'variant2', 'title2', 'sname1', 'outcome', 'lane', 'flag1', 'flag2']],
            'queue, filtered' => ['/ui/review', ['queue' => 'Check', 'channel' => 'vpg', 'min' => '1', 'q' => 'script'], ['title']],
            'queue, hostile filters' => ['/ui/review', ['queue' => 'Check', 'channel' => $attr, 'lane' => $evil, 'min' => $attr, 'q' => $attr, 'page' => $evil], []],
            'listing' => ['/ui/review/listing/' . $l1, ['queue' => 'Check'], [
                'title', 'vtitle', 'brand', 'variant', 'chname', 'lbc1', 'lbc2', 'an1', 'av1', 'an2', 'av2', 'sname1', 'sbrand1', 'sline1', 'sflav1', 'tbc1',
                'outcome', 'model', 'lane', 'flag1', 'reason', 'fna', 'br1', 'warn', 'veto', 'soft', 'sname2', 'role1', 'pre1', 'cveto', 'csoft', 'cw2', 'role2', 'pre2']],
            'listing, picked item' => ['/ui/review/listing/' . $l1, ['queue' => 'Check', 'pick' => (string) $strict], ['sname2', 'sbrand2', 'sflav2', 'tbc2']],
            'listing, hostile context' => ['/ui/review/listing/' . $l1, ['queue' => 'Check', 'fq' => $evil, 'channel' => $attr, 'lane' => $evil, 'min' => $attr, 'pick' => $attr, 's' => $evil, 'notice' => $evil, 'prev' => $evil], ['title']],
            'listing, item search' => ['/ui/review/listing/' . $l1, ['s' => 'script'], ['sname1', 'sname2', 'tbc1']],
            'listing, new item proposal' => ['/ui/review/listing/' . $l2, [], ['title2', 'vtitle2', 'variant2', 'chname2', 'flag2']],
            'listing, linked' => ['/ui/review/listing/' . $l3, [], ['title3', 'vtitle3', 'reason3', 'decider', 'sname1']],
            'listing, waiting' => ['/ui/review/listing/' . $l4, [], ['title4', 'reason4', 'decider', 'sname1']],
            'item' => ['/ui/items/' . $legacy, [], ['sname1', 'sbrand1', 'sline1', 'sflav1', 'tbc1', 'title3', 'vtitle3', 'decider']],
            'protected item' => ['/ui/items/' . $strict, [], ['sname2', 'sbrand2', 'sflav2', 'tbc2']],
            'search by markup' => ['/ui/search', ['q' => $evil], []],
            'search by attribute breakout' => ['/ui/search', ['q' => $attr], []],
            'search by word' => ['/ui/search', ['q' => 'script'], ['sname1', 'sname2', 'title', 'title2']],
            'second approval' => ['/ui/review', ['queue' => 'pending', 'notice' => $evil, 'prev' => $evil], ['title4', 'decider', 'reason4', 'sname1']],
            'password' => ['/ui/password', [], []],
        ];
        foreach ($pages as $what => [$path, $query, $fields]) {
            $r = $web->get($path, $query);
            self::assertInert($r, $what);
            foreach (['me', ...$fields] as $f) {
                self::assertStringContainsString(self::shown($f), $r->body, "{$what}: field {$f} is shown, escaped");
            }
        }

        // Reflected input is escaped where it is put back into a field.
        $escaped = htmlspecialchars($evil, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $escapedAttr = htmlspecialchars($attr, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('value="' . $escaped . '"', $web->get('/ui/search', ['q' => $evil])->body);
        self::assertStringContainsString('value="' . $escapedAttr . '"', $web->get('/ui/search', ['q' => $attr])->body);
        self::assertStringContainsString('value="' . $escapedAttr . '"', $web->get('/ui/review', ['queue' => 'Check', 'q' => $attr])->body);
        self::assertStringContainsString('value="' . $escaped . '"', $web->get('/ui/review/listing/' . $l1, ['queue' => 'Check', 'fq' => $evil, 's' => $evil])->body);

        // A javascript: link is never a link; a good one is kept, escaped.
        $listing = $web->get('/ui/review/listing/' . $l1, ['queue' => 'Check'])->body;
        self::assertStringNotContainsString('javascript:', $listing);
        self::assertStringContainsString('href="https://shop.example/p?a=1&amp;b=x"', $web->get('/ui/review/listing/' . $l2)->body);
        foreach (['https://shop.example/p?a="x"', "https://shop.example/p?a='x'", 'https://shop.example/<b>', 'https://shop.example/a b', "https://shop.example/a\nb", 'ftp://shop.example/p', 'HTTPS://shop.example/p"onmouseover="x'] as $bad) {
            self::$db->exec('UPDATE listing_profile SET perma_link = ? WHERE listing_id = ?', [$bad, $l2]);
            $r = $web->get('/ui/review/listing/' . $l2);
            self::assertInert($r, 'perma_link ' . $bad);
            foreach ($r->hrefs() as $href) {
                self::assertStringStartsNotWith(strtolower(substr($bad, 0, 12)), strtolower($href), 'not a link: ' . $bad);
            }
        }

        // A notice is one of a fixed set of sentences, never the text of the URL.
        $noticed = $web->get('/ui/review', ['queue' => 'Check', 'notice' => $evil]);
        self::assertStringNotContainsString('role="status"', $noticed->body);
        $known = $web->get('/ui/review', ['queue' => 'Check', 'notice' => 'decided_link', 'prev' => '4711']);
        self::assertStringContainsString('Linked listing #4711.', $known->text());
        $sneaky = $web->get('/ui/review', ['queue' => 'Check', 'notice' => 'decided_link', 'prev' => $evil . '5']);
        self::assertStringContainsString('Linked the listing.', $sneaky->text());
    }

    public function testASessionCookieGoesNowhereItShouldNot(): void
    {
        $web = $this->signIn($this->uiUser('mapper'));
        $r = $web->get('/ui/');
        self::assertSame([], $r->headerValues('set-cookie'), 'no cookie is re-sent on ordinary pages');
        // The session id is never in the page, a link or a form.
        $id = $web->cookies['cw_session'];
        foreach (['/ui/', '/ui/search', '/ui/password'] as $path) {
            self::assertStringNotContainsString($id, $web->get($path)->body, $path);
            self::assertStringNotContainsString(hash('sha256', $id), $web->get($path)->body, $path);
        }
        // Nor is anything secret in an error page for a database that is not there: see UiUnitTest for the 503 page.
        $r = $web->get('/ui/items/1');
        self::assertSame(404, $r->status);
        self::assertStringNotContainsString('SQLSTATE', $r->body);
    }

    /** A second browser is signed in separately and sees only its own session. */
    public function testSessionsAreIndependent(): void
    {
        $a = $this->uiUser('mapper');
        $b = $this->uiUser('mapper');
        $one = $this->signIn($a);
        $two = $this->signIn($b);
        self::assertNotSame($one->cookies['cw_session'], $two->cookies['cw_session']);
        self::assertStringContainsString('Mapper 1', $one->get('/ui/')->text());
        self::assertStringContainsString('Mapper 2', $two->get('/ui/')->text());
        $out = $one->post('/ui/logout', ['csrf' => $this->token($one)]);
        self::assertSame(303, $out->status);
        self::assertSame(200, $two->get('/ui/')->status, "another person's session is not touched by a sign-out");
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE revoked = 0'));
    }
}
