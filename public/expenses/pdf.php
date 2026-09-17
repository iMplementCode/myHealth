<?php

/**
 * ============================================================
 *  Expense voucher (PDF)
 * ------------------------------------------------------------
 *  One expense, on paper. What it was for, who it went to, which
 *  account it came out of, and room for the signatures that make
 *  it a record rather than a note.
 *
 *  Money leaving the business is the half of the books that gets
 *  challenged — by an auditor, by a partner, by the person who
 *  paid it out of their own pocket and wants reimbursing. Every
 *  other document here could be printed and handed over; the
 *  expense could only be looked at on a screen.
 *
 *  ?dl=1 downloads it, anything else opens it for printing —
 *  the same flag every other PDF in the application reads.
 *
 *  Expenses are a Manager's business, so this is guarded the way
 *  the expense list is.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/document_pdf.php';
require_once __DIR__ . '/../../includes/cashbook.php';

require_login();
require_role(ROLE_MANAGER);

$id = input_int($_GET, 'id');
if ($id === null) {
    http_response_code(400);
    exit('No expense given.');
}

$e = db_one(
    "SELECT e.*,
            c.name AS category_name,
            a.name AS account_name,
            s.name AS supplier_name,
            TRIM(CONCAT(u.first_name, ' ', u.last_name))  AS created_by_name,
            TRIM(CONCAT(u2.first_name, ' ', u2.last_name)) AS updated_by_name
       FROM expenses e
       JOIN expense_categories c ON c.category_id = e.category_id
       LEFT JOIN cash_accounts a ON a.cash_account_id = e.cash_account_id
       LEFT JOIN suppliers s     ON s.supplier_id     = e.supplier_id
       LEFT JOIN users u         ON u.user_id         = e.created_by
       LEFT JOIN users u2        ON u2.user_id        = e.updated_by
      WHERE e.expense_id = :id",
    [':id' => $id]
);

if (!$e) {
    http_response_code(404);
    exit('That expense no longer exists.');
}

/*  Its own number. Expenses carry the supplier's receipt reference
 *  when there is one, but that is the *other* party's number and
 *  two suppliers can easily both call something "001". The voucher
 *  number is ours, derived from the row, and the receipt reference
 *  is printed as a separate fact.                                 */
$voucherNo = 'EXP-' . str_pad((string) $e['expense_id'], 5, '0', STR_PAD_LEFT);

/*  PAYMENT_METHODS in includes/cashbook.php is the list the rest of
 *  the application uses — cash, bank_transfer, mobile_money, cheque,
 *  card, salary_deduction, other. Writing the labels out again here
 *  produced a map with "mpesa" and "bank" in it, neither of which is
 *  a value this column can hold, so every voucher would have printed
 *  the raw database word instead.                                  */
$method = $e['payment_method']
    ? (PAYMENT_METHODS[$e['payment_method']]
        ?? ucfirst(str_replace('_', ' ', (string) $e['payment_method'])))
    : '';

// Expenses are recorded in the base currency — there is no
// currency column on the row — so this uses the same money()
// helper the rest of the application formats with.
$currency = 'KES';

stream_voucher_pdf([
    'title'    => 'Expense Voucher',
    'filename' => pdf_document_filename('Expense', $voucherNo),
    'download' => !empty($_GET['dl']),

    'meta' => [
        ['Voucher No', $voucherNo],
        ['Date',       date('d M, Y', strtotime((string) $e['expense_date']))],
        ['Status',     ucfirst((string) $e['status'])],
    ],

    'facts' => [
        ['Category',      $e['category_name']],
        ['Paid to',       $e['supplier_name']],
        ['Description',   $e['description']],
        ['Payment method', $method],
        ['Paid from',     $e['account_name']],
        ['Paid on',       $e['paid_on'] ? date('d M, Y', strtotime((string) $e['paid_on'])) : ''],
        ['Their receipt', $e['receipt_reference']],
        ['Recorded by',   $e['created_by_name']],
    ],

    'amount_label' => 'Amount paid',
    'amount'       => money($e['amount']),
    'words'        => amount_in_words((float) $e['amount'], $currency),

    'signatures' => ['Prepared by', 'Approved by', 'Received by'],

    'footer' => 'This voucher records money paid out by '
        . (company_settings()['company_name'] ?? APP_NAME)
        . '. Keep it with the supplier receipt it refers to.',
]);
