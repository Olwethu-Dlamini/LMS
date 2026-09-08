<?php
/**
 * Zero the Sick Leave and Unpaid Leave balances of every user.
 *
 *   php tools/zero_leave_balances.php                  # dry run, writes nothing
 *   php tools/zero_leave_balances.php --commit         # write, after confirming
 *
 * What "zero" means here
 * ---------------------------------------------------------------------------
 * A person's balance is one row in leave_entitlements per leave type per year,
 * and the number they can still book is:
 *
 *     available = total_days - used_days - pending_days
 *
 * This tool sets total_days = 0 for the chosen types. used_days and
 * pending_days are left alone, because they are the record of leave already
 * taken or already requested, and deleting that record would quietly rewrite
 * history: HR reports would show staff who never took a sick day. The
 * consequence is that anyone with days already on the clock ends up with a
 * NEGATIVE available figure, which is reported below before anything is
 * written. --zero-history clears those two columns as well, and says so.
 *
 * The allowance comes back unless you also change the policy
 * ---------------------------------------------------------------------------
 * leave_entitlements holds this year's number for each person; the DEFAULT it
 * was copied from lives in leave_types.max_days_per_year (Sick 10, Unpaid 30).
 * Three things still read that default and will re-allocate the old number
 * over the top of a zeroed row:
 *
 *   - HR > Leave Allocations > "Bulk Initialize Annual Allocations"
 *   - Administration > User Management, whenever an account is created
 *   - tools/seed_employees.php, for each new roster entry
 *
 * So zeroing the rows alone is a correction for this year that any of those
 * three undoes. --with-policy sets max_days_per_year = 0 for the same types,
 * which is what makes it stick.
 *
 * Options
 * ---------------------------------------------------------------------------
 *   --codes=SCK,UNP   Leave types to act on, by leave_types.code.
 *                     Default SCK,UNP (Sick Leave, Unpaid Leave).
 *   --year=2026       Entitlement year. Default the current year.
 *   --year=all        Every year on record, including closed ones.
 *   --with-policy     Also set leave_types.max_days_per_year = 0, so new
 *                     accounts and bulk re-initialisation stop handing the
 *                     days back out.
 *   --zero-history    Also set used_days = 0 and pending_days = 0. Destroys
 *                     the record of sick and unpaid leave already taken.
 *   --commit          Actually write. Without it nothing is changed.
 *   --yes             Skip the typed confirmation. Required when --commit runs
 *                     without a terminal (cron, CI, ssh with no tty).
 *
 * Before you run it against production
 * ---------------------------------------------------------------------------
 * Take a dump first - this is not reversible from inside the application:
 *
 *     ./tools/export_database.sh --dump-only
 *
 * The tool also writes a rollback script containing the exact prior value of
 * every row it touches, to ~/ri-leave-exports/, outside the repository.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

// ---- arguments ------------------------------------------------------------
$commit      = in_array('--commit', $argv, true);
$assumeYes   = in_array('--yes', $argv, true);
$withPolicy  = in_array('--with-policy', $argv, true);
$zeroHistory = in_array('--zero-history', $argv, true);

$codes = ['SCK', 'UNP'];
$year  = (int)date('Y');
$allYears = false;

foreach ($argv as $arg) {
    if (strpos($arg, '--codes=') === 0) {
        $codes = array_values(array_filter(array_map(
            static fn(string $c): string => strtoupper(trim($c)),
            explode(',', substr($arg, 8))
        ), static fn(string $c): bool => $c !== ''));
    } elseif (strpos($arg, '--year=') === 0) {
        $value = strtolower(trim(substr($arg, 7)));
        if ($value === 'all') {
            $allYears = true;
        } else {
            $year = (int)$value;
        }
    }
}

$known = ['--commit', '--yes', '--with-policy', '--zero-history'];
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, $known, true)) {
        continue;
    }
    if (strpos($arg, '--codes=') === 0 || strpos($arg, '--year=') === 0) {
        continue;
    }
    fwrite(STDERR, "Unknown option: $arg\nRun with no options for a dry run; see the header of this file.\n");
    exit(2);
}

if (!$codes) {
    fwrite(STDERR, "--codes= was given with no leave type codes in it.\n");
    exit(2);
}
if (!$allYears && ($year < 2000 || $year > 2100)) {
    fwrite(STDERR, "--year=$year is not a plausible leave year.\n");
    exit(2);
}

$db = getDBConnection();

// ---- which database is this? ---------------------------------------------
// Printed on every run, dry or not. The same command points at a developer
// laptop, the UAT instance and the live server depending only on DB_* in the
// environment, and the operator is entitled to see which one answered before
// deciding to type yes.
printf("Target   : %s@%s:%s/%s\n", DB_USER, DB_HOST, DB_PORT, DB_NAME);
printf("Types    : %s\n", implode(', ', $codes));
printf("Year     : %s\n", $allYears ? 'all years on record' : (string)$year);
printf("Policy   : %s\n", $withPolicy
    ? 'max_days_per_year -> 0 as well (change persists for new accounts)'
    : 'left alone (see --with-policy: new accounts get the old allowance back)');
printf("History  : %s\n", $zeroHistory
    ? 'used_days and pending_days ALSO cleared (record of leave taken is lost)'
    : 'used_days and pending_days preserved');
echo "\n";

// ---- resolve the leave types ---------------------------------------------
$placeholders = implode(',', array_fill(0, count($codes), '?'));
$stmtTypes = $db->prepare("SELECT id, code, name, max_days_per_year FROM leave_types WHERE code IN ($placeholders)");
$stmtTypes->execute($codes);
$types = $stmtTypes->fetchAll();

$foundCodes = array_column($types, 'code');
$missing    = array_diff($codes, $foundCodes);
if ($missing) {
    fwrite(STDERR, "No leave type with code: " . implode(', ', $missing) . "\n");
    fwrite(STDERR, "Codes on this database: "
        . implode(', ', $db->query("SELECT code FROM leave_types ORDER BY code")->fetchAll(PDO::FETCH_COLUMN)) . "\n");
    exit(1);
}

$typeIds  = array_map('intval', array_column($types, 'id'));
$typeById = [];
foreach ($types as $t) {
    $typeById[(int)$t['id']] = $t;
    printf("  %-4s %-24s allowance now %d day(s)/year\n", $t['code'], $t['name'], (int)$t['max_days_per_year']);
}
echo "\n";

$idPlaceholders = implode(',', array_fill(0, count($typeIds), '?'));
$yearClause     = $allYears ? '' : ' AND e.year = ?';

// ---- what would change ---------------------------------------------------
$params = $typeIds;
if (!$allYears) {
    $params[] = $year;
}

$stmtRows = $db->prepare("
    SELECT e.id, e.user_id, e.leave_type_id, e.year,
           e.total_days, e.used_days, e.pending_days,
           u.emp_id, u.first_name, u.last_name, u.email, u.status
    FROM leave_entitlements e
    JOIN users u ON u.id = e.user_id
    WHERE e.leave_type_id IN ($idPlaceholders)$yearClause
    ORDER BY u.first_name ASC, u.last_name ASC, e.year DESC, e.leave_type_id ASC
");
$stmtRows->execute($params);
$rows = $stmtRows->fetchAll();

if (!$rows) {
    echo "Nothing to do: no entitlement rows match those types and that year.\n";
    exit(0);
}

$alreadyZero = 0;
$toZero      = [];
$negatives   = [];
foreach ($rows as $r) {
    $historyClean = ((float)$r['used_days'] === 0.0 && (float)$r['pending_days'] === 0.0);
    if ((float)$r['total_days'] === 0.0 && (!$zeroHistory || $historyClean)) {
        $alreadyZero++;
        continue;
    }
    $toZero[] = $r;
    if (!$zeroHistory && ((float)$r['used_days'] > 0 || (float)$r['pending_days'] > 0)) {
        $negatives[] = $r;
    }
}

$people = count(array_unique(array_column($rows, 'user_id')));
printf("%d entitlement row(s) across %d user(s) match.\n", count($rows), $people);
printf("  to change    : %d\n", count($toZero));
printf("  already zero : %d\n", $alreadyZero);
echo "\n";

if ($toZero) {
    printf("%-10s  %-26s  %-4s  %-6s  %8s  %8s  %8s\n",
        'EMP ID', 'NAME', 'TYPE', 'YEAR', 'TOTAL', 'USED', 'PENDING');
    foreach ($toZero as $r) {
        printf("%-10s  %-26s  %-4s  %-6d  %8.2f  %8.2f  %8.2f\n",
            $r['emp_id'],
            trim($r['first_name'] . ' ' . $r['last_name']),
            $typeById[(int)$r['leave_type_id']]['code'],
            (int)$r['year'],
            (float)$r['total_days'],
            (float)$r['used_days'],
            (float)$r['pending_days']
        );
    }
    echo "\n";
}

// ---- the two things worth reading before typing yes ----------------------
if ($negatives) {
    printf("WARNING: %d row(s) have days already used or reserved. Zeroing the\n", count($negatives));
    echo "allowance underneath them leaves a negative available balance, which the\n";
    echo "dashboard and HR reports will display:\n\n";
    foreach ($negatives as $r) {
        $available = -((float)$r['used_days'] + (float)$r['pending_days']);
        printf("  %-10s %-26s %-4s %d  available becomes %+.2f\n",
            $r['emp_id'], trim($r['first_name'] . ' ' . $r['last_name']),
            $typeById[(int)$r['leave_type_id']]['code'], (int)$r['year'], $available);
    }
    echo "\nNothing breaks - no new request can pass a zero balance either way. But if\n";
    echo "you want a clean set of books, settle these first: cancel or reject the\n";
    echo "in-flight requests in the application, which releases pending_days\n";
    echo "properly and notifies the applicant, then re-run this.\n\n";
}

// In-flight applications are named explicitly. pending_days is only ever
// released by the approval workflow, so a request left in the queue will keep
// moving numbers around after this tool has finished.
$stmtFlight = $db->prepare("
    SELECT a.application_no, a.status, a.start_date, a.end_date, a.total_days,
           a.leave_type_id, u.emp_id, u.first_name, u.last_name
    FROM leave_applications a
    JOIN users u ON u.id = a.user_id
    WHERE a.leave_type_id IN ($idPlaceholders)
      AND a.status IN ('pending_manager', 'pending_hr', 'pending_executive')
    ORDER BY a.start_date ASC
");
$stmtFlight->execute($typeIds);
$inFlight = $stmtFlight->fetchAll();

if ($inFlight) {
    printf("%d sick/unpaid request(s) are still in the approval queue:\n\n", count($inFlight));
    foreach ($inFlight as $a) {
        printf("  %-16s %-10s %-26s %-4s %s to %s  %.2f day(s)  [%s]\n",
            $a['application_no'], $a['emp_id'],
            trim($a['first_name'] . ' ' . $a['last_name']),
            $typeById[(int)$a['leave_type_id']]['code'],
            $a['start_date'], $a['end_date'], (float)$a['total_days'], $a['status']);
    }
    echo "\nEach one still holds pending_days. Approving it moves those days into\n";
    echo "used_days against a total of zero; rejecting or cancelling it releases them.\n\n";
}

if (!$commit) {
    echo "=== DRY RUN. Nothing was written. Pass --commit to apply. ===\n";
    exit(0);
}

// ---- confirmation --------------------------------------------------------
$confirmWord = 'ZERO';
if (!$assumeYes) {
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, "--commit needs a terminal to confirm on. Pass --yes to run unattended.\n");
        exit(2);
    }
    printf("About to zero %d row(s) on %s. Type %s to continue: ", count($toZero), DB_NAME, $confirmWord);
    $answer = trim((string)fgets(STDIN));
    if ($answer !== $confirmWord) {
        echo "Aborted. Nothing was written.\n";
        exit(1);
    }
}

// ---- rollback script, written before the change --------------------------
// Outside the repository, for the same reason the database dumps are: these
// rows describe real staff. One UPDATE per row, restoring exactly what was
// there, so a mistake is undone without restoring a whole dump.
$stamp    = date('Ymd-His');
$outDir   = getenv('HOME') . '/ri-leave-exports';
@mkdir($outDir, 0700, true);
$rollback = "$outDir/rollback-zero-leave-balances-$stamp.sql";

$fh = fopen($rollback, 'w');
if ($fh === false) {
    fwrite(STDERR, "Could not write the rollback script to $rollback. Refusing to continue.\n");
    exit(1);
}
fwrite($fh, "-- Rollback for tools/zero_leave_balances.php run at " . date('c') . "\n");
fwrite($fh, "-- Database: " . DB_NAME . " on " . DB_HOST . "\n");
fwrite($fh, "-- Types: " . implode(', ', $codes) . "   Year: " . ($allYears ? 'all' : $year) . "\n");
fwrite($fh, "-- Apply with: mysql " . DB_NAME . " < " . basename($rollback) . "\n\n");
fwrite($fh, "START TRANSACTION;\n");
foreach ($toZero as $r) {
    fwrite($fh, sprintf(
        "UPDATE leave_entitlements SET total_days = %.2f, used_days = %.2f, pending_days = %.2f WHERE id = %d;\n",
        (float)$r['total_days'], (float)$r['used_days'], (float)$r['pending_days'], (int)$r['id']
    ));
}
if ($withPolicy) {
    foreach ($types as $t) {
        fwrite($fh, sprintf(
            "UPDATE leave_types SET max_days_per_year = %d WHERE id = %d; -- %s\n",
            (int)$t['max_days_per_year'], (int)$t['id'], $t['code']
        ));
    }
}
fwrite($fh, "COMMIT;\n");
fclose($fh);
@chmod($rollback, 0600);
printf("\nRollback script written first:\n  %s\n\n", $rollback);

// ---- the change ----------------------------------------------------------
$setClause = $zeroHistory
    ? 'total_days = 0, used_days = 0, pending_days = 0'
    : 'total_days = 0';

try {
    $db->beginTransaction();

    $stmtZero = $db->prepare("
        UPDATE leave_entitlements e
        SET $setClause
        WHERE e.leave_type_id IN ($idPlaceholders)$yearClause
    ");
    $stmtZero->execute($params);
    $changed = $stmtZero->rowCount();

    $policyChanged = 0;
    if ($withPolicy) {
        $stmtPolicy = $db->prepare("UPDATE leave_types SET max_days_per_year = 0 WHERE id IN ($idPlaceholders)");
        $stmtPolicy->execute($typeIds);
        $policyChanged = $stmtPolicy->rowCount();
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "FAILED, rolled back, nothing changed: " . $e->getMessage() . "\n");
    exit(1);
}

printf("=== COMMITTED ===\n");
printf("entitlement rows written : %d\n", $changed);
if ($withPolicy) {
    printf("leave types re-policied  : %d (max_days_per_year = 0)\n", $policyChanged);
}

// ---- post-change check ---------------------------------------------------
// Read it back rather than trusting rowCount: MySQL reports 0 changed rows for
// a value that was already correct, so the count alone cannot tell "nothing
// needed doing" apart from "nothing happened".
$stmtCheck = $db->prepare("
    SELECT COUNT(*) FROM leave_entitlements e
    WHERE e.leave_type_id IN ($idPlaceholders)$yearClause
      AND e.total_days <> 0
");
$stmtCheck->execute($params);
$stragglers = (int)$stmtCheck->fetchColumn();

if ($stragglers > 0) {
    fwrite(STDERR, "\nCHECK FAILED: $stragglers row(s) still hold a non-zero allowance.\n");
    exit(1);
}
echo "check: every matching row now reads 0 allowance.\n";

if (!$withPolicy) {
    echo "\nReminder: leave_types.max_days_per_year is unchanged, so creating an\n";
    echo "account or running HR's bulk initialisation will hand these days back out.\n";
    echo "Re-run with --with-policy to make the change stick.\n";
}
