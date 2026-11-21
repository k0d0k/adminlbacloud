<?php

namespace Modules\Patients\Support;

/**
 * Shared provenance rules for patient-entered and clinician-entered clinical
 * profile entries (Phase 2).
 *
 * Every health-profile row records WHERE it came from (`source`) and WHETHER an
 * authorized person has reviewed it (`verification_status`). The two are
 * deliberately independent:
 *
 *  - a patient may report hypertension, which is stored as
 *    `source = patient_reported` and stays `unverified` until a clinician
 *    reviews it — the platform never promotes a patient report into a
 *    verified diagnosis on its own;
 *  - a clinician may type an entry directly, which is
 *    `source = clinician_entered` and may be recorded as verified in the same
 *    authorized action.
 *
 * A clinical summary must therefore always be able to mark which entries are
 * still awaiting review.
 */
trait RecordsClinicalProvenance
{
    /** Reported by the patient (or an accompanying person). */
    public const SOURCE_PATIENT = 'patient_reported';

    /** Taken from a medical document supplied to the clinic. */
    public const SOURCE_DOCUMENT = 'medical_document';

    /** Entered directly by an authorized clinic user. */
    public const SOURCE_CLINICIAN = 'clinician_entered';

    /** @var list<string> */
    public const SOURCES = [
        self::SOURCE_PATIENT,
        self::SOURCE_DOCUMENT,
        self::SOURCE_CLINICIAN,
    ];

    /** Not yet reviewed by an authorized person. */
    public const VERIFICATION_UNVERIFIED = 'unverified';

    /** Reviewed and confirmed by an authorized person. */
    public const VERIFICATION_VERIFIED = 'verified';

    /** @var list<string> */
    public const VERIFICATIONS = [
        self::VERIFICATION_UNVERIFIED,
        self::VERIFICATION_VERIFIED,
    ];

    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFICATION_VERIFIED;
    }

    public function isUnverified(): bool
    {
        return ! $this->isVerified();
    }

    public function isPatientReported(): bool
    {
        return $this->source === self::SOURCE_PATIENT;
    }
}
