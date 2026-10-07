<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Auth\Csrf;
use CW\Auth\Permissions;
use CW\Auth\StaffIdentity;
use CW\Caller;
use CW\Company\CompanyDetails;
use CW\Config;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Mapping\DecisionService;
use CW\Settings;
use CW\Staff\SecretBox;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;

/**
 * Everything a controller needs for one /ui request: the request, the database, the signed-in
 * person (null only on public routes), CSRF tokens, the renderer and the route's `{id}` values.
 */
final class Context
{
    private ?Queries $queries = null;
    private ?DecisionService $decisions = null;
    private ?Documents $documents = null;
    private ?FileStore $files = null;
    private ?Settings $settings = null;
    private ?Suppliers $suppliers = null;
    private ?SupplierItems $supplierItems = null;
    private ?CompanyDetails $company = null;

    /**
     * @param array<string, string> $params route parameters
     * @param string|null $pre the login form's pre-session cookie value (public routes)
     * @param (\Closure(Db): array<string, \CW\Documents\DocumentHandler>)|null $handlers the live document types (Kernel)
     * @param (\Closure(string): void)|null $log the kernel's error log (file integrity failures land there with the request id)
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
        private readonly ?\Closure $handlers = null,
        private readonly ?\Closure $log = null,
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

    /** The document base with the live document types (none in I-1: DocumentHandlers::all). */
    public function documents(): Documents
    {
        return $this->documents ??= new Documents($this->db, $this->handlers !== null ? ($this->handlers)($this->db) : DocumentHandlers::all($this->db));
    }

    /** CW's settings (app_setting), read once per request. */
    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->db);
    }

    /** Suppliers and their approvals (IM4). */
    public function suppliers(): Suppliers
    {
        return $this->suppliers ??= new Suppliers($this->db, $this->settings());
    }

    /** Supplier items and prices (IM4). */
    public function supplierItems(): SupplierItems
    {
        return $this->supplierItems ??= new SupplierItems($this->db);
    }

    /** The company details printed on POs (company_profile, I90-I99). */
    public function company(): CompanyDetails
    {
        return $this->company ??= new CompanyDetails($this->db);
    }

    /** The file store app.env names (file_store_dir / CW_FILE_STORE_DIR); 503 file_store_unconfigured without one. */
    public function files(): FileStore
    {
        $log = $this->log;
        $rid = $this->rid;
        return $this->files ??= FileStore::fromConfig(Config::loadApp(), $this->db, static function (string $m) use ($log, $rid): void {
            if ($log !== null) {
                $log("{$rid} {$m}");
            } else {
                error_log("[cw-ui] {$rid} {$m}");
            }
        });
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
     * A page in the layout. The layout gets the person's menu (Permissions::menu of the roles read for this
     * request, I14), the badge counts their menu shows (only those they may see: the second-approval count needs
     * linking.view, the review count documents.review) and whether the quick search box is theirs (catalogue.view).
     *
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $layout title, active (nav key), notice ...
     */
    public function page(string $template, array $vars, int $status = 200, array $layout = []): HtmlResponse
    {
        $token = $this->token();
        $shared = ['csrf' => $token, 'who' => $this->who];
        $view = new View(View::defaultDir(), $shared);
        $html = $view->page($template, $vars, $layout + ['title' => 'Central Warehouse', 'active' => '', 'notice' => null,
            'menu' => $this->menu(), 'badges' => $this->badges(), 'searchBox' => $this->who?->can('catalogue.view') ?? false]);
        return new HtmlResponse($status, $html);
    }

    /** @return list<array{section: string, items: list<array<string, mixed>>}> the signed-in person's menu ([] when nobody) */
    public function menu(): array
    {
        return $this->who === null ? [] : Permissions::menu($this->who->roles);
    }

    /**
     * @return array<string, int> badge name (Permissions::MENU `badge`) => count, for what the person may see:
     *         linking_pending (decisions waiting for a second approval), linking_duplicates (open duplicate groups, M34),
     *         reviews_open (open review and approval tasks this
     *         person may decide: not opened by them, not on a document they created, submitted or posted, I19; plus the
     *         open supplier tasks they may decide: not on a supplier they created, asked for or last changed, I40; plus the
     *         open reviews of a change of the company details they did not make, I94)
     */
    public function badges(): array
    {
        $out = [];
        if ($this->who !== null && $this->who->can('linking.view')) {
            $out['linking_pending'] = $this->queries()->pendingCount();
            $out['linking_duplicates'] = (new Duplicates($this->db))->openCount();
        }
        if ($this->who !== null && ($this->who->can('documents.review') || $this->who->can('suppliers.approve') || $this->who->can('company.confirm'))) {
            // Documents, suppliers and the company details share the review queue (I40, I94): the open tasks this person may decide.
            $out['reviews_open'] = $this->documents()->decidableCount($this->who->id, $this->who->roles)
                + $this->suppliers()->decidableCount($this->who->id, $this->who->roles)
                + $this->company()->decidableCount($this->who->id, $this->who->roles);
        }
        return $out;
    }

    /** An error page (with the navigation when signed in). */
    public function error(int $status, string $code, string $message): HtmlResponse
    {
        return $this->page('error', ['status' => $status, 'code' => $code, 'message' => $message, 'rid' => $this->rid],
            $status, ['title' => 'Error ' . $status]);
    }
}
