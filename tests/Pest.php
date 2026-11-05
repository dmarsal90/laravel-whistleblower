<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Whistleblower\Tests\Laravel\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Laravel');
