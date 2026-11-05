<?php

declare(strict_types=1);

namespace Whistleblower\Symfony;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Reads the request body and validates it, answering 422 in the same shape the Laravel adapter uses. */
final readonly class PayloadValidator
{
    public function __construct(private ValidatorInterface $validator) {}

    /**
     * Validates the body against the fields and returns it.
     *
     * @return array<string, mixed>
     *
     * @throws EarlyResponse 400 for an unreadable body, 422 for invalid fields
     */
    public function validated(Request $request, Collection $constraint): array
    {
        try {
            $data = $request->getContentTypeFormat() === 'json' ? $request->toArray() : $request->request->all();
        } catch (\Throwable) {
            throw new EarlyResponse(new JsonResponse(['message' => 'Invalid JSON body.'], 400));
        }

        $errors = [];
        foreach ($this->validator->validate($data, $constraint) as $violation) {
            $errors[trim($violation->getPropertyPath(), '[]')][] = (string) $violation->getMessage();
        }
        if ($errors !== []) {
            throw new EarlyResponse(new JsonResponse(['message' => 'The given data was invalid.', 'errors' => $errors], 422));
        }

        return $data;
    }
}
