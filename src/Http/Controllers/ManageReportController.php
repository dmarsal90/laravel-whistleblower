<?php

declare(strict_types=1);

namespace Whistleblower\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Whistleblower\Enums\ReportStatus;
use Whistleblower\Exceptions\InvalidTransition;
use Whistleblower\Models\Report;
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
        ]);

        $query = Report::query()->latest('received_at');
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if ($request->boolean('overdue')) {
            $now = Carbon::now();
            $query->whereIn('status', [ReportStatus::Received->value, ReportStatus::Acknowledged->value, ReportStatus::Investigating->value])
                ->where(fn ($q) => $q->where('resolution_due_at', '<', $now)
                    ->orWhere(fn ($q) => $q->whereNull('acknowledged_at')->where('acknowledgement_due_at', '<', $now)));
        }

        // The list carries no report content or contact data.
        $page = $query->paginate(25);
        $page->through(fn (Report $r): array => $this->summary($r));

        return response()->json($page);
    }

    public function show(Request $request, Report $report): JsonResponse
    {
        $this->whistleblower->recordView($report, $this->actor($request));

        return response()->json($this->summary($report) + [
            'subject' => $report->subject,
            'description' => $report->description,
            'reporter_name' => $report->reporter_name,
            'reporter_email' => $report->reporter_email,
            'resolution' => $report->resolution,
            'messages' => $report->messages()->get()->map(fn ($m): array => [
                'author' => $m->author, 'user_id' => $m->user_id, 'body' => $m->body, 'created_at' => $m->created_at->toIso8601String(),
            ])->all(),
            'audit' => $report->auditLogs()->get()->map(fn ($a): array => [
                'actor_type' => $a->actor_type, 'actor_id' => $a->actor_id, 'action' => $a->action, 'created_at' => $a->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function acknowledge(Request $request, Report $report): JsonResponse
    {
        return $this->apply(fn () => $this->whistleblower->acknowledge($report, $this->actor($request)));
    }

    public function investigate(Request $request, Report $report): JsonResponse
    {
        return $this->apply(fn () => $this->whistleblower->startInvestigation($report, $this->actor($request)));
    }

    public function extend(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate(['justification' => ['required', 'string', 'max:5000']]);

        return $this->apply(fn () => $this->whistleblower->extendDeadline($report, $this->actor($request), $data['justification']));
    }

    public function resolve(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate(['summary' => ['required', 'string', 'max:10000']]);

        return $this->apply(fn () => $this->whistleblower->resolve($report, $this->actor($request), $data['summary']));
    }

    public function archive(Request $request, Report $report): JsonResponse
    {
        return $this->apply(fn () => $this->whistleblower->archive($report, $this->actor($request)));
    }

    public function message(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'internal' => ['nullable', 'boolean']]);

        try {
            $this->whistleblower->addMessage($report, 'manager', $this->actor($request), $data['body'], $request->boolean('internal'));
        } catch (InvalidTransition $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['status' => 'sent'], 201);
    }

    /** @param callable(): Report $action */
    private function apply(callable $action): JsonResponse
    {
        try {
            return response()->json($this->summary($action()));
        } catch (InvalidTransition $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    private function actor(Request $request): string|int|null
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) || is_int($id) ? $id : null;
    }

    /** @return array<string, mixed> */
    private function summary(Report $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status->value,
            'anonymous' => $r->anonymous,
            'received_at' => $r->received_at->toIso8601String(),
            'acknowledgement_due_at' => $r->acknowledgement_due_at->toIso8601String(),
            'acknowledged_at' => $r->acknowledged_at?->toIso8601String(),
            'resolution_due_at' => $r->resolution_due_at->toIso8601String(),
            'extended' => $r->extended_at !== null,
            'anonymized' => $r->anonymized_at !== null,
        ];
    }
}
