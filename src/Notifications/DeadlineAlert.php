<?php

declare(strict_types=1);

namespace Whistleblower\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Whistleblower\Events\DeadlineReached;

final class DeadlineAlert extends Notification
{
    public function __construct(private readonly DeadlineReached $event) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $state = $this->event->overdue ? 'overdue' : 'approaching';
        $deadline = __("whistleblower::messages.deadline.{$this->event->deadline->value}");

        return (new MailMessage)
            ->subject(trans("whistleblower::messages.alert.subject_{$state}", ['deadline' => $deadline]))
            ->line(__("whistleblower::messages.alert.body_{$state}", [
                'deadline' => $deadline,
                'date' => $this->event->dueAt->toDateString(),
                'id' => $this->event->reportId,
            ]));
    }
}
