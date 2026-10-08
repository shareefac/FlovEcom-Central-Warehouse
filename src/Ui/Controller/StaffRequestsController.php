<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Staff\RoleRequests;
use CW\Ui\Context;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Staff access to OK (docs/decisions.md Y25): while the owner has the staff-grant rule on (approvals.staff_grant, the Approval
 * rules page; off by default), an admin's grant of Admin or Reviewer waits here until a reviewer who neither asked for it nor is the
 * person says OK or Not OK (staff.approve; Staff\RoleRequests checks it all again). Home's card and the Approval rules page link
 * here; it has no menu item (empty while the rule is off).
 */
final class StaffRequestsController
{
    public const NOTICES = ['approved' => Words::STAFF_NOTICE['request_approved'], 'rejected' => Words::STAFF_NOTICE['request_rejected'],
        'stale' => Words::STAFF_NOTICE['request_stale']];

    public function index(Context $ctx): HtmlResponse
    {
        return $this->page($ctx, 200, null, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    public function approve(Context $ctx): HtmlResponse
    {
        return $this->decide($ctx, true);
    }

    public function reject(Context $ctx): HtmlResponse
    {
        return $this->decide($ctx, false);
    }

    private function decide(Context $ctx, bool $approve): HtmlResponse
    {
        $id = $ctx->id();
        try {
            $r = (new RoleRequests($ctx->db))->decide($ctx->caller(), $id, $approve, $ctx->req->field('note'));
        } catch (CwException $e) {
            return $this->page($ctx, $e->httpStatus, $id, $e);
        }
        return HtmlResponse::redirect('/ui/staff-requests?notice=' . $r['result']);
    }

    private function page(Context $ctx, int $status, ?int $errorId, ?CwException $error, ?string $notice = null): HtmlResponse
    {
        $rows = [];
        foreach ((new RoleRequests($ctx->db))->pending($ctx->me()->id) as $r) {
            $rows[] = $r + ['error' => $errorId === $r['id'] && $error !== null
                ? (Words::STAFF_REQUESTS[$error->errorCode] ?? Words::error($error->errorCode, $error->getMessage())) : null];
        }
        $general = $error !== null && !in_array($errorId, array_column($rows, 'id'), true)
            ? (Words::STAFF_REQUESTS[$error->errorCode] ?? Words::error($error->errorCode, $error->getMessage())) : null;
        return $ctx->page('staff_requests', ['requests' => $rows, 'error' => $general], $status,
            ['title' => Words::title('staff_requests'), 'active' => 'staff_requests', 'notice' => $notice]);
    }
}
