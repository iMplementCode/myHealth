<?php

/**
 * ============================================================
 *  Patients
 * ------------------------------------------------------------
 *  Reading and writing the patient record. The page displays;
 *  everything that decides what is allowed is here.
 *
 *  See migration 064 for why a patient is not a customer, and
 *  why the allergy field is free text that nothing matches
 *  against automatically.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * Recorded, never assumed.
 *
 * 'unknown' is a real answer for somebody brought in unconscious.
 * Forcing a guess writes a wrong one into the record for good.
 *
 * Keys must satisfy patients_sex_ck in migration 064.
 */
const PATIENT_SEXES = [
    'female'   => 'Female',
    'male'     => 'Male',
    'intersex' => 'Intersex',
    'unknown'  => 'Not known',
];

/** What goes on a card: "P-2026-0001". */
function patient_next_number(): string
{
    $year = date('Y');

    if (function_exists('next_document_number') && table_exists('document_sequences')) {
        //  Reuses the shared allocator, which locks a row rather than
        //  reading a maximum two callers can both see.
        return next_document_number('patients', 'patient_number', 'P');
    }

    $n = (int) db_value(
        "SELECT COALESCE(MAX(NULLIF(regexp_replace(patient_number, '^P-\\d{4}-', ''), '')::INT), 0)
           FROM patients WHERE patient_number LIKE :p",
        [':p' => 'P-' . $year . '-%']
    );
    return sprintf('P-%s-%04d', $year, $n + 1);
}

/** "Wanjiru Kamau", or just "Wanjiru" when that is all there is. */
function patient_name(array $p): string
{
    return trim(((string) ($p['first_name'] ?? '')) . ' ' . ((string) ($p['last_name'] ?? '')));
}

/**
 * Age in whole years, or null when the date of birth is not known.
 *
 * Whole years is wrong for an infant — a dose for a three-month-old
 * is not a dose for a one-year-old — so under two it says months.
 */
function patient_age(?string $dob): ?string
{
    if (!$dob) {
        return null;
    }
    try {
        $born = new DateTimeImmutable($dob);
    } catch (Throwable) {
        return null;
    }
    $now  = new DateTimeImmutable('today');
    if ($born > $now) {
        return null;
    }
    $diff = $born->diff($now);

    if ($diff->y >= 2) {
        return $diff->y . ' yrs';
    }
    $months = ($diff->y * 12) + $diff->m;
    return $months >= 1 ? $months . ' mo' : $diff->days . ' days';
}

/**
 * Find a patient by name, number, phone or SHA membership.
 *
 * @return list<array<string,mixed>>
 */
function patient_search(string $term, int $limit = 10): array
{
    $term = trim($term);
    if ($term === '' || !table_exists('patients')) {
        return [];
    }

    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($term)) . '%';

    return db_all(
        "SELECT patient_id, patient_number, first_name, last_name,
                date_of_birth, phone, allergies
           FROM patients
          WHERE is_active
            AND (LOWER(first_name) LIKE :t
              OR LOWER(last_name) LIKE :t
              OR LOWER(first_name || ' ' || COALESCE(last_name, '')) LIKE :t
              OR LOWER(patient_number) LIKE :t
              OR phone LIKE :t
              OR LOWER(COALESCE(sha_number, '')) LIKE :t)
          ORDER BY last_name NULLS LAST, first_name
          LIMIT " . max(1, $limit),
        [':t' => $like]
    );
}

/**
 * Create or update a patient.
 *
 * @return array{ok: bool, message: string, patient_id: ?int}
 */
function patient_save(?int $id, array $in): array
{
    $fail = static fn(string $m): array => ['ok' => false, 'message' => $m, 'patient_id' => null];

    $first = trim((string) ($in['first_name'] ?? ''));
    if ($first === '') {
        return $fail('A first name is required.');
    }

    $dob = trim((string) ($in['date_of_birth'] ?? ''));
    if ($dob !== '') {
        //  Checked here as well as by patients_dob_ck, so somebody
        //  who fat-fingered the year gets a sentence rather than a
        //  constraint violation and an error page.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || strtotime($dob) === false) {
            return $fail('That date of birth is not a date.');
        }
        if (strtotime($dob) > strtotime('today')) {
            return $fail('That date of birth is in the future.');
        }
    }

    $sex = trim((string) ($in['sex'] ?? ''));
    if ($sex !== '' && !array_key_exists($sex, PATIENT_SEXES)) {
        $sex = '';
    }

    $fields = [
        ':first'   => $first,
        ':last'    => trim((string) ($in['last_name'] ?? '')) ?: null,
        ':dob'     => $dob !== '' ? $dob : null,
        ':sex'     => $sex !== '' ? $sex : null,
        ':phone'   => trim((string) ($in['phone'] ?? '')) ?: null,
        ':email'   => trim((string) ($in['email'] ?? '')) ?: null,
        ':nid'     => trim((string) ($in['national_id'] ?? '')) ?: null,
        ':sha'     => trim((string) ($in['sha_number'] ?? '')) ?: null,
        ':blood'   => trim((string) ($in['blood_group'] ?? '')) ?: null,
        ':allerg'  => trim((string) ($in['allergies'] ?? '')) ?: null,
        ':chronic' => trim((string) ($in['chronic_conditions'] ?? '')) ?: null,
        ':kin'     => trim((string) ($in['next_of_kin_name'] ?? '')) ?: null,
        ':kinph'   => trim((string) ($in['next_of_kin_phone'] ?? '')) ?: null,
        ':notes'   => trim((string) ($in['notes'] ?? '')) ?: null,
    ];

    $me  = function_exists('current_user') ? current_user() : null;
    $uid = $me['id'] ?? null;

    try {
        if ($id === null) {
            $number = patient_next_number();
            $newId = (int) db_value(
                "INSERT INTO patients
                    (patient_number, first_name, last_name, date_of_birth, sex,
                     phone, email, national_id, sha_number, blood_group,
                     allergies, chronic_conditions, next_of_kin_name, next_of_kin_phone,
                     notes, created_by, updated_by)
                 VALUES
                    (:num, :first, :last, :dob, :sex,
                     :phone, :email, :nid, :sha, :blood,
                     :allerg, :chronic, :kin, :kinph,
                     :notes, :by, :by)
                 RETURNING patient_id",
                $fields + [':num' => $number, ':by' => $uid]
            );
            if (function_exists('audit_log')) {
                audit_log('patient_created', 'patient', $newId);
            }
            return ['ok' => true, 'message' => 'Patient ' . $number . ' registered.',
                    'patient_id' => $newId];
        }

        if (!db_value('SELECT 1 FROM patients WHERE patient_id = :id', [':id' => $id])) {
            return $fail('That patient no longer exists.');
        }

        db_run(
            "UPDATE patients
                SET first_name = :first, last_name = :last, date_of_birth = :dob,
                    sex = :sex, phone = :phone, email = :email, national_id = :nid,
                    sha_number = :sha, blood_group = :blood, allergies = :allerg,
                    chronic_conditions = :chronic, next_of_kin_name = :kin,
                    next_of_kin_phone = :kinph, notes = :notes,
                    updated_at = NOW(), updated_by = :by
              WHERE patient_id = :id",
            $fields + [':id' => $id, ':by' => $uid]
        );
        if (function_exists('audit_log')) {
            audit_log('patient_updated', 'patient', $id);
        }
        return ['ok' => true, 'message' => 'Patient updated.', 'patient_id' => $id];
    } catch (Throwable $e) {
        error_log('[PATIENTS] save failed: ' . $e->getMessage());
        return $fail('The patient could not be saved. Please try again.');
    }
}

/** One patient, or null. */
function patient_get(int $id): ?array
{
    if (!table_exists('patients')) {
        return null;
    }
    return db_one('SELECT * FROM patients WHERE patient_id = :id', [':id' => $id]) ?: null;
}
