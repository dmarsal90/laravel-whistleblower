<?php

declare(strict_types=1);

namespace Whistleblower\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Whistleblower\Domain\Report;
use Whistleblower\Enums\ReportStatus;
use Whistleblower\Exceptions\InvalidTransition;
use Whistleblower\Presenter;
use Whistleblower\Whistleblower;

/**
 * Case-management endpoints. Access is decided by the ability in config('whistleblower.ability'),
 * which the host application must define: with no Gate defined, everyone is denied.
 */
final class ManageReportController extends Controller
{
    public function __construct(private readonly Whistleblower $whistleblower) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ReportStatus::class)],
            'overdue' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $this->whistleblower->list(
            isset($filters['status']) ? ReportStatus::from($filters['status']) : null,
            $request->boolean('overdue'),
            (int) ($filters['page'] ?? 1),
        );

        // The list carries no report content or contact data.
        return response()->json([
            'data' => array_map(Presenter::summary(...), $page->items),
            'total' => $page->total,
            'page' => $page->page,
            'per_page' => $page->perPage,
        ]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $found = $this->whistleblower->find($report);
        if ($found === null) {
            return $this->notFound();
        }
        $this->whistleblower->recordView($found, $this->actor($request));

        return response()->json(Presenter::detail($found, $this->whistleblower->messages($found, true), $this->whistleblower->auditTrail($found)));
    }

    public function acknowledge(Request $request, string $report): JsonResponse
    {
        return $this->apply($report, fn (Report $r) => $this->whistleblower->acknowledge($r, $this->actor($request)));
    }

    public function investigate(Request $request, string $report): JsonResponse
    {
        return $this->apply($report, fn (Report $r) => $this->whistleblower->startInvestigation($r, $this->actor($request)));
    }

    public function extend(Request $request, string $report): JsonResponse
    {
        $data = $request->validate(['justification' => ['required', 'string', 'max:5000']]);

        return $this->apply($report, fn (Report $r) => $this->whistleblower->extendDeadline($r, $this->actor($request), $data['justification']));
    }

    public function resolve(Request $request, string $report): JsonResponse
    {
        $data = $request->validate(['summary' => ['required', 'string', 'max:10000']]);

        return $this->apply($report, fn (Report $r) => $this->whistleblower->resolve($r, $this->actor($request), $data['summary']));
    }

    public function archive(Request $request, string $report): JsonResponse
    {
        return $this->apply($report, fn (Report $r) => $this->whistleblower->archive($r, $this->actor($request)));
    }

    public function message(Request $request, string $report): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'internal' => ['nullable', 'boolean']]);
        $found = $this->whistleblower->find($report);
        if ($found === null) {
            return $this->notFound();
        }

        try {
            $this->whistleblower->addMessage($found, 'manager', $this->actor($request), $data['body'], $request->boolean('internal'));
        } catch (InvalidTransition $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['status' => 'sent'], 201);
    }

    /** @param callable(Report): Report $action */
    private function apply(string $id, callable $action): JsonResponse
    {
        $found = $this->whistleblower->find($id);
        if ($found === null) {
            return $this->notFound();
        }

        try {
            return response()->json(Presenter::summary($action($found)));
        } catch (InvalidTransition $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Report not found.'], 404);
    }

    private function actor(Request $request): string|int|null
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) || is_int($id) ? $id : null;
    }
}
