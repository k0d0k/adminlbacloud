<?php

namespace App\Notifications;

use App\Platform\Site\Services\VisitorBooking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Appointments\Models\BookingRequest;

/**
 * Website CMS (Task 11) — verify a visitor's email after a public-frontpage
 * booking request.
 *
 * Delivered to the address the visitor submitted (on-demand), never to a third
 * party: opening the short-lived signed link proves ownership of that mailbox,
 * which is what lets the SAME request be continued in the patient portal. The
 * message carries only the signed link, its expiry and the requested slot — no
 * credential, no account secret and no medical data.
 */
class VerifyBookingRequestEmail extends Notification
{
    use Queueable;

    public function __construct(
        private readonly BookingRequest $booking,
        private readonly string $clinicName,
        private readonly string $verificationUrl,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('booking.email_subject', ['clinic' => $this->clinicName]))
            ->line(__('booking.email_intro', [
                'name' => $this->booking->full_name,
                'clinic' => $this->clinicName,
            ]))
            ->line(__('booking.email_slot', [
                'slot' => (string) $this->booking->scheduled_at,
            ]))
            ->action(__('booking.email_action'), $this->verificationUrl)
            ->line(__('booking.email_expiry', [
                'hours' => VisitorBooking::VERIFICATION_TTL_HOURS,
            ]))
            ->line(__('booking.email_footer'));
    }
}
