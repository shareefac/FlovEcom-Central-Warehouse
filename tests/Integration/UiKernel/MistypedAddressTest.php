<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * A mistyped address while signed in (seen 2 Nov 2026), through the real /ui kernel as cw_app: the "not found" page comes in the
 * person's own frame, with the menu and Back to Home, never "Back to sign in"; the wrong method keeps its Allow header; a cookie whose
 * session has ended gets the plain page and is cleared. Without a session cookie nothing changed: UiUnitTest shows the database is
 * never touched.
 */
final class MistypedAddressTest extends KernelUiTestCase
{
    public function testASignedInPersonKeepsTheirFrame(): void
    {
        $web = $this->signIn($this->uiUser('buyer'));
        $nf = $web->get('/ui/nothing-here');
        self::assertSame(404, $nf->status, $nf->describe());
        self::assertStringContainsString(Words::ERROR['not_found'], $nf->body);
        self::assertStringContainsString(Words::UI['back_home'], $nf->body);
        self::assertStringNotContainsString(Words::UI['back_sign_in'], $nf->body);
        self::assertArrayHasKey('Home', self::nav($nf), 'the menu is drawn');
        self::assertArrayHasKey('cw_session', $web->cookies, 'still signed in');
        self::assertSame(200, $web->get('/ui/')->status);

        $m = $web->send('DELETE', '/ui/login', [], []);
        self::assertSame(405, $m->status, $m->describe());
        self::assertStringContainsString(Words::UI['back_home'], $m->body);
        self::assertStringNotContainsString(Words::UI['back_sign_in'], $m->body);
        // The browser keeps only the headers the screens use; the kernel's own answer carries Allow.
        $raw = $this->kernel()->handle(new UiRequest('DELETE', '/ui/login', [], [], $web->cookies, ['host' => 'cw-ui.review.invalid'], $web->ip, false, []));
        self::assertSame('GET, POST', $raw->header('Allow'));
    }

    public function testACookieWithoutASessionGetsThePlainPage(): void
    {
        $web = $this->browser();
        $web->cookies['cw_session'] = str_repeat('A', 43);
        $nf = $web->get('/ui/nothing-here');
        self::assertSame(404, $nf->status, $nf->describe());
        self::assertStringContainsString(Words::UI['back_sign_in'], $nf->body);
        self::assertStringNotContainsString(Words::UI['back_home'], $nf->body);
        self::assertSame([], self::nav($nf), 'no menu for nobody');
        self::assertArrayNotHasKey('cw_session', $web->cookies, 'the dead cookie is cleared');
    }
}
