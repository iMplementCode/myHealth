<?php

/**
 * ============================================================
 *  Notes
 * ------------------------------------------------------------
 *  The place to write down what does not fit on a document.
 *
 *  Everything that decides who may see or change a note lives in
 *  this file, in note_visible_sql() and note_may_edit(). The
 *  pages ask; they do not decide. That is deliberate: the last
 *  authorisation bug in this system came from two pieces of code
 *  answering the same question differently, and a rule written
 *  once cannot disagree with itself.
 * ============================================================
 */

declare(strict_types=1);

// Not a page: refuse to run when requested directly over HTTP.
// CLI tools (migrations, cron) legitimately include this file, so the
// guard only applies to web requests.
if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * What kinds of note this business writes.
 *
 * Slug => label. Stored as the slug so a label can be reworded
 * without rewriting rows, and unknown slugs still render as
 * themselves rather than vanishing.
 */
function note_categories(): array
{
    return [
        'general'      => 'General',
        'meeting'      => 'Meeting',
        'site_survey'  => 'Site survey',
        'installation' => 'Installation',
        'customer'     => 'Customer',
        'supplier'     => 'Supplier',
        'internal'     => 'Internal',
    ];
}

/** The label for a stored category slug, or the slug itself. */
function note_category_label(?string $slug): string
{
    $slug = (string) $slug;
    return note_categories()[$slug] ?? ($slug !== '' ? ucfirst(str_replace('_', ' ', $slug)) : 'General');
}

/** Is this a category we store? Anything else is refused on save. */
function note_category_valid(string $slug): bool
{
    return array_key_exists($slug, note_categories());
}

/**
 * The WHERE fragment that limits a note list to what the signed-in
 * user may see, and the parameters it needs.
 *
 * Shared notes are visible to anybody signed in. A private note is
 * visible to its author. An administrator sees everything, which is
 * stated here rather than implied — they can read the database
 * directly, so a rule that pretended otherwise would only be
 * misleading whoever reads this next.
 *
 * @param string $alias  table alias used in the caller's query
 * @return array{0: string, 1: array}  [sql, params]
 */
function note_visible_sql(string $alias = 'n'): array
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('note_visible_sql: bad alias.');
    }
    if (is_admin()) {
        return ['TRUE', []];
    }
    $me = (int) (current_user()['id'] ?? 0);
    return [
        "({$alias}.visibility = 'shared' OR {$alias}.created_by = :note_me)",
        [':note_me' => $me],
    ];
}

/** May the signed-in user change or delete this note? */
function note_may_edit(?array $note): bool
{
    if (!$note) {
        return false;
    }
    if (is_admin()) {
        return true;
    }
    return (int) ($note['created_by'] ?? 0) === (int) (current_user()['id'] ?? 0);
}

/**
 * One note by id, or null when it does not exist OR the signed-in
 * user may not see it.
 *
 * Both answers are null on purpose. Telling somebody "that note
 * exists but is not yours" tells them a note exists, and the id is
 * a number they can count through.
 */
function note_get(int $id): ?array
{
    [$visible, $params] = note_visible_sql('n');
    return db_one(
        "SELECT n.*, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS author_name
           FROM notes n
           LEFT JOIN users u ON u.user_id = n.created_by
          WHERE n.note_id = :id AND $visible",
        $params + [':id' => $id]
    );
}

/**
 * A one-line summary of a note's body, for a list row.
 *
 * Newlines become spaces so a note written as bullet points does
 * not turn a table row into a paragraph.
 */
function note_excerpt(?string $body, int $length = 120): string
{
    $flat = trim(preg_replace('/\s+/', ' ', (string) $body) ?? '');
    if ($flat === '') {
        return '';
    }
    if (function_exists('mb_strlen') ? mb_strlen($flat) <= $length : strlen($flat) <= $length) {
        return $flat;
    }
    $cut = function_exists('mb_substr') ? mb_substr($flat, 0, $length) : substr($flat, 0, $length);
    // Break on a word rather than mid-word, when there is one to
    // break on within reach of the end.
    $space = strrpos($cut, ' ');
    if ($space !== false && $space > $length - 20) {
        $cut = substr($cut, 0, $space);
    }
    return rtrim($cut) . '…';
}
