<?php

declare(strict_types=1);

namespace Whistleblower\Symfony;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Whistleblower\Events\DeadlineReached;
use Whistleblower\Messages;

/** Mails the configured recipients. The alert carries the report ID and the due date, never content. */
final readonly class DeadlineAlertMailer
{
    /** @param list<string> $recipients */
    public function __construct(
        private MailerInterface $mailer,
        private array $recipients,
        private ?string $from,
        private string $locale,
    ) {}

    public function __invoke(DeadlineReached $event): void
    {
        $state = $event->overdue ? 'overdue' : 'approaching';
        $deadline = Messages::get($this->locale, 'deadline.'.$event->deadline->value);

        $email = (new Email)
            ->to(...$this->recipients)
            ->subject(Messages::get($this->locale, "alert.subject_{$state}", ['deadline' => $deadline]))
            ->text(Messages::get($this->locale, "alert.body_{$state}", [
                'deadline' => $deadline,
                'date' => $event->dueAt->format('Y-m-d'),
                'id' => $event->reportId,
            ]));
        if ($this->from !== null) {
            $email->from($this->from);
        }

        $this->mailer->send($email);
    }
}
