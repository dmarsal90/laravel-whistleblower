<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Whistleblower\Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(__DIR__);
