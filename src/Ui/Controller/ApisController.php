<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Admin\ApiCredentials;
use CW\Config;
use CW\ConfigException;
use CW\CwException;
use CW\Db;
use CW\Invoices\ErpApiReader;
use CW\Invoices\ErpInvoiceCopy;
use CW\Invoices\ErpTransport;
use CW\OpResult;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Settings › APIs (the owner, 5 Nov 2026: "on cw give me a section called apis and add a section there to add the key i will add there and
 * hit submit"; docs/decisions.md AK1-AK12): one card per API CW calls, today "ERPNext (read only)", the user of the invoice copy (CW-917).
 *
 * Who looks (system.view, as Settings › Stores): which key is in use (saved here, set on the server, none), the address, who saved it and
 * when, the key's last 4 characters, that a secret is saved; NEVER the key or the secret, whoever looks. Who changes it (settings.manage:
 * an Admin or a Reviewer, re-checked inside the service's transaction): Save (address, key, secret; the version the form was drawn with
 * and a FormOnce key: sent twice, one save), "Check the connection" (ONE read-only GET through Invoices\ErpApiReader::check(), the answer in
 * a fixed word, audited) and Remove (a fold with a tick: the confirm step is in the page, no script). A refused form comes back with the
 * address as typed and never the key or the secret. Saving a key never switches the copy on (erp_copy.on is changed on its setting only).
 */
final class ApisController
{
    public const PATH = '/ui/system/apis';
    /** "Check the connection" waits at most this long for ERPNext (the copy job's reads wait ErpApiReader::TIMEOUT). */
    public const CHECK_TIMEOUT = 10;

    /**
     * @param ErpTransport|null $transport how the check reaches ERPNext (null: the real one, public only for a saved address; tests: fixtures)
     * @param (\Closure(): Config)|null $config where app.env's key is read from (null: Config::loadApp(); tests: their own)
     */
    public function __construct(private readonly ?ErpTransport $transport = null, private readonly ?\Closure $config = null)
    {
    }

    public function show(Context $ctx): HtmlResponse
    {
        $notice = Words::APIS_NOTICE[$ctx->req->param('notice') ?? ''] ?? null;
        return $this->page($ctx, 200, null, null, $notice, self::checked($ctx->req->param('checked'), $ctx->req->param('code')));
    }

    public function save(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $url = (string) ($req->field('url') ?? '');
        $key = (string) ($req->field('api_key') ?? '');
        $secret = (string) ($req->field('api_secret') ?? '');
        $version = self::version($req->field('version'));
        if ($version === null) {
            return $this->page($ctx, 400, new CwException('bad_version', Words::APIS_ERROR['bad_version'], 400), $url);
        }
        // What FormOnce keeps of this form: the address, the version and a keyed fingerprint of the key and the secret (the same form sent
        // twice is one save; the same form with another key is refused), never the key or the secret themselves (AK2).
        $request = ['name' => ApiCredentials::ERP_READ, 'url' => trim($url), 'version' => (string) $version,
            'credential' => $ctx->csrf->forForm('api.' . ApiCredentials::ERP_READ, trim($key) . "\n" . trim($secret))];
        try {
            $config = $this->config();
            $r = FormOnce::run($ctx, 'ui.apis.save', $request, static function (Db $db) use ($ctx, $version, $url, $key, $secret, $config): OpResult {
                $s = (new ApiCredentials($db))->save($ctx->caller(), ApiCredentials::ERP_READ, $version, $url, $key, $secret, $ctx->secretBox(), $config);
                return OpResult::of(303, ['result' => $s['result'], 'version' => $s['version'], 'redirect' => Html::url(self::PATH, ['notice' => $s['result']])]);
            });
        } catch (CwException $e) {
            if ($e->errorCode === 'idempotency_key_reused') {
                // Saved already, and sent again with another key or address (the back button): a fresh form, so the next Save works.
                return $this->page($ctx, 409, new CwException('saved_twice', Words::APIS_ERROR['saved_twice'], 409), $url);
            }
            return $this->page($ctx, $e->httpStatus, $e, $url);
        }
        return FormOnce::redirect($r);
    }

    public function remove(Context $ctx): HtmlResponse
    {
        $version = self::version($ctx->req->field('version'));
        if ($version === null) {
            return $this->page($ctx, 400, new CwException('bad_version', Words::APIS_ERROR['bad_version'], 400));
        }
        if ($ctx->req->field('confirm') !== '1') {
            return $this->page($ctx, 422, new CwException('api_confirm', Words::APIS_ERROR['api_confirm'], 422));
        }
        try {
            $r = FormOnce::run($ctx, 'ui.apis.remove', ['name' => ApiCredentials::ERP_READ, 'version' => (string) $version, 'confirm' => '1'],
                static function (Db $db) use ($ctx, $version): OpResult {
                    $s = (new ApiCredentials($db))->remove($ctx->caller(), ApiCredentials::ERP_READ, $version);
                    return OpResult::of(303, ['result' => $s['result'], 'version' => $s['version'],
                        'redirect' => Html::url(self::PATH, ['notice' => $s['result'] === 'removed' ? 'removed' : 'unchanged'])]);
                });
        } catch (CwException $e) {
            return $this->page($ctx, $e->httpStatus === 422 && $e->errorCode === 'idempotency_key_reused' ? 409 : $e->httpStatus, $e);
        }
        return FormOnce::redirect($r);
    }

    /**
     * "Check the connection" (AK8): the key the copy job would use (saved here, else app.env's), ONE read through ErpApiReader::check(),
     * at most CHECK_TIMEOUT seconds, outside any transaction; its audit row; then the page with the answer in a fixed word (PRG). Nothing
     * of ERPNext's answer, the key or the secret is shown, logged or audited.
     */
    public function check(Context $ctx): HtmlResponse
    {
        $creds = new ApiCredentials($ctx->db);
        $status = null;
        $reader = null;
        try {
            $reader = ErpApiReader::fromConfig($this->config(), $this->transport, $ctx->db, $ctx->secretBox(), self::CHECK_TIMEOUT);
            ['outcome' => $outcome, 'status' => $status] = $reader->check();
            $source = $reader->source() === 'saved' ? 'saved' : 'app_env';
        } catch (CwException $e) {
            [$outcome, $source] = match ($e->errorCode) {
                'erp_read_unconfigured' => ['nothing', 'none'],
                'api_cannot_open' => ['cannot_open', 'saved'],
                'api_host_not_allowed' => ['host_not_allowed', 'saved'],
                'erp_read_bad_url', 'erp_read_bad_key' => ['bad_setup', 'app_env'],
                default => throw $e,
            };
        }
        $creds->recordCheck($ctx->caller(), ApiCredentials::ERP_READ, $source, $outcome, $status, $reader?->base(), $reader?->keyLast4());
        return HtmlResponse::redirect(Html::url(self::PATH, ['checked' => $outcome] + ($status !== null ? ['code' => (string) $status] : [])) . '#erp');
    }

    /** The answer of a check named in the address (a fixed word only), or null. @return array{outcome: string, text: string, tone: string}|null */
    private static function checked(?string $outcome, ?string $code): ?array
    {
        if ($outcome === null || !in_array($outcome, ApiCredentials::OUTCOMES, true)) {
            return null;
        }
        $text = Words::APIS_CHECK[$outcome];
        if ($outcome === 'other') {
            $text = $code !== null && preg_match('/^[1-5][0-9]{2}$/D', $code) === 1 ? Words::say('APIS_CHECK', 'other', $code) : Words::APIS_CHECK['other_no_code'];
        }
        return ['outcome' => $outcome, 'text' => $text, 'tone' => $outcome === 'ok' ? 'done' : 'blocked'];
    }

    /**
     * The page. $typedUrl: the address a refused form sent (kept); the key and the secret never come back.
     *
     * @param array{outcome: string, text: string, tone: string}|null $check
     */
    private function page(Context $ctx, int $status, ?CwException $error, ?string $typedUrl = null, ?string $notice = null, ?array $check = null): HtmlResponse
    {
        $creds = new ApiCredentials($ctx->db);
        $c = $creds->shown(ApiCredentials::ERP_READ);
        $env = $this->config();
        $inEnv = true;
        foreach (ErpApiReader::CONFIG as $name) {
            $inEnv = $inEnv && trim((string) ($env->get($name) ?? '')) !== '';
        }
        $canEdit = $ctx->me()->can('settings.manage');
        $who = $c['updated_by_name'] ?? Words::APIS['someone'];
        $changed = $c['version'] === 0 ? null : Words::say('APIS', $c['saved'] ? 'saved_by' : 'removed_by', $who, Html::when($c['updated_at']));
        return $ctx->page('apis', [
            'saved' => $c['saved'],
            'inUse' => $c['saved'] ? 'saved' : ($inEnv ? 'app_env' : 'none'),
            'shownUrl' => $c['url'],
            'keyLast4' => $c['key_last4'],
            'changed' => $changed,
            'url' => $typedUrl ?? $c['url'] ?? ApiCredentials::DEFAULT_URL[ApiCredentials::ERP_READ],
            'version' => $c['version'],
            'formKey' => FormOnce::newKey(),
            'removeKey' => FormOnce::newKey(),
            'canEdit' => $canEdit,
            'copyOn' => $ctx->settings()->has(ErpInvoiceCopy::SWITCH) && $ctx->settings()->get(ErpInvoiceCopy::SWITCH) === true,
            'copySetting' => Html::url('/ui/reference/settings/setting', ['key' => ErpInvoiceCopy::SWITCH]),
            'check' => $check,
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
            'retype' => $error !== null && $typedUrl !== null,
            'lookOnly' => $canEdit ? null : Words::APIS['look_only'],
        ], $status, ['title' => Words::APIS['title'], 'active' => 'apis', 'notice' => $notice]);
    }

    /** A refusal in the page's words, by its code; another code keeps the service's words (Words::error). */
    private static function plain(CwException $e): string
    {
        if ($e->errorCode === 'api_changed') {
            return Words::say('APIS_ERROR', 'api_changed', is_string($e->detail['by'] ?? null) ? $e->detail['by'] : Words::APIS['someone'],
                Html::when(is_string($e->detail['at'] ?? null) ? $e->detail['at'] : null));
        }
        return Words::APIS_ERROR[$e->errorCode] ?? Words::error($e->errorCode, $e->getMessage());
    }

    /** app.env as the web service reads it; an app.env this worker cannot read has no ERPNext key (the page still opens). */
    private function config(): Config
    {
        try {
            return $this->config !== null ? ($this->config)() : Config::loadApp();
        } catch (ConfigException) {
            return Config::fromArrays([]);
        }
    }

    private static function version(?string $v): ?int
    {
        return $v !== null && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $v) === 1 ? (int) $v : null;
    }
}
