<?php

declare(strict_types=1);

namespace Whistleblower\Symfony;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Lets a controller helper end the request with a ready-made response (validation, 404, 429...). */
final class EarlyResponse extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Early response.');
    }
}
