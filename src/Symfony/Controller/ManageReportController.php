<?php

declare(strict_types=1);

namespace Whistleblower\Symfony\Controller;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Whistleblower\Domain\Report;
use Whistleblower\Enums\ReportStatus;
use Whistleblower\Exceptions\InvalidTransition;
use Whistleblower\Presenter;
use Whistleblower\Symfony\EarlyResponse;
use Whistleblower\Symfony\PayloadValidator;
use Whistleblower\Whistleblower;

/**
 * Case-management endpoints. Access is decided by the attribute in the `ability` option, which the
 * host application must grant through a voter or a role: with nothing granting it, everyone is denied.
 */
final readonly class ManageReportController
{
    public function __construct(
        private Whistleblower $whistleblower,
        private PayloadValidator $validator,
        private Security $security,
        private string $ability,
    ) {}

    #[Route('/manage/reports', name: 'whistleblower_manage_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        try {
            $this->authorize();
            $status = $request->query->get('status');
            $page = $request->query->get('page', '1');
            $errors = [];
            if ($status !== null && ReportStatus::tryFrom((string) $status) === null) {
                $errors['status'][] = 'The selected status is invalid.';
            }
            if (! is_numeric($page) || (int) $page < 1) {
                $errors['page'][] = 'The page must be a positive integer.';
            }
            if ($errors !== []) {
                return new JsonResponse(['message' => 'The given data was invalid.', 'errors' => $errors], 422);
            }
        } catch (EarlyResponse $e) {
            return $e->response;
        }

        $result = $this->whistleblower->list(
            $status === null ? null : ReportStatus::from((string) $status),
            $request->query->getBoolean('overdue'),
            (int) $page,
        );

        // The list carries no report content or contact data.
        return new JsonResponse([
            'data' => array_map(Presenter::summary(...), $result->items),
            'total' => $result->total,
            'page' => $result->page,
            'per_page' => $result->perPage,
        ]);
    }

    #[Route('/manage/reports/{id}', name: 'whistleblower_manage_show', methods: ['GET'], requirements: ['id' => '[0-9A-Za-z]{26}'])]
    public function show(string $id): Response
    {
        try {
            $this->authorize();
            $report = $this->find($id);
        } catch (EarlyResponse $e) {
            return $e->response;
        }
        $this->whistleblower->recordView($report, $this->actor());

        return new JsonResponse(Presenter::detail($report, $this->whistleblower->messages($report, true), $this->whistleblower->auditTrail($report)));
    }

    #[Route('/manage/reports/{id}/acknowledge', name: 'whistleblower_manage_acknowledge', methods: ['POST'], requirements: ['id' => '[0-9A-Za-z]{26}'])]
    public function acknowledge(string $id): Response
    {
        return $this->apply($id, fn (Report $r) => $this->whistleblower->acknowledge($r, $this->actor()));
    }

    #[Route('/manage/reports/{id}/investigate', name: 'whistleblower_manage_investigate', methods: ['POST'], requirements: ['id' => '[0-9A-Za-z]{26}'])]
    public function investigate(string $id): Response
    {
        return $this->apply($id, fn (Report $r) => $this->whistleblower->startInvestigation($r, $this->actor()));
    }

    #[Route('/manage/reports/{id}/extend', name: 'whistleblower_manage_extend', methods: ['POST'], requirements: ['id' => '[0-9A-Za-z]{26}'])]
    public function extend(Request $request, string $id): Response
    {
        return $this->apply($id, fn (Report $r) => $this->whistleblower->extendDeadline($r, $this->actor(), (string) $this->input($request, 'justification', 5000)['justification']));
    }

    #[Route('/manage/reports/{id}/resolve', name: 'whistleblower_manage_resolve', methods: ['POST'], requirements: ['id' => '[0-9A-Za-z]{26}'])]
    public function resolve(Request $request, string $id): Response
    {
        return $this->apply($id, fn (Report $r) => $this->whistleblower->resolve($r, $this->actor(), (string) $this->input($request, 'summary', 10000)['summary']));
    }

    #[Route('/manage/reports/{id}/archive', name: 'whistleblower_manage_archive', methods: ['POST'], requirements: ['id' => '[0-9A-Za-z]{26}'])]
    public function archive(string $id): Response
    {
        return $this->apply($id, fn (Report $r) => $this->whistleblower->archive($r, $this->actor()));
    }

    #[Route('/manage/reports/{id}/messages', name: 'whistleblower_manage_message', methods: ['POST'], requirements: ['id' => '[0-9A-Za-z]{26}'])]
    public function message(Request $request, string $id): Response
    {
        try {
            $this->authorize();
            $data = $this->input($request, 'body', 5000, ['internal' => new Assert\Optional([new Assert\Type('bool')])]);
            $report = $this->find($id);
            $this->whistleblower->addMessage($report, 'manager', $this->actor(), (string) $data['body'], (bool) ($data['internal'] ?? false));
        } catch (EarlyResponse $e) {
            return $e->response;
        } catch (InvalidTransition $e) {
            return new JsonResponse(['message' => $e->getMessage()], 409);
        }

        return new JsonResponse(['status' => 'sent'], 201);
    }

    /** @param callable(Report): Report $action */
    private function apply(string $id, callable $action): Response
    {
        try {
            $this->authorize();
            $report = $this->find($id);

            return new JsonResponse(Presenter::summary($action($report)));
        } catch (EarlyResponse $e) {
            return $e->response;
        } catch (InvalidTransition $e) {
            return new JsonResponse(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * @param  int<1, max>  $max
     * @param  array<string, Assert\Required|Assert\Optional>  $extra
     * @return array<string, mixed>
     *
     * @throws EarlyResponse
     */
    private function input(Request $request, string $field, int $max, array $extra = []): array
    {
        return $this->validator->validated($request, new Assert\Collection(allowExtraFields: true, fields: $extra + [
            $field => new Assert\Required([new Assert\NotBlank, new Assert\Type('string'), new Assert\Length(max: $max)]),
        ]));
    }

    /** @throws EarlyResponse */
    private function find(string $id): Report
    {
        return $this->whistleblower->find($id) ?? throw new EarlyResponse(new JsonResponse(['message' => 'Report not found.'], 404));
    }

    /** @throws EarlyResponse */
    private function authorize(): void
    {
        if ($this->security->getUser() === null) {
            throw new EarlyResponse(new JsonResponse(['message' => 'Unauthenticated.'], 401));
        }
        if (! $this->security->isGranted($this->ability)) {
            throw new EarlyResponse(new JsonResponse(['message' => 'This action is unauthorized.'], 403));
        }
    }

    private function actor(): ?string
    {
        return $this->security->getUser()?->getUserIdentifier();
    }
}
