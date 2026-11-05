<?php

declare(strict_types=1);

namespace Whistleblower;

use DateTimeInterface;
use Whistleblower\Domain\AuditEntry;
use Whistleblower\Domain\Message;
use Whistleblower\Domain\Report;

/** The JSON shapes of the API, shared by every framework adapter. */
final class Presenter
{
    /**
     * The list view: no report content and no contact data.
     *
     * @return array<string, mixed>
     */
    public static function summary(Report $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status->value,
            'anonymous' => $r->anonymous,
            'received_at' => self::date($r->receivedAt),
            'acknowledgement_due_at' => self::date($r->acknowledgementDueAt),
            'acknowledged_at' => self::date($r->acknowledgedAt),
            'resolution_due_at' => self::date($r->resolutionDueAt),
            'extended' => $r->extendedAt !== null,
            'anonymized' => $r->anonymizedAt !== null,
        ];
    }

    /**
     * @param  list<Message>  $messages
     * @param  list<AuditEntry>  $audit
     * @return array<string, mixed>
     */
    public static function detail(Report $r, array $messages, array $audit): array
    {
        return self::summary($r) + [
            'subject' => $r->subject,
            'description' => $r->description,
            'reporter_name' => $r->reporterName,
            'reporter_email' => $r->reporterEmail,
            'resolution' => $r->resolution,
            'messages' => array_map(static fn (Message $m): array => [
                'author' => $m->author, 'user_id' => $m->userId, 'body' => $m->body, 'created_at' => self::date($m->createdAt),
            ], $messages),
            'audit' => array_map(static fn (AuditEntry $a): array => [
                'actor_type' => $a->actorType, 'actor_id' => $a->actorId, 'action' => $a->action, 'created_at' => self::date($a->createdAt),
            ], $audit),
        ];
    }

    /**
     * What the reporter sees when following up with the tracking code.
     *
     * @param  list<Message>  $visibleMessages
     * @return array<string, mixed>
     */
    public static function followUp(Report $r, array $visibleMessages): array
    {
        return [
            'status' => $r->status->value,
            'received_at' => self::date($r->receivedAt),
            'acknowledged_at' => self::date($r->acknowledgedAt),
            'resolution_due_at' => self::date($r->resolutionDueAt),
            'resolution' => $r->resolution,
            'messages' => array_map(static fn (Message $m): array => [
                'author' => $m->author, 'body' => $m->body, 'created_at' => self::date($m->createdAt),
            ], $visibleMessages),
        ];
    }

    private static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format(DateTimeInterface::ATOM);
    }
}
