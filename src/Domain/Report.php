<?php

declare(strict_types=1);

namespace Whistleblower\Domain;

use DateTimeImmutable;
use Whistleblower\Enums\ReportStatus;

/** A report as the core sees it. Stores are responsible for encrypting the personal fields. */
final class Report
{
    public function __construct(
        public readonly string $id,
        public string $trackingCodeHash,
        public ReportStatus $status,
        public ?string $category,
        public ?string $subject,
        public ?string $description,
        public bool $anonymous,
        public ?string $reporterName,
        public ?string $reporterEmail,
        public ?string $resolution,
        public DateTimeImmutable $receivedAt,
        public DateTimeImmutable $acknowledgementDueAt,
        public ?DateTimeImmutable $acknowledgedAt,
        public DateTimeImmutable $resolutionDueAt,
        public ?DateTimeImmutable $extendedAt = null,
        public ?DateTimeImmutable $investigationStartedAt = null,
        public ?DateTimeImmutable $resolvedAt = null,
        public ?DateTimeImmutable $archivedAt = null,
        public ?DateTimeImmutable $anonymizedAt = null,
    ) {}
}
