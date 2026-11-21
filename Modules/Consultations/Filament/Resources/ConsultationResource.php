<?php

namespace Modules\Consultations\Filament\Resources;

use App\Models\TenantMembership;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Appointments\Models\Appointment;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Filament\Resources\ConsultationResource\Pages\CreateConsultation;
use Modules\Consultations\Filament\Resources\ConsultationResource\Pages\EditConsultation;
use Modules\Consultations\Filament\Resources\ConsultationResource\Pages\ListConsultations;
use Modules\Consultations\Filament\Resources\ConsultationResource\Pages\ViewConsultation;
use Modules\Consultations\Filament\Resources\ConsultationResource\RelationManagers\ConsultationMessagesRelationManager;
use Modules\Consultations\Filament\Resources\ConsultationResource\RelationManagers\ConsultationStatusHistoryRelationManager;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Services\ConsultationManager;
use Modules\Consultations\Services\SinaConsentService;
use Modules\Consultations\Services\SinaEscalation;
use Modules\Patients\Models\Patient;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Services\PractitionerAvailability;
use UnitEnum;

/**
 * Workstream 2 — Clinic panel consultation book.
 *
 * Operates on the verified current tenant's isolated database and is further
 * scoped to the branches the acting identity may operate in, so a consultation
 * is never visible across tenants and never leaks another branch's schedule.
 *
 * The status lifecycle only moves through {@see ConsultationManager}, which
 * validates every transition; consultations are archived via status, never
 * deleted.
 *
 * @extends resource<Consultation>
 */
class ConsultationResource extends Resource
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'consultations';

    protected static ?string $model = Consultation::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 22;

    protected static ?string $recordTitleAttribute = 'id';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function getModelLabel(): string
    {
        return __('lba.consultations.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.consultations.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.consultations.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.consultations.section_subject'))
                    ->schema([
                        Select::make('patient_id')
                            ->label(__('lba.consultations.patient'))
                            ->options(fn (): array => static::patientOptions())
                            ->searchable()
                            ->required(),
                        Select::make('branch_id')
                            ->label(__('lba.consultations.branch'))
                            ->options(static::accessibleBranchOptions())
                            ->default(fn (): ?string => static::defaultBranchId())
                            ->required(),
                        Select::make('staff_user_id')
                            ->label(__('lba.consultations.staff'))
                            ->options(static::staffOptions())
                            ->searchable()
                            ->placeholder(__('lba.consultations.staff_unassigned')),
                        Select::make('appointment_id')
                            ->label(__('lba.consultations.appointment'))
                            ->options(fn (): array => static::appointmentOptions())
                            ->searchable()
                            ->placeholder(__('lba.consultations.appointment_none')),
                        Select::make('channel')
                            ->label(__('lba.consultations.channel'))
                            ->options(static::channelOptions())
                            ->default(Consultation::CHANNEL_SINA_CHAT)
                            ->required(),
                        Select::make('type')
                            ->label(__('lba.consultations.type'))
                            ->options(static::typeOptions())
                            ->default(Consultation::TYPE_CONSULTATION)
                            ->required(),
                    ])
                    ->columns(2),
                Section::make(__('lba.consultations.section_schedule'))
                    ->schema([
                        DateTimePicker::make('scheduled_at')
                            ->label(__('lba.consultations.scheduled_at'))
                            ->seconds(false),
                    ])
                    ->columns(2),
                Section::make(__('lba.consultations.section_summary'))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('lba.consultations.reason'))
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                        Textarea::make('summary')
                            ->label(__('lba.consultations.summary'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $staffNames = static::staffOptions();

        return $table
            ->columns([
                TextColumn::make('scheduled_at')
                    ->label(__('lba.consultations.scheduled_at'))
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('patient.full_name')
                    ->label(__('lba.consultations.patient'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('branch.name')
                    ->label(__('lba.consultations.branch'))
                    ->placeholder('—'),
                TextColumn::make('staff_user_id')
                    ->label(__('lba.consultations.staff'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : ($staffNames[$state] ?? $state))
                    ->placeholder('—'),
                TextColumn::make('channel')
                    ->label(__('lba.consultations.channel'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.consultations.channel_{$state}")),
                TextColumn::make('status')
                    ->label(__('lba.consultations.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.consultations.status_{$state}"))
                    ->color(fn (string $state): string => Consultation::statusColor($state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.consultations.filter_status'))
                    ->options(static::statusOptions()),
                SelectFilter::make('channel')
                    ->label(__('lba.consultations.filter_channel'))
                    ->options(static::channelOptions()),
                SelectFilter::make('branch_id')
                    ->label(__('lba.consultations.filter_branch'))
                    ->options(static::accessibleBranchOptions()),
                SelectFilter::make('staff_user_id')
                    ->label(__('lba.consultations.filter_staff'))
                    ->options(static::staffOptions())
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                static::escalateAction(),
                static::statusAction(
                    Consultation::STATUS_IN_PROGRESS,
                    'lba.consultations.action_start',
                    Heroicon::OutlinedPlayCircle,
                    'warning',
                ),
                static::statusAction(
                    Consultation::STATUS_COMPLETED,
                    'lba.consultations.action_complete',
                    Heroicon::OutlinedCheckBadge,
                    'success',
                ),
                static::statusAction(
                    Consultation::STATUS_CANCELLED,
                    'lba.consultations.action_cancel',
                    Heroicon::OutlinedXCircle,
                    'danger',
                ),
                static::statusAction(
                    Consultation::STATUS_NO_SHOW,
                    'lba.consultations.action_no_show',
                    Heroicon::OutlinedUserMinus,
                    'danger',
                ),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedChatBubbleLeftRight)
            ->emptyStateHeading(__('lba.consultations.empty_heading'))
            ->emptyStateDescription(__('lba.consultations.empty_description'));
    }

    public static function getEloquentQuery(): Builder
    {
        // The verified tenant connection is resolved at query time by the model;
        // the list is additionally restricted to the branches the acting identity
        // may operate in. With no accessible branch nothing is listed.
        $branches = static::accessibleBranchIds();

        return parent::getEloquentQuery()->when(
            $branches !== [],
            fn (Builder $query): Builder => $query->whereIn('branch_id', $branches),
            fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
        );
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            ConsultationStatusHistoryRelationManager::class,
            ConsultationMessagesRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListConsultations::route('/'),
            'create' => CreateConsultation::route('/create'),
            'view' => ViewConsultation::route('/{record}'),
            'edit' => EditConsultation::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::CONSULTATION_MANAGE);
    }

    public static function canCreate(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::CONSULTATION_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::CONSULTATION_MANAGE);
    }

    public static function canView(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::CONSULTATION_MANAGE);
    }

    public static function canDelete(Model $record): bool
    {
        // Consultations are cancelled, never deleted.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Phase 13 — escalate a SINA AI chat session to a human expert consultation.
     *
     * Visible only for a SINA session whose patient has already granted the
     * `expert_sharing` consent; the {@see SinaEscalation} service re-derives
     * every gate server-side. The action books through the SAME human
     * consultation flow the clinic already uses, so scheduling, practitioner
     * authorization and the disclosed tariff are unchanged.
     */
    protected static function escalateAction(): Action
    {
        return Action::make('escalate')
            ->label(__('lba.consultations.action_escalate'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('primary')
            ->visible(fn (Consultation $record): bool => static::moduleActive()
                && static::allows(PermissionKey::CONSULTATION_MANAGE)
                && $record->isSinaSession()
                && $record->isActive()
                && static::expertSharingGranted($record))
            ->schema([
                Select::make('branch_id')
                    ->label(__('lba.consultations.branch'))
                    ->options(static::accessibleBranchOptions())
                    ->default(fn (): ?string => static::defaultBranchId())
                    ->required()
                    ->live(),
                Select::make('practitioner_id')
                    ->label(__('lba.consultations.action_escalate_practitioner'))
                    ->options(fn (Get $get): array => static::bookablePractitionerOptions($get('branch_id')))
                    ->searchable()
                    ->required(),
                DateTimePicker::make('scheduled_at')
                    ->label(__('lba.consultations.scheduled_at'))
                    ->seconds(false)
                    ->required(),
                Select::make('type')
                    ->label(__('lba.consultations.type'))
                    ->options(static::typeOptions())
                    ->default(Consultation::TYPE_CONSULTATION)
                    ->required(),
            ])
            ->action(function (Consultation $record, array $data): void {
                $branch = TenantBranch::query()->whereKey((string) ($data['branch_id'] ?? ''))->first();

                if ($branch === null) {
                    Notification::make()->danger()->title(__('lba.consultations.escalate_failed'))->send();

                    return;
                }

                try {
                    app(SinaEscalation::class)->escalate($record, $branch, (string) ($data['practitioner_id'] ?? ''), [
                        'type' => $data['type'] ?? Consultation::TYPE_CONSULTATION,
                        'scheduled_at' => $data['scheduled_at'] ?? null,
                    ], static::currentUser());

                    Notification::make()->success()->title(__('lba.consultations.escalated'))->send();
                } catch (ConsultationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title($exception->errorCode() === 'expert_sharing_consent_required'
                            ? __('lba.consultations.escalate_consent_required')
                            : $exception->getMessage())
                        ->send();
                }
            });
    }

    /**
     * A validated status-transition action; visible only when the transition is
     * legal and the identity may manage consultations.
     */
    protected static function statusAction(string $to, string $labelKey, Heroicon $icon, string $color): Action
    {
        return Action::make("status_{$to}")
            ->label(__($labelKey))
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->visible(fn (Consultation $record): bool => static::moduleActive()
                && static::allows(PermissionKey::CONSULTATION_MANAGE)
                && $record->canTransitionTo($to))
            ->action(function (Consultation $record) use ($to): void {
                try {
                    app(ConsultationManager::class)->transition($record, $to, static::currentUser());

                    Notification::make()
                        ->success()
                        ->title(__('lba.consultations.status_changed'))
                        ->send();
                } catch (ConsultationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title($exception->getMessage())
                        ->send();
                }
            });
    }

    /**
     * @return array<string, string>
     */
    protected static function statusOptions(): array
    {
        $options = [];

        foreach (Consultation::STATUSES as $status) {
            $options[$status] = __("lba.consultations.status_{$status}");
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function channelOptions(): array
    {
        $options = [];

        foreach (Consultation::CHANNELS as $channel) {
            $options[$channel] = __("lba.consultations.channel_{$channel}");
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function typeOptions(): array
    {
        $options = [];

        foreach (Consultation::TYPES as $type) {
            $options[$type] = __("lba.consultations.type_{$type}");
        }

        return $options;
    }

    /**
     * Active patients of the verified tenant, for the consultation picker.
     *
     * @return array<string, string>
     */
    protected static function patientOptions(): array
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        return Patient::query()
            ->active()
            ->orderBy('full_name')
            ->pluck('full_name', 'id')
            ->all();
    }

    /**
     * Upcoming appointments of the verified tenant, for linking a consultation.
     *
     * @return array<string, string>
     */
    protected static function appointmentOptions(): array
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        return Appointment::query()
            ->orderByDesc('starts_at')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn ($appointment): array => [
                (string) $appointment->getKey() => trim(($appointment->starts_at?->toDateTimeString() ?? '').' · '.($appointment->patient?->full_name ?? '')),
            ])
            ->all();
    }

    /**
     * Active staff members of the verified tenant, for assignment and filters.
     *
     * @return array<string, string>
     */
    protected static function staffOptions(): array
    {
        $tenantId = app(ResolvesTenantDatabase::class)->current();

        if ($tenantId === null) {
            return [];
        }

        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->with('user')
            ->get()
            ->mapWithKeys(fn (TenantMembership $membership): array => [
                (string) $membership->user_id => $membership->user?->name ?? (string) $membership->user_id,
            ])
            ->all();
    }

    protected static function defaultBranchId(): ?string
    {
        $user = static::currentUser();

        if ($user === null) {
            return null;
        }

        return app(ManagesBranchContext::class)->currentBranchId($user);
    }

    /**
     * Whether the session's patient has explicitly approved sharing the SINA
     * session with a human expert.
     */
    protected static function expertSharingGranted(Consultation $record): bool
    {
        $patient = Patient::query()->whereKey($record->patient_id)->first();

        if ($patient === null) {
            return false;
        }

        return app(SinaConsentService::class)->hasExpertSharingConsent($patient);
    }

    /**
     * The verified practitioners authorized to attend a consultation at a
     * branch, for the escalation picker. Empty without a branch or tenant.
     *
     * @return array<string, string>
     */
    protected static function bookablePractitionerOptions(?string $branchId): array
    {
        if (blank($branchId) || app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        $branch = TenantBranch::query()->whereKey($branchId)->first();

        if ($branch === null) {
            return [];
        }

        return app(PractitionerAvailability::class)
            ->bookablePractitioners($branch)
            ->mapWithKeys(fn (Practitioner $practitioner): array => [
                (string) $practitioner->getKey() => (string) ($practitioner->display_name ?: $practitioner->getKey()),
            ])
            ->all();
    }
}
