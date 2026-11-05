<?php

declare(strict_types=1);

namespace Whistleblower\Symfony\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Whistleblower\Domain\Report;
use Whistleblower\Exceptions\InvalidTransition;
use Whistleblower\Messages;
use Whistleblower\Presenter;
use Whistleblower\Symfony\EarlyResponse;
use Whistleblower\Symfony\PayloadValidator;
use Whistleblower\Whistleblower;

/**
 * Anonymous endpoints. The tracking code travels in the request body, never in the URL,
 * so it does not end up in access logs or Referer headers.
 */
final readonly class PublicReportController
{
    /** @param list<string> $categories */
    public function __construct(
        private Whistleblower $whistleblower,
        private PayloadValidator $validator,
        private RateLimiterFactory $limiter,
        private array $categories,
        private string $locale,
    ) {}

    #[Route('/reports', name: 'whistleblower_reports_store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        try {
            $this->throttle($request);
            $data = $this->validator->validated($request, new Assert\Collection(allowExtraFields: true, fields: [
                'subject' => new Assert\Required([new Assert\NotBlank, new Assert\Type('string'), new Assert\Length(max: 200)]),
                'description' => new Assert\Required([new Assert\NotBlank, new Assert\Type('string'), new Assert\Length(max: 10000)]),
                'category' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 100), ...($this->categories === [] ? [] : [new Assert\Choice(choices: $this->categories)])]),
                'contact_name' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 150)]),
                'contact_email' => new Assert\Optional([new Assert\Type('string'), new Assert\Email, new Assert\Length(max: 254)]),
            ]));
        } catch (EarlyResponse $e) {
            return $e->response;
        }

        $submission = $this->whistleblower->submit([
            'subject' => (string) $data['subject'],
            'description' => (string) $data['description'],
            'category' => isset($data['category']) ? (string) $data['category'] : null,
            'contact_name' => isset($data['contact_name']) ? (string) $data['contact_name'] : null,
            'contact_email' => isset($data['contact_email']) ? (string) $data['contact_email'] : null,
        ]);

        return $this->json([
            'tracking_code' => $submission->trackingCode,
            'received_at' => $submission->report->receivedAt->format(DATE_ATOM),
            'acknowledgement_due_at' => $submission->report->acknowledgementDueAt->format(DATE_ATOM),
        ], 201);
    }

    #[Route('/reports/status', name: 'whistleblower_reports_status', methods: ['POST'])]
    public function status(Request $request): Response
    {
        try {
            $report = $this->lookup($request);
        } catch (EarlyResponse $e) {
            return $e->response;
        }

        return $this->json(Presenter::followUp($report, $this->whistleblower->messages($report, false)));
    }

    #[Route('/reports/messages', name: 'whistleblower_reports_message', methods: ['POST'])]
    public function message(Request $request): Response
    {
        try {
            $report = $this->lookup($request, [
                'body' => new Assert\Required([new Assert\NotBlank, new Assert\Type('string'), new Assert\Length(max: 5000)]),
            ], $data);
            $this->whistleblower->addMessage($report, 'reporter', null, (string) $data['body']);
        } catch (EarlyResponse $e) {
            return $e->response;
        } catch (InvalidTransition $e) {
            return $this->json(['message' => $e->getMessage()], 409);
        }

        return $this->json(['status' => $report->status->value], 201);
    }

    /**
     * @param  array<string, Assert\Required|Assert\Optional>  $extraFields
     * @param  array<string, mixed>|null  $data
     *
     * @param-out array<string, mixed> $data
     *
     * @throws EarlyResponse
     */
    private function lookup(Request $request, array $extraFields = [], ?array &$data = null): Report
    {
        $this->throttle($request);
        $data = $this->validator->validated($request, new Assert\Collection(allowExtraFields: true, fields: $extraFields + [
            'tracking_code' => new Assert\Required([new Assert\NotBlank, new Assert\Type('string'), new Assert\Length(max: 40)]),
        ]));

        return $this->whistleblower->findByCode((string) $data['tracking_code'])
            ?? throw new EarlyResponse($this->json(['message' => Messages::get($this->locale, 'not_found')], 404));
    }

    /** @throws EarlyResponse */
    private function throttle(Request $request): void
    {
        $limit = $this->limiter->create((string) $request->getClientIp())->consume();
        if (! $limit->isAccepted()) {
            $response = $this->json(['message' => 'Too many requests.'], 429);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));
            throw new EarlyResponse($response);
        }
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
