<?php

declare(strict_types=1);

namespace Whistleblower\Enums;

enum Deadline: string
{
    case Acknowledgement = 'acknowledgement';
    case Resolution = 'resolution';
}
