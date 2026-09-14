<?php

declare(strict_types=1);

namespace Modules\Crm\SelfService\Application\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * TASK-ECOS-V1.1-CRM-04-SECURE-SELF-SERVICE-BACKEND-IMPLEMENTATION-019 §2 — the ONE real,
 * already-existing transactional delivery authority this codebase has: Laravel's own Mail/
 * Notification system (SMTP-backed, already used throughout this app — e.g.
 * DriverAssignedNotification, TaskAssignedNotification). Sent via
 * Notification::route('mail', $email)->notify(...) — a Customer is not a Notifiable model, so
 * this deliberately never needs to become one. No SMS/WhatsApp transport exists anywhere in this
 * codebase (confirmed by search) and CustomerEngagement's WhatsApp provider is explicitly not
 * reused here (internal inbox transport is not OTP transport).
 */
final class CustomerTrackingChallengeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Both properties are public readonly so tests can assert on the exact code that would be
     * emailed (via Notification::fake()'s own assertSentOnDemand callback) without ever needing
     * to read it back from the database — `customer_verification_challenges.code_hash` is
     * write-only by design (§7) and deliberately cannot be reversed.
     */
    public function __construct(
        public readonly string $code,
        public readonly string $orderNumber,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your order tracking verification code')
            ->greeting('Hello,')
            ->line("Use this code to securely track order {$this->orderNumber}:")
            ->line("**{$this->code}**")
            ->line('This code expires in 10 minutes and can only be used once.')
            ->line('If you did not request this, you can safely ignore this email.');
    }
}
