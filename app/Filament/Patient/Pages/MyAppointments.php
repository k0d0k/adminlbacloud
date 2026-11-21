<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use App\Platform\Access\PermissionKey;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Throwable;

/**
 * Phase 8A — "Janji Saya".
 *
 * Lists ONLY the acting identity's own appointments (filtered by their own
 * patient id) and lets them cancel a cancellable one. The cancellation runs
 * through the same {@see AppointmentScheduler} transition used by the clinic,
 * so the lifecycle rules are enforced identically. Requires `booking.own`.
 */
class MyAppointments extends Page
{
    use AuthorizesPatientPortal;

    protected static ?string $slug = 'janji-saya';

    protected static ?int $navigationSort = 12;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected string $view = 'filament.patient.pages.my-appointments';

    /** The appointment currently being rescheduled, or null when no form is open. */
    public ?string $reschedulingId = null;

    /** The new start date/time (`Y-m-d\TH:i`) submitted from the reschedule form. */
    public string $rescheduleStartsAt = '';

    public static function canAccess(): bool
    {
        return static::hasPatientContext() && static::allows(PermissionKey::BOOKING_OWN);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.patient.my_appointments');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient.appointments.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.patient.appointments.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.patient.appointments.subtitle');
    }

    /**
     * The acting identity's OWN appointments, newest first. Fails closed.
     *
     * @return Collection<int, Appointment>
     */
    public function appointments(): Collection
    {
        $patient = $this->patient();

        if ($patient === null) {
            return collect();
        }

        try {
            return Appointment::query()
                ->with('branch')
                ->where('patient_id', $patient->getKey())
                ->orderByDesc('starts_at')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    public function canCancel(Appointment $appointment): bool
    {
        return $appointment->canTransitionTo(Appointment::STATUS_CANCELLED);
    }

    public function cancel(string $appointmentId): void
    {
        $patient = $this->requirePatient();

        if (! static::allows(PermissionKey::BOOKING_OWN)) {
            abort(403);
        }

        // Self-only: an appointment that is not the acting identity's own is
        // never loaded, so a foreign id simply resolves to nothing.
        $appointment = Appointment::query()
            ->whereKey($appointmentId)
            ->where('patient_id', $patient->getKey())
            ->first();

        abort_if($appointment === null, 404);

        try {
            app(AppointmentScheduler::class)->transition(
                $appointment,
                Appointment::STATUS_CANCELLED,
                auth()->user(),
                __('lba.patient.appointments.cancelled_note'),
            );
        } catch (AppointmentConflictException) {
            Notification::make()
                ->danger()
                ->title(__('lba.patient.appointments.errors.cancel'))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('lba.patient.appointments.cancelled'))
            ->send();
    }

    public function statusLabel(string $status): string
    {
        return __("lba.patient.appointments.status.{$status}");
    }

    /**
     * A non-terminal appointment may be moved. The lifecycle status is never
     * edited here — only the slot — so the rule mirrors the API.
     */
    public function canReschedule(Appointment $appointment): bool
    {
        return ! $appointment->isTerminal();
    }

    /**
     * Open the reschedule form for one of the acting identity's OWN
     * appointments, pre-filled with its current start.
     */
    public function beginReschedule(string $appointmentId): void
    {
        $patient = $this->requirePatient();

        if (! static::allows(PermissionKey::BOOKING_OWN)) {
            abort(403);
        }

        $appointment = $this->ownAppointment($appointmentId, (string) $patient->getKey());

        $this->resetErrorBag();
        $this->reschedulingId = (string) $appointment->getKey();
        $this->rescheduleStartsAt = $appointment->starts_at?->format('Y-m-d\TH:i') ?? '';
    }

    public function cancelReschedule(): void
    {
        $this->resetErrorBag();
        $this->reschedulingId = null;
        $this->rescheduleStartsAt = '';
    }

    /**
     * Move the appointment to the submitted slot via the SAME
     * {@see AppointmentScheduler::reschedule()} the API uses, so every guard
     * (tenant-local patient/branch, capacity, practitioner overlap) applies.
     *
     * On a conflict the scheduler's transaction rolls back, so the patient's
     * ORIGINAL booking is preserved untouched.
     */
    public function reschedule(): void
    {
        $patient = $this->requirePatient();

        if (! static::allows(PermissionKey::BOOKING_OWN)) {
            abort(403);
        }

        if ($this->reschedulingId === null) {
            return;
        }

        $appointment = $this->ownAppointment($this->reschedulingId, (string) $patient->getKey());

        if ($appointment->isTerminal()) {
            Notification::make()
                ->danger()
                ->title(__('lba.patient.appointments.errors.reschedule'))
                ->send();

            $this->cancelReschedule();

            return;
        }

        $this->validate([
            'rescheduleStartsAt' => ['required', 'date', 'after:now'],
        ], [], [
            'rescheduleStartsAt' => __('lba.patient.appointments.starts_at'),
        ]);

        $startsAt = Carbon::parse($this->rescheduleStartsAt);

        // Preserve the original appointment's duration across the move.
        $duration = ($appointment->starts_at !== null && $appointment->ends_at !== null)
            ? (int) $appointment->starts_at->diffInMinutes($appointment->ends_at)
            : 0;
        $duration = $duration > 0 ? $duration : 30;

        try {
            app(AppointmentScheduler::class)->reschedule($appointment, [
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes($duration),
            ], auth()->user());
        } catch (AppointmentConflictException) {
            Notification::make()
                ->danger()
                ->title(__('lba.patient.appointments.errors.reschedule'))
                ->send();

            return;
        }

        $this->cancelReschedule();

        Notification::make()
            ->success()
            ->title(__('lba.patient.appointments.rescheduled'))
            ->send();
    }

    /**
     * Load an appointment that belongs to the acting patient, or 404. A foreign
     * id resolves to nothing, so existence is never leaked.
     */
    private function ownAppointment(string $appointmentId, string $patientId): Appointment
    {
        $appointment = Appointment::query()
            ->with('branch')
            ->whereKey($appointmentId)
            ->where('patient_id', $patientId)
            ->first();

        abort_if($appointment === null, 404);

        return $appointment;
    }
}
