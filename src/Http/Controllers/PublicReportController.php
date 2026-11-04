<?php

declare(strict_types=1);

namespace Whistleblower\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Whistleblower\Exceptions\InvalidTransition;
use Whistleblower\Models\Report;
use Whistleblower\Whistleblower;

/**
 * Anonymous endpoints. The tracking code travels in the request body, never in the URL,
 * so it does not end up in access logs or Referer headers.
 */
final class PublicReportController extends Controller
{
    public function __construct(private readonly Whistleblower $whistleblower) {}

    public function store(Request $request): JsonResponse
    {
        $categories = array_values(array_filter((array) config('whistleblower.categories'), is_string(...)));
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
            'category' => ['nullable', 'string', 'max:100', ...($categories === [] ? [] : [Rule::in($categories)])],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:254'],
        ]);

        $submission = $this->whistleblower->submit($data);

        return $this->noStore(response()->json([
            'tracking_code' => $submission->trackingCode,
            'received_at' => $submission->report->received_at->toIso8601String(),
            'acknowledgement_due_at' => $submission->report->acknowledgement_due_at->toIso8601String(),
        ], 201));
    }

    public function status(Request $request): JsonResponse
    {
        $report = $this->lookup($request);
        if ($report === null) {
            return $this->notFound();
        }

        return $this->noStore(response()->json([
            'status' => $report->status->value,
            'received_at' => $report->received_at->toIso8601String(),
            'acknowledged_at' => $report->acknowledged_at?->toIso8601String(),
            'resolution_due_at' => $report->resolution_due_at->toIso8601String(),
            'resolution' => $report->resolution,
            'messages' => $report->messages()->where('author', '!=', 'internal')->get()
                ->map(fn ($m): array => ['author' => $m->author, 'body' => $m->body, 'created_at' => $m->created_at->toIso8601String()])
                ->all(),
        ]));
    }

    public function message(Request $request): JsonResponse
    {
        $report = $this->lookup($request);
        if ($report === null) {
            return $this->notFound();
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        try {
            $this->whistleblower->addMessage($report, 'reporter', null, $data['body']);
        } catch (InvalidTransition $e) {
            return $this->noStore(response()->json(['message' => $e->getMessage()], 409));
        }

        return $this->noStore(response()->json(['status' => $report->status->value], 201));
    }

    private function lookup(Request $request): ?Report
    {
        $data = $request->validate(['tracking_code' => ['required', 'string', 'max:40']]);

        return $this->whistleblower->findByCode($data['tracking_code']);
    }

    private function notFound(): JsonResponse
    {
        return $this->noStore(response()->json(['message' => __('whistleblower::messages.not_found')], 404));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store');
    }
}
