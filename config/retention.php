<?php
/**
 * Data retention policy. These are STARTING DEFAULTS, not legal advice -
 * confirm actual periods with counsel before relying on them.
 *
 * There's a real tension worth understanding here: POPIA's purpose-
 * specification condition says don't keep data longer than necessary for
 * the purpose it was collected for. But POPIA section 14 also permits
 * longer retention where ANOTHER law requires it - and FICA's AML
 * record-keeping duties (commonly ~5 years for transaction/verification
 * records) likely apply to this system. That's why DHA verification and
 * transaction records default to a longer period than biometric templates
 * themselves: the biometric data's own purpose (matching a live capture to
 * an enrollment) doesn't need years of history, but the compliance record
 * that a check happened probably does.
 */

return [
    // Biometric templates: destroy when the purpose is fulfilled, or after
    // this many days, whichever comes first. ~3 years is a commonly cited
    // benchmark for biometric data destruction, not a POPIA-mandated number.
    'biometric_template_days' => 1095,

    // DHA verification records: the RAW bureau response (names, DOB, etc.)
    // gets redacted after this period, but the row itself (match result,
    // cost, timestamp) is kept longer for AML/audit purposes - see the
    // reasoning above. ~5 years, aligned with typical FICA record-keeping
    // expectations - confirm with counsel.
    'dha_raw_response_days' => 1825,

    // Audit log: kept long by design - this IS the accountability record.
    'audit_log_days' => 1825,

    // Consent records are never auto-purged. They're the evidence a lawful
    // basis existed at the time processing happened - deleting them would
    // remove the proof of compliance, not just the personal data itself.
    'consent_record_days' => null,
];
