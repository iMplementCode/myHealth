<?php

/**
 * ============================================================
 *  My leave
 * ------------------------------------------------------------
 *  The one page the Staff role can reach, and the page they land
 *  on when they sign in. Their balances, a form to apply, and
 *  what has happened to everything they have asked for.
 *
 *  Everything on it is scoped by hr_current_employee(), which
 *  reads the session and nothing else. No employee id is ever
 *  taken from the request — which is what stops somebody reading
 *  a colleague's record by editing a number in the address bar.
 *  The same is true of the actions: a request id IS accepted, and
 *  then checked to belong to the person asking before anything
 *  happens to it.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/icons.php';
require_once __DIR__ . '/../../includes/uploads.php';

require_login();
require_tables('employees', 'leave_requests');

$me = hr_current_employee();

/* ── POST: apply, withdraw, attach a note ─────────────────── */
if (is_post()) {
    csrf_check();

    if (!$me) {
        flash('error', 'Your login is not attached to an employee record yet. An administrator can do that.');
        redirect('hr/my_leave.php');
    }

    $action = $_POST['_action'] ?? '';

    if ($action === 'apply') {
        //  The employee is taken from the session, not the form.
        $doc = null;
        if (!empty($_FILES['document']['name'])) {
            $stored = store_uploaded_document($_FILES['document'], 'leave');
            if ($stored['error']) {
                flash('error', $stored['error']);
                redirect('hr/my_leave.php');
            }
            $doc = $stored['file']['url'] ?? null;
        }

        $r = leave_submit([
            'employee_id'   => (int) $me['employee_id'],
            'leave_type_id' => input_int($_POST, 'leave_type_id') ?? 0,
            'start_date'    => input($_POST, 'start_date'),
            'end_date'      => input($_POST, 'end_date'),
            'reason'        => input($_POST, 'reason'),
            'contact'       => input($_POST, 'contact'),
            'document_url'  => $doc,
        ]);
        flash($r['ok'] ? 'success' : 'error', $r['message']);
        redirect('hr/my_leave.php');
    }

    if ($action === 'withdraw') {
        $r = leave_decide(input_int($_POST, 'request_id') ?? 0, 'withdraw');
        flash($r['ok'] ? 'success' : 'error', $r['message']);
        redirect('hr/my_leave.php');
    }

    if ($action === 'attach') {
        $id = input_int($_POST, 'request_id') ?? 0;
        if (empty($_FILES['document']['name'])) {
            flash('error', 'No file was chosen.');
            redirect('hr/my_leave.php');
        }
        $stored = store_uploaded_document($_FILES['document'], 'leave');
        if ($stored['error']) {
            flash('error', $stored['error']);
            redirect('hr/my_leave.php');
        }
        //  leave_attach_document() re-checks that the request is
        //  this person's; the id above is not trusted on its own.
        $r = leave_attach_document($id, (string) $stored['file']['url']);
        flash($r['ok'] ? 'success' : 'error', $r['message']);
        redirect('hr/my_leave.php');
    }

    redirect('hr/my_leave.php');
}

$year     = (int) date('Y');
$requests = $me ? leave_requests_list(['employee_id' => (int) $me['employee_id']], 50) : [];

//  Every type, each carrying why this person cannot take it —
//  shown on the form rather than discovered after filling it in.
$typeOptions = [];
foreach (leave_types_all() as $t) {
    $typeOptions[] = $t + ['blocked' => $me ? leave_eligibility($me, $t) : 'No employee record.'];
}

/*  Balances only for what this person could actually take.
 *
 *  Showing all of them put a "Paternity leave — 14 days left"
 *  tile in front of a woman, and a maternity tile in front of a
 *  man. The figure was right and the question was wrong: a
 *  balance is what you have to spend, and an entitlement you are
 *  not eligible for is not that.                                */
$balances = [];
if ($me) {
    //  Three queries for all of them, rather than three per type —
    //  then drop the ones this person is not eligible for.
    $blocked = [];
    foreach ($typeOptions as $t) {
        $blocked[(int) $t['leave_type_id']] = $t['blocked'];
    }
    foreach (leave_balances_for((int) $me['employee_id'], $year) as $b) {
        if (($blocked[(int) $b['leave_type_id']] ?? null) === null) {
            $balances[] = $b;
        }
    }
}

$pageTitle    = 'My leave';
$pageSubtitle = $me ? hr_full_name($me) . ' · ' . $me['job_title'] : 'Your leave';
$pageStyles   = ['forms.css', 'dashboard.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'My leave']];

require __DIR__ . '/../../includes/header.php';
?>

<?php if (!$me): ?>

    <div class="state-block">
        <h1 class="state-title">No employee record yet</h1>
        <p class="state-text">
            Your login is not attached to an employee record, so there is no leave
            entitlement to show. An administrator can attach it from
            <strong>People → Employees</strong>.
        </p>
    </div>

<?php else: ?>

    <section class="mini-grid">
        <?php foreach ($balances as $b):
            $bal = $b['balance'];
            if ($bal['uncapped']) {
                continue;   // no allowance to show a figure for
            }
        ?>
            <div class="mini-card">
                <span class="mini-label"><?= e($b['name']) ?></span>
                <span class="mini-value"><?= e(rtrim(rtrim(number_format((float) $bal['available'], 1), '0'), '.')) ?></span>
                <span class="mini-sub">
                    of <?= e((string) (int) ($bal['entitled'] + $bal['carried'] + $bal['adjustment'])) ?> days left
                    <?php if ($bal['pending'] > 0): ?>
                        · <?= e(rtrim(rtrim(number_format($bal['pending'], 1), '0'), '.')) ?> awaiting a decision
                    <?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="activity-row">
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Apply for leave</h2></div>
            <div class="u-pad">
                <form method="POST" action="<?= e(url('hr/my_leave.php')) ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="apply">

                    <div class="form-group">
                        <label class="form-label" for="leave_type_id">Kind of leave <span class="req">*</span></label>
                        <select name="leave_type_id" id="leave_type_id" class="form-control" required>
                            <option value="">— choose —</option>
                            <?php foreach ($typeOptions as $t): ?>
                                <option value="<?= (int) $t['leave_type_id'] ?>"
                                        <?= $t['blocked'] ? 'disabled' : '' ?>>
                                    <?= e($t['name']) ?><?= $t['blocked'] ? ' — not available to you' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php foreach ($typeOptions as $t): ?>
                            <?php if ($t['blocked']): ?>
                                <p class="form-hint"><?= e($t['blocked']) ?></p>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="start_date">From <span class="req">*</span></label>
                            <input type="date" name="start_date" id="start_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="end_date">To <span class="req">*</span></label>
                            <input type="date" name="end_date" id="end_date" class="form-control" required>
                        </div>
                    </div>
                    <p class="form-hint">
                        Weekends and public holidays are not counted against annual or sick
                        leave. Maternity and paternity are counted in calendar days.
                    </p>

                    <div class="form-group">
                        <label class="form-label" for="reason">Reason</label>
                        <input type="text" name="reason" id="reason" class="form-control"
                               placeholder="Optional, but it helps whoever decides">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="contact">Contact while away</label>
                        <input type="text" name="contact" id="contact" class="form-control" maxlength="120">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="document">Supporting document</label>
                        <input type="file" name="document" id="document" class="form-control"
                               accept=".pdf,.jpg,.jpeg,.png,.webp">
                        <p class="form-hint">
                            Sick leave needs one before it can be approved. You can attach it
                            later if you do not have it yet. Only you and whoever decides your
                            leave can open it.
                        </p>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Send for approval</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">What you have asked for</h2></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Leave</th>
                            <th>When</th>
                            <th class="ta-right">Days</th>
                            <th>Status</th>
                            <th class="ta-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$requests): ?>
                            <tr><td colspan="5" class="table-empty">Nothing yet.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($requests as $r):
                            //  leave_may_withdraw() reads only employee_id
                            //  and status, both of which the list row
                            //  carries — so no second query per row.
                            $canWithdraw = leave_may_withdraw($r);
                            $needsDoc    = !empty($r['requires_document']) && empty($r['document_url'])
                                           && in_array($r['status'], ['submitted', 'dept_approved'], true);
                            $why = $r['final_note'] ?: $r['dept_note'];
                        ?>
                            <tr>
                                <td>
                                    <span class="cell-title"><?= e($r['type_name']) ?></span>
                                    <span class="cell-sub"><?= e($r['request_number']) ?></span>
                                </td>
                                <td class="cell-muted">
                                    <?= e(fmt_date($r['start_date'])) ?> – <?= e(fmt_date($r['end_date'])) ?>
                                </td>
                                <td class="ta-right"><?= e(rtrim(rtrim(number_format((float) $r['days_requested'], 1), '0'), '.')) ?></td>
                                <td>
                                    <span class="badge badge--<?= e(leave_status_tone($r['status'])) ?>">
                                        <?= e(leave_statuses()[$r['status']] ?? $r['status']) ?>
                                    </span>
                                    <?php if ($needsDoc): ?>
                                        <span class="cell-sub">Needs a document</span>
                                    <?php endif; ?>
                                    <?php if ($r['status'] === 'rejected' && $why): ?>
                                        <span class="cell-sub"><?= e($why) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="ta-right">
                                    <div class="row-actions">
                                        <?php if ($r['document_url']): ?>
                                            <a class="icon-btn" title="Open the attached document" target="_blank" rel="noopener"
                                               href="<?= e(private_document_url($r['document_url'])) ?>"><?= icon('file') ?></a>
                                        <?php endif; ?>
                                        <?php if ($needsDoc): ?>
                                            <button type="button" class="icon-btn" title="Attach a document"
                                                    data-modal-open="tplAttach" data-modal-title="Attach a document"
                                                    data-prefill="<?= e(json_encode(['request_id' => $r['request_id']], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
                                                <?= icon('plus') ?>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($canWithdraw): ?>
                                            <form method="POST" action="<?= e(url('hr/my_leave.php')) ?>" class="inline-form"
                                                  data-confirm="Withdraw <?= e($r['request_number']) ?>?">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="_action" value="withdraw">
                                                <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                                                <button type="submit" class="icon-btn icon-btn--danger" title="Withdraw"><?= icon('undo') ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <template id="tplAttach">
        <form method="POST" action="<?= e(url('hr/my_leave.php')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="attach">
            <input type="hidden" name="request_id" value="">
            <div class="form-group">
                <label class="form-label">Document</label>
                <input type="file" name="document" class="form-control" required
                       accept=".pdf,.jpg,.jpeg,.png,.webp">
                <p class="form-hint">A sick note, a burial permit. PDF or an image, up to 5 MB.</p>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Attach</button>
            </div>
        </form>
    </template>

<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
