<?php

namespace App\Notifications;

use App\Models\User;
use App\Platform\Site\Services\PatientSelfRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Website CMS — verify a patient's OWN email after a public-frontpage
 * registration.
 *
 * Delivered to the address the visitor registered with (on-demand), never to a
 * third party: opening the short-lived signed link proves ownership of that
 * mailbox. The message carries only the signed link and its expiry — no
 * credential and no account secret. Verification proves EMAIL OWNERSHIP only;
 * clinical identity verification remains a clinic process.
 */
class VerifyPatientEmail extends Notification
{
    use Queueable;

    public function __construct(
        private readonly User $user,
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
            ->subject(__('site.registration.email_subject', ['clinic' => $this->clinicName]))
            ->line(__('site.registration.email_intro', [
                'name' => $this->user->name,
                'clinic' => $this->clinicName,
            ]))
            ->action(__('site.registration.email_action'), $this->verificationUrl)
            ->line(__('site.registration.email_expiry', [
                'hours' => PatientSelfRegistration::VERIFICATION_TTL_HOURS,
            ]))
            ->line(__('site.registration.email_footer'));
    }
}
