<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Auth\Csrf;
use CW\Auth\StaffIdentity;
use CW\Caller;
use CW\Db;
use CW\Mapping\DecisionService;
use CW\Staff\SecretBox;

/**
 * Everything a controller needs for one /ui request: the request, the database, the signed-in
 * person (null only on public routes), CSRF tokens, the renderer and the route's `{id}` values.
 */
final class Context
{
    private ?Queries $queries = null;
    private ?DecisionService $decisions = null;

    /**
     * @param array<string, string> $params route parameters
     * @param string|null $pre the login form's pre-session cookie value (public routes)
     */
    public function __construct(
        public readonly UiRequest $req,
        public readonly Db $db,
        public readonly ?StaffIdentity $who,
        public readonly Csrf $csrf,
        public readonly array $params,
        public readonly string $rid,
        public readonly ?string $pre = null,
        #[\SensitiveParameter] private readonly string $secretKey = '',
    ) {
    }

    /** The box that seals TOTP secrets (app.env ui_secret_key). */
    public function secretBox(): SecretBox
    {
        return SecretBox::fromBase64($this->secretKey);
    }

    public function me(): StaffIdentity
    {
        return $this->who ?? throw new \LogicException('this route needs a signed-in person');
    }

    public function caller(): Caller
    {
        return Caller::staff($this->me()->id, $this->req->ip);
    }

    public function queries(): Queries
    {
        return $this->queries ??= new Queries($this->db);
    }

    public function decisions(): DecisionService
    {
        return $this->decisions ??= new DecisionService($this->db);
    }

    public function id(string $name = 'id'): int
    {
        return (int) ($this->params[$name] ?? 0);
    }

    /** The CSRF token for the forms of this page (bound to the session, or to the login form's cookie). */
    public function token(): string
    {
        if ($this->who !== null) {
            return $this->csrf->forSession($this->who->sessionId);
        }
        return $this->pre !== null ? $this->csrf->forPre($this->pre) : '';
    }

    /**
     * A page in the layout.
     *
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $layout title, active (nav key), notice ...
     */
    public function page(string $template, array $vars, int $status = 200, array $layout = []): HtmlResponse
    {
        $token = $this->token();
        $shared = ['csrf' => $token, 'who' => $this->who];
        $view = new View(View::defaultDir(), $shared);
        $pending = $this->who !== null ? $this->queries()->pendingCount() : null;
        $html = $view->page($template, $vars, $layout + ['title' => 'Central Warehouse', 'active' => '', 'notice' => null, 'pendingCount' => $pending]);
        return new HtmlResponse($status, $html);
    }

    /** An error page (with the navigation when signed in). */
    public function error(int $status, string $code, string $message): HtmlResponse
    {
        return $this->page('error', ['status' => $status, 'code' => $code, 'message' => $message, 'rid' => $this->rid],
            $status, ['title' => 'Error ' . $status]);
    }
}
