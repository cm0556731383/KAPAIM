<?php

namespace App\Exceptions;

use App\Support\PagePermissions;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A write attempted from a page the user may only view (ReadOnlyGuard). */
class EditNotAllowedException extends HttpException
{
    public function __construct(public readonly string $page, ?string $message = null)
    {
        parent::__construct(403, $message ?? 'אין לך הרשאת עריכה בדף "'.PagePermissions::label($page).'" — ניתן רק לצפות בו.');
    }
}
