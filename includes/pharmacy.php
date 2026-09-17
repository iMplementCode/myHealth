<?php

/**
 * ============================================================
 *  What the trade calls things
 * ------------------------------------------------------------
 *  The vocabulary a pharmacy uses, in one file, because it is
 *  needed in three places that must agree: the form that offers
 *  the choices, the code that validates what came back, and the
 *  CHECK constraints in migration 061 that refuse anything else.
 *
 *  Two of those three are here. The third is in SQL and cannot
 *  be, so **if you add a form or a route below, widen the CHECK
 *  in a migration in the same commit** — otherwise the select
 *  offers a value the database will reject, and the only person
 *  who finds out is whoever was trying to save a drug.
 *
 *  The keys are what is stored. The labels are what a human
 *  reads, and they can be changed freely.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * How the medicine is presented.
 *
 * Ordered by how often a retail shelf sees them, not
 * alphabetically — the top three are most of a pharmacy's lines
 * and a long alphabetical list would put 'tablet' near the end.
 */
const DOSAGE_FORMS = [
    'tablet'      => 'Tablet',
    'capsule'     => 'Capsule',
    'syrup'       => 'Syrup',
    'suspension'  => 'Suspension',
    'solution'    => 'Solution',
    'injection'   => 'Injection',
    'infusion'    => 'Infusion',
    'cream'       => 'Cream',
    'ointment'    => 'Ointment',
    'gel'         => 'Gel',
    'drops'       => 'Drops',
    'inhaler'     => 'Inhaler',
    'suppository' => 'Suppository',
    'pessary'     => 'Pessary',
    'patch'       => 'Patch',
    'sachet'      => 'Sachet',
    'powder'      => 'Powder',
    'lozenge'     => 'Lozenge',
    'spray'       => 'Spray',
    'device'      => 'Device',
    'other'       => 'Other',
];

/** How it goes in. */
const ADMIN_ROUTES = [
    'oral'        => 'By mouth',
    'topical'     => 'On the skin',
    'iv'          => 'Intravenous',
    'im'          => 'Intramuscular',
    'sc'          => 'Subcutaneous',
    'rectal'      => 'Rectal',
    'vaginal'     => 'Vaginal',
    'ophthalmic'  => 'Into the eye',
    'otic'        => 'Into the ear',
    'nasal'       => 'Into the nose',
    'inhaled'     => 'Inhaled',
    'sublingual'  => 'Under the tongue',
    'transdermal' => 'Through the skin',
    'other'       => 'Other',
];

/**
 * How it has to be kept.
 *
 * The temperatures are in the labels because "cool" means nothing
 * to somebody deciding whether a delivery that sat in a matatu
 * all afternoon is still sellable.
 */
const STORAGE_CONDITIONS = [
    'room'   => 'Room temperature (below 25°C)',
    'cool'   => 'Cool (8–15°C)',
    'cold'   => 'Refrigerated (2–8°C)',
    'frozen' => 'Frozen (below 0°C)',
];

/**
 * Controlled classes, as the Narcotic Drugs and Psychotropic
 * Substances Act separates them.
 *
 * Deliberately coarse. The point of this field is not to encode
 * the statute — it is to make the dispensing screen behave
 * differently, and for that, "is this controlled and roughly how"
 * is the whole question. An empty value means ordinary stock.
 */
const CONTROLLED_SCHEDULES = [
    'narcotic'     => 'Narcotic',
    'psychotropic' => 'Psychotropic',
    'precursor'    => 'Precursor chemical',
];

/**
 * Is this one of ours, or something a form invented?
 *
 * Returns the value when it is in the list, and null otherwise —
 * so a caller writes `pharmacy_valid(DOSAGE_FORMS, $in)` straight
 * into a bound parameter and an unknown value becomes NULL rather
 * than an error the database has to catch.
 *
 * Empty string is null, not invalid: "not said yet" is a legal
 * state for every one of these.
 */
function pharmacy_valid(array $list, mixed $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    return array_key_exists($value, $list) ? $value : null;
}

/**
 * How a drug should read on a shelf label, a receipt or a
 * search result.
 *
 * "Panadol 500 mg tablet". The brand name alone is ambiguous
 * across strengths, and this is the one-line form of the whole
 * identity — used anywhere a single line is all there is room
 * for.
 */
function drug_label(array $row): string
{
    $bits = [trim((string) ($row['name'] ?? ''))];

    if (!empty($row['strength'])) {
        $bits[] = trim((string) $row['strength']);
    }
    if (!empty($row['dosage_form'])) {
        $bits[] = strtolower(DOSAGE_FORMS[$row['dosage_form']] ?? (string) $row['dosage_form']);
    }

    return implode(' ', array_filter($bits));
}

/**
 * The generic, shown only when it adds something.
 *
 * A row named "Paracetamol 500mg" whose generic is also
 * paracetamol does not need "(paracetamol)" after it — that is
 * noise on every line of a busy screen. Returns an empty string
 * when the name already carries it.
 */
function drug_generic_note(array $row): string
{
    $generic = trim((string) ($row['generic_name'] ?? ''));
    if ($generic === '') {
        return '';
    }
    if (stripos((string) ($row['name'] ?? ''), $generic) !== false) {
        return '';
    }
    return $generic;
}
