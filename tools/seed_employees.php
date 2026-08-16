<?php
/**
 * Seed the Real Image staff roster into the users table.
 *
 *   php tools/seed_employees.php            # dry run, prints what it would do
 *   php tools/seed_employees.php --commit   # actually write
 *
 * Each new account gets its own random temporary password and is flagged
 * must_change_password, so the holder is forced to set their own on first
 * sign-in. The temporary passwords are written to a CSV OUTSIDE the repository
 * ($HOME/.ri-leave-uat/initial-passwords.csv) so they can never be committed.
 *
 * Existing accounts are left completely untouched, so the script is safe to
 * re-run as the roster grows.
 *
 * Everyone is created as a plain 'employee' with no department and no reporting
 * manager, because the roster did not specify them. Assign roles, departments
 * and managers in Administration > User Management afterwards; until a manager
 * is set, only an admin can clear Stage 1 for that person.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

$commit  = in_array('--commit', $argv, true);
// --reissue gives a fresh temporary password to every account that is still
// awaiting its first sign-in. Use it when the credential list has been lost:
// stored passwords are bcrypt hashes and cannot be recovered, only replaced.
$reissue = in_array('--reissue', $argv, true);

/** Roster exactly as supplied. Entries without a display name are marked TBC. */
$roster = <<<'TXT'
Natasha Williamson <natasha@realnet.co.sz>
Ali Resting <ali@realnet.co.sz>
Celiwe Ngwenya <celiwen@realnet.co.sz>
Vamile Sikhondze <vamile@realnet.co.sz>
Trevor Chisenga <trevorc@realnet.co.sz>
Ntombenhle Dlamini <ntombenhle@realnet.co.sz>
sky@realnet.co.sz
max@realnet.co.sz
coach@realnet.co.sz
Sandile Warren Dlamini <sandilew@realnet.co.sz>
Mduduzi Gumedze <mduduzig@realnet.co.sz>
lindani@realnet.co.sz
Sisimo Seyama <sisimo@realnet.co.sz>
sihle@realnet.co.sz
Sandile Masuku <sandilem@realnet.co.sz>
Edem Agbeke <edem@realnet.co.sz>
Monde Radebe <monde@realnet.co.sz>
Muzi Phiri <muzip@realnet.co.sz>
reno@realnet.co.sz
Thanda Mndzebele <thanda@realnet.co.sz>
Nick Mdluli <nickm@realnet.co.sz>
Cydalia Smith <cydalia@realnet.co.sz>
Tania Middleton <tania@realnet.co.sz>
Janine Middleton <janinem@realnet.co.sz>
Sandile Dlamini <sandiled@realnet.co.sz>
Anele Dlamini <anele@realnet.co.sz>
Lorinda Bennett <lorinda@realnet.co.sz>
presidentl@realnet.co.sz
Mbongeni Sibandze <mbongeni@realnet.co.sz>
ndumiso@realnet.co.sz
banelet@realnet.co.sz
goodluck@realnet.co.sz
sphila@realnet.co.sz
TXT;

/** Split "First Last <a@b>" or a bare address into name parts + email. */
function parse_entry(string $line): ?array {
    $line = trim($line);
    if ($line === '') {
        return null;
    }
    if (preg_match('/^(.*?)\s*<\s*([^>]+)\s*>$/', $line, $m)) {
        $name  = trim($m[1]);
        $email = strtolower(trim($m[2]));
    } else {
        $name  = '';
        $email = strtolower($line);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    if ($name !== '') {
        $parts = preg_split('/\s+/', $name);
        $first = array_shift($parts);
        $last  = $parts ? implode(' ', $parts) : '(TBC)';
        $namedFromEmail = false;
    } else {
        // No display name supplied - derive a placeholder from the mailbox.
        $local = explode('@', $email)[0];
        $first = ucfirst(preg_replace('/[^a-z]/i', '', $local));
        $last  = '(TBC)';
        $namedFromEmail = true;
    }

    return [
        'first' => $first,
        'last'  => $last,
        'email' => $email,
        'tbc'   => $namedFromEmail,
    ];
}

/** Readable, reasonably strong temporary password meeting the app's policy. */
function temp_password(): string {
    $words = ['Mbabane', 'Lubombo', 'Hhohho', 'Manzini', 'Shiselweni', 'Malolotja',
              'Sibebe', 'Mantenga', 'Ezulwini', 'Nkomati', 'Ngwenya', 'Mlilwane'];
    $word = $words[random_int(0, count($words) - 1)];
    return $word . random_int(1000, 9999) . '#ri';
}

$db  = getDBConnection();
$now = date('Y-m-d H:i:s');
$year = (int)date('Y');

// Look up the plain employee role id rather than assuming it is 1.
$roleId = (int)$db->query("SELECT id FROM roles WHERE name = 'employee' LIMIT 1")->fetchColumn();
if ($roleId <= 0) {
    fwrite(STDERR, "Could not find the 'employee' role. Import schema.sql first.\n");
    exit(1);
}

// Next free EMP-xxxx sequence number.
$maxEmp = (int)$db->query("
    SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(emp_id, '-', -1) AS UNSIGNED)), 1000)
    FROM users WHERE emp_id LIKE 'EMP-%'
")->fetchColumn();
$nextSeq = max($maxEmp, 1000) + 1;

$leaveTypes = $db->query("SELECT id, max_days_per_year FROM leave_types")->fetchAll();

$stmtExists = $db->prepare("SELECT id FROM users WHERE email = :email");
$stmtInsert = $db->prepare("
    INSERT INTO users
      (emp_id, first_name, last_name, email, password_hash, role_id,
       department_id, manager_id, status, must_change_password)
    VALUES
      (:emp_id, :fn, :ln, :email, :pwd, :role_id, NULL, NULL, 'active', 1)
");
$stmtEnt = $db->prepare("
    INSERT INTO leave_entitlements (user_id, leave_type_id, year, total_days, used_days, pending_days)
    VALUES (:user_id, :type_id, :year, :total_days, 0, 0)
    ON DUPLICATE KEY UPDATE total_days = VALUES(total_days)
");

$created = [];
$skipped = [];
$invalid = [];
$tbcList = [];

foreach (explode("\n", $roster) as $line) {
    $entry = parse_entry($line);
    if ($entry === null) {
        if (trim($line) !== '') {
            $invalid[] = trim($line);
        }
        continue;
    }

    $stmtExists->execute(['email' => $entry['email']]);
    if ($stmtExists->fetchColumn()) {
        $skipped[] = $entry['email'];
        continue;
    }

    $empId = sprintf('EMP-%04d', $nextSeq++);
    $temp  = temp_password();

    if ($entry['tbc']) {
        $tbcList[] = $entry['email'];
    }

    if ($commit) {
        $db->beginTransaction();
        try {
            $stmtInsert->execute([
                'emp_id'  => $empId,
                'fn'      => $entry['first'],
                'ln'      => $entry['last'],
                'email'   => $entry['email'],
                'pwd'     => password_hash($temp, PASSWORD_BCRYPT),
                'role_id' => $roleId,
            ]);
            $newId = (int)$db->lastInsertId();

            foreach ($leaveTypes as $lt) {
                $stmtEnt->execute([
                    'user_id'    => $newId,
                    'type_id'    => $lt['id'],
                    'year'       => $year,
                    'total_days' => $lt['max_days_per_year'],
                ]);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            fwrite(STDERR, "FAILED {$entry['email']}: {$e->getMessage()}\n");
            continue;
        }
    }

    $created[] = [
        'emp_id' => $empId,
        'name'   => trim($entry['first'] . ' ' . $entry['last']),
        'email'  => $entry['email'],
        'temp'   => $temp,
    ];
}

// ---- report ---------------------------------------------------------------
printf("%s\n", $commit ? '=== SEEDING COMMITTED ===' : '=== DRY RUN (pass --commit to write) ===');
printf("to create : %d\n", count($created));
printf("skipped   : %d (already present)\n", count($skipped));
if ($invalid) {
    printf("unparsed  : %d -> %s\n", count($invalid), implode(', ', $invalid));
}
echo "\n";
printf("%-10s  %-26s  %s\n", 'EMP ID', 'NAME', 'EMAIL');
foreach ($created as $c) {
    printf("%-10s  %-26s  %s\n", $c['emp_id'], $c['name'], $c['email']);
}

// ---- optional: re-issue temporary passwords ------------------------------
$reissued = [];
if ($reissue) {
    $awaiting = $db->query("
        SELECT id, emp_id, first_name, last_name, email
        FROM users
        WHERE must_change_password = 1
        ORDER BY id ASC
    ")->fetchAll();

    $stmtPwd = $db->prepare("
        UPDATE users
        SET password_hash = :pwd, must_change_password = 1, password_changed_at = NULL
        WHERE id = :id
    ");

    foreach ($awaiting as $u) {
        // Skip anyone this run just created - they already have a fresh password.
        $alreadyFresh = false;
        foreach ($created as $c) {
            if ($c['email'] === $u['email']) { $alreadyFresh = true; break; }
        }
        if ($alreadyFresh) {
            continue;
        }
        $temp = temp_password();
        if ($commit) {
            $stmtPwd->execute(['pwd' => password_hash($temp, PASSWORD_BCRYPT), 'id' => $u['id']]);
        }
        $reissued[] = [
            'emp_id' => $u['emp_id'],
            'name'   => trim($u['first_name'] . ' ' . $u['last_name']),
            'email'  => $u['email'],
            'temp'   => $temp,
        ];
    }
    printf("\nre-issued : %d temporary password(s) for accounts awaiting first sign-in\n", count($reissued));
}

// ---- credential file -----------------------------------------------------
// APPENDED, never truncated: overwriting would destroy the only copy of
// temporary passwords already issued but not yet handed out.
$writeRows = array_merge($created, $reissued);
if ($commit && $writeRows) {
    $csvPath = getenv('HOME') . '/.ri-leave-uat/initial-passwords.csv';
    @mkdir(dirname($csvPath), 0700, true);
    $isNew = !file_exists($csvPath) || filesize($csvPath) === 0;
    $fh = fopen($csvPath, 'a');
    if ($isNew) {
        fputcsv($fh, ['emp_id', 'name', 'email', 'temporary_password', 'issued_at']);
    }
    foreach ($writeRows as $c) {
        fputcsv($fh, [$c['emp_id'], $c['name'], $c['email'], $c['temp'], $now]);
    }
    fclose($fh);
    @chmod($csvPath, 0600);
    echo "\n" . count($writeRows) . " credential row(s) appended to:\n  $csvPath\n";
    echo "(outside the repository, mode 0600 - distribute securely, then delete the file)\n";
    echo "Newest row wins where an email appears more than once.\n";
}

if ($tbcList) {
    echo "\nNo surname supplied for these - set the real name in User Management:\n";
    foreach ($tbcList as $e) {
        echo "  - $e\n";
    }
}

echo "\nEveryone was created as role 'employee' with no department and no reporting\n";
echo "manager. Assign those in Administration > User Management; until a manager is\n";
echo "set, only an admin can clear Stage 1 approval for that person.\n";
