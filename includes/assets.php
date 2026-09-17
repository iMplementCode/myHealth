<?php

/**
 * ============================================================
 *  Fixed assets — what the business owns and uses
 * ------------------------------------------------------------
 *  Desks, laptops, ladders, the van. Owned, used to run the
 *  business, not for sale.
 *
 *  Everything the daily position called an asset before this
 *  was a **current** asset: stock, cash, money owed to us, all
 *  of it turning into cash within a year. Fixed assets are the
 *  other kind, and leaving them out understated the net asset
 *  value by whatever the business had spent equipping itself.
 *
 *  ── Cost is not value ───────────────────────────────────────
 *  A laptop bought for 120,000 in 2022 is not worth 120,000
 *  today. Each asset is carried at
 *
 *      net book value = cost − accumulated depreciation
 *
 *  written down in a straight line over its useful life and
 *  floored at its residual. Straight line because it is the
 *  method somebody can check by hand.
 *
 *  Nothing is stored but the facts. The depreciation is
 *  computed on the way past — by `fixed_asset_nbv()` in the
 *  database, so SQL and PHP cannot disagree about it — which
 *  means the register is right on whatever date it is asked
 *  about, including a date in the past.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * What kinds of thing a business owns, and how long each is
 * reckoned to last.
 *
 * The life is a starting point, not a rule: it prefills the form
 * and every asset keeps its own, because a laptop that lives on a
 * workbench and one that lives in a bag do not last the same time.
 *
 * Land is the one thing that never wears out, so it carries a life
 * of zero, which means never written down.
 */
const FIXED_ASSET_CATEGORIES = [
    'Furniture and fittings'   => ['months' => 96,  'hint' => 'Desks, chairs, shelving, counters'],
    'Computers and IT'         => ['months' => 36,  'hint' => 'Laptops, desktops, servers, network gear'],
    'Office equipment'         => ['months' => 60,  'hint' => 'Printers, phones, air conditioning'],
    'Tools and machinery'      => ['months' => 60,  'hint' => 'Ladders, crimpers, testers, power tools'],
    'Security and CCTV'        => ['months' => 60,  'hint' => 'Our own installed cameras, alarms, gates'],
    'Motor vehicles'           => ['months' => 60,  'hint' => 'Vans, motorcycles'],
    'Buildings and improvements' => ['months' => 300, 'hint' => 'Premises, partitioning, wiring a unit out'],
    'Land'                     => ['months' => 0,   'hint' => 'Never depreciated — land does not wear out'],
];

/** The default life for a category, in months. */
function fixed_asset_default_life(string $category): int
{
    return FIXED_ASSET_CATEGORIES[$category]['months'] ?? 60;
}

/** Whether a category is one this system knows. */
function fixed_asset_category_valid(string $category): bool
{
    return isset(FIXED_ASSET_CATEGORIES[$category]);
}

/**
 * The register as it stood on a date.
 *
 * Anything bought after that date was not owned yet, and anything
 * disposed of on or before it had already gone — the same rule the
 * rest of the daily position follows, so the two pages can never
 * describe different businesses.
 *
 * `$scope`:
 *   held      what was owned on the date  (the default)
 *   disposed  what had already gone
 *   all       everything ever recorded
 */
function fixed_asset_rows(?string $asOf = null, string $scope = 'held', string $search = ''): array
{
    $asOf = $asOf ?: date('Y-m-d');

    $where  = [];
    $params = [':as_of1' => $asOf, ':as_of2' => $asOf, ':as_of3' => $asOf, ':as_of4' => $asOf];

    if ($scope === 'held') {
        $where[] = "a.purchase_date <= :as_of3
                    AND (a.disposed_on IS NULL OR a.disposed_on > :as_of4)";
    } elseif ($scope === 'disposed') {
        $where[] = "a.disposed_on IS NOT NULL AND a.disposed_on <= :as_of4
                    AND a.purchase_date <= :as_of3";
    } else {
        // Every row, but the placeholders still have to be bound.
        $where[] = "(:as_of3 IS NOT NULL AND :as_of4 IS NOT NULL)";
    }

    if ($search !== '') {
        $where[] = "(LOWER(a.name) LIKE :q OR LOWER(COALESCE(a.asset_tag,'')) LIKE :q
                     OR LOWER(COALESCE(a.serial_number,'')) LIKE :q
                     OR LOWER(COALESCE(a.location,'')) LIKE :q
                     OR LOWER(a.category) LIKE :q)";
        $params[':q'] = '%' . strtolower($search) . '%';
    }

    return db_all(
        "SELECT a.*,
                s.name AS supplier_name,
                fixed_asset_nbv(a.cost, a.residual_value, a.useful_life_months,
                                a.purchase_date, :as_of1) AS book_value,
                a.cost - fixed_asset_nbv(a.cost, a.residual_value, a.useful_life_months,
                                         a.purchase_date, :as_of2) AS depreciation
           FROM fixed_assets a
           LEFT JOIN suppliers s ON s.supplier_id = a.supplier_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY a.purchase_date DESC, a.asset_id DESC",
        $params
    );
}

/**
 * What the fixed assets were worth on a date — the one figure the
 * daily position adds in.
 *
 * Computed in SQL rather than by summing PHP rows so the page and
 * the position cannot drift apart, and so a hundred assets cost
 * one query.
 */
function finance_fixed_assets(?string $asOf = null): float
{
    if (!table_exists('fixed_assets')) {
        return 0.0;
    }
    $asOf = $asOf ?: date('Y-m-d');

    return (float) db_value(
        "SELECT COALESCE(SUM(
                    fixed_asset_nbv(cost, residual_value, useful_life_months,
                                    purchase_date, :as_of1)
                ), 0)
           FROM fixed_assets
          WHERE purchase_date <= :as_of2
            AND (disposed_on IS NULL OR disposed_on > :as_of3)",
        [':as_of1' => $asOf, ':as_of2' => $asOf, ':as_of3' => $asOf]
    );
}

/** Cost, depreciation and book value across a set of rows. */
function fixed_asset_totals(array $rows): array
{
    $cost = $depn = $book = 0.0;
    foreach ($rows as $r) {
        $cost += (float) $r['cost'];
        $depn += (float) $r['depreciation'];
        $book += (float) $r['book_value'];
    }
    return ['items' => count($rows), 'cost' => $cost,
            'depreciation' => $depn, 'book_value' => $book];
}

/** The same, one line per category. */
function fixed_assets_by_category(?string $asOf = null): array
{
    $asOf = $asOf ?: date('Y-m-d');

    return db_all(
        "SELECT category,
                COUNT(*) AS items,
                SUM(cost) AS cost,
                SUM(fixed_asset_nbv(cost, residual_value, useful_life_months,
                                    purchase_date, :as_of1)) AS book_value,
                SUM(cost) - SUM(fixed_asset_nbv(cost, residual_value, useful_life_months,
                                                purchase_date, :as_of2)) AS depreciation
           FROM fixed_assets
          WHERE purchase_date <= :as_of3
            AND (disposed_on IS NULL OR disposed_on > :as_of4)
          GROUP BY category
          ORDER BY book_value DESC",
        [':as_of1' => $asOf, ':as_of2' => $asOf, ':as_of3' => $asOf, ':as_of4' => $asOf]
    );
}

/**
 * How much life an asset has left, in words.
 *
 * The number people actually want off a register is not the
 * depreciation — it is whether the thing is about to need
 * replacing.
 */
function fixed_asset_life_left(array $row, ?string $asOf = null): string
{
    $life = (int) $row['useful_life_months'];
    if ($life <= 0) {
        return 'Not depreciated';
    }
    $asOf  = $asOf ?: date('Y-m-d');
    $start = new DateTimeImmutable($row['purchase_date']);
    $now   = new DateTimeImmutable($asOf);
    $used  = ($now->diff($start)->y * 12) + $now->diff($start)->m;
    $left  = $life - $used;

    if ($left <= 0) {
        return 'Written off';
    }
    if ($left < 12) {
        return $left . ' month' . ($left === 1 ? '' : 's') . ' left';
    }
    $years = intdiv($left, 12);
    $months = $left % 12;
    return $years . ' yr' . ($years === 1 ? '' : 's')
         . ($months ? ' ' . $months . ' mo' : '') . ' left';
}
