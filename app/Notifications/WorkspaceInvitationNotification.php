<?php

namespace App\Notifications;

use App\Models\WorkspaceInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Keep synchronous: queueing would put the plain token in the jobs table (SHARED_CONTRACTS §8).
class WorkspaceInvitationNotification extends Notification
{
    /** The plain token exists only here and in the emailed link; the DB keeps its hash. */
    public function __construct(
        public readonly WorkspaceInvitation $invitation,
        public readonly string $token,
        public readonly string $inviterName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = $this->invitation->workspace->name;

        return (new MailMessage)
            ->subject("You're invited to {$workspace}")
            ->line("{$this->inviterName} invited you to join {$workspace} as {$this->invitation->role->label()}.")
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line('This link expires in '.WorkspaceInvitation::TTL_DAYS.' days and works once.');
    }
}
