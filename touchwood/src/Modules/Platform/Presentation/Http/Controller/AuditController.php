<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Application\Query\ListAudit\ListAuditHandler;
use Modules\Platform\Presentation\Http\Resource\AuditPages;

/**
 * The audit log (frontend.md 3.5, E6).
 *
 * Who changed what and when, for the stores this person may read. An entry that belongs to no store
 * - a currency, a global setting, a role - needs every store to read: those changes are the
 * system's rather than a shop's.
 *
 * Nothing here writes, and nothing could: the table refuses an UPDATE or a DELETE by trigger,
 * whoever asks (platform.md 1.5).
 */
final readonly class AuditController
{
    /** @var list<string> */
    private const array WORDS = ['platform::admin_audit', 'platform::audit', 'access::audit', 'platform::errors', 'access::errors', 'admin'];

    public function __construct(
        private Page $page,
        private AuditPages $pages,
    ) {}

    /** E6. */
    public function index(Request $request, ListAuditHandler $audit): Response
    {
        $filters = [
            'from' => $this->optional($request, 'from'),
            'until' => $this->optional($request, 'until'),
            'actor' => $this->optional($request, 'actor'),
            'action' => $this->optional($request, 'action'),
            'source' => $this->optional($request, 'source'),
        ];

        $cursorId = $this->optional($request, 'after_id');

        return $this->page->render('Platform/Admin/Audit/Index', $this->pages->list(
            $audit,
            $filters,
            $this->optional($request, 'after_at'),
            $cursorId === null ? null : (int) $cursorId,
        )->toArray(), self::WORDS);
    }

    private function optional(Request $request, string $field): ?string
    {
        $value = trim($request->string($field)->toString());

        return $value === '' ? null : $value;
    }
}
