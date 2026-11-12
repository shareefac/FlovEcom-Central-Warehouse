<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Catalogue\ItemNames;
use CW\CwException;
use CW\Db;
use CW\OpResult;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;

/**
 * "Save the name" on the item page (POST /ui/items/{id}/name, catalogue.edit; docs/decisions.md IR1-IR4): the Name part's form sends the new
 * name and the name the page showed (`name_was`); CW\Catalogue\ItemNames renames the item in one transaction (checking the person again) under
 * the form's key (FormOnce: the same form sent twice renames once). A refusal draws the item page again with its words on top, the Name part
 * open, what was typed kept and the same key (a refusal stores nothing); after a 409 the page shows the name the item has now.
 */
final class ItemNameController
{
    public function save(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $name = $ctx->req->field('name') ?? '';
        $was = $ctx->req->field('name_was') ?? '';
        try {
            $r = FormOnce::run($ctx, 'ui.item.rename', ['name' => $name, 'name_was' => $was], static function (Db $db) use ($ctx, $id, $name, $was): OpResult {
                $s = (new ItemNames($db))->rename($ctx->caller(), $id, $was, $name);
                return OpResult::of(303, ['result' => $s['result'], 'redirect' => Html::url('/ui/items/' . $id,
                    ['notice' => $s['result'] === 'unchanged' ? 'name_unchanged' : 'name_saved'])]);
            });
        } catch (CwException $e) {
            $key = in_array($e->errorCode, ['bad_form_key', 'idempotency_key_reused'], true) ? FormOnce::newKey() : ($ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey());
            return (new ItemController())->page($ctx, $e->httpStatus, $e, null, ['rename' => ['name' => $name, 'form_key' => $key]]);
        }
        return FormOnce::redirect($r);
    }
}
