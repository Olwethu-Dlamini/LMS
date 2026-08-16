<?php
/**
 * Create the first system administrator.
 *
 *   php tools/create_admin.php --email you@realnet.co.sz --name "Your Name"
 *
 * schema.sql seeds no accounts, so a fresh install has nobody to sign in as.
 * This is the way in. It exists instead of a seeded admin because a seeded
 * account has a password that lives in the repository, which means every
 * installation shares it and nobody remembers to change it.
 *
 * The password is generated here, printed once, and never stored anywhere but
 * the bcrypt hash in the database. The account is flagged must_change_password,
 * so whoever holds it is made to set their own on first sign-in - the printed
 * one is only good enough to get through the door.
 *
 * Safe to re-run: an existing email is reported and left alone. Use
 * --reset-password to issue a fresh temporary password for an account that is
 * locked out, which is the supported way back in when the only administrator
 * has lost theirs.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

/** Read --flag value from the command line. */
function arg_value(array $argv, string $flag): ?string {
    $index = array_search($flag, $argv, true);
    if ($index === false || !isset($argv[$index + 1])) {
        return null;
    }
    return $argv[$index + 1];
}

$email = trim((string)arg_value($argv, '--email'));
$name  = trim((string)(arg_value($argv, '--name') ?? ''));
$reset = in_array('--reset-password', $argv, true);

if ($email === '') {
    fwrite(STDERR, "Usage: php tools/create_admin.php --email you@realnet.co.sz --name \"Your Name\"\n"
                 . "       php tools/create_admin.php --email you@realnet.co.sz --reset-password\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "That does not look like an email address: {$email}\n");
    exit(1);
}

/**
 * A temporary password that satisfies the policy and is readable down a phone
 * line. It is replaced on first sign-in, so it only has to survive being typed
 * once.
 */
function temporary_password(): string {
    $words = ['Mbabane', 'Lubombo', 'Malolotja', 'Hlane', 'Manzini', 'Ezulwini'];
    return $words[random_int(0, count($words) - 1)] . random_int(1000, 9999) . '#ri';
}

$db = getDBConnection();

$stmt = $db->prepare("
    SELECT u.id, u.emp_id, r.name AS role_name
    FROM users u JOIN roles r ON r.id = u.role_id
    WHERE u.email = :email
");
$stmt->execute(['email' => $email]);
$existing = $stmt->fetch();

$password = temporary_password();

if ($existing && !$reset) {
    echo "An account already exists for {$email} ({$existing['emp_id']}, {$existing['role_name']}).\n";
    echo "Nothing was changed. Use --reset-password to issue a new temporary password.\n";
    exit(0);
}

if ($existing) {
    $stmtReset = $db->prepare("
        UPDATE users
        SET password_hash = :pwd, must_change_password = 1, password_changed_at = NULL
        WHERE id = :id
    ");
    $stmtReset->execute(['pwd' => password_hash($password, PASSWORD_BCRYPT), 'id' => (int)$existing['id']]);
    $empId = $existing['emp_id'];
    $action = 'Password reset for';
} else {
    $adminRoleId = $db->query("SELECT id FROM roles WHERE name = 'admin' LIMIT 1")->fetchColumn();
    if ($adminRoleId === false) {
        fwrite(STDERR, "No 'admin' role found. Import schema.sql first.\n");
        exit(1);
    }

    // Split a supplied name on the first space; the account is editable in the
    // console afterwards, so this only has to be reasonable.
    $parts = preg_split('/\s+/', $name, 2);
    $firstName = $parts[0] !== '' ? $parts[0] : 'System';
    $lastName  = $parts[1] ?? 'Administrator';

    $empId = next_emp_id($db);

    $stmtCreate = $db->prepare("
        INSERT INTO users
            (emp_id, first_name, last_name, email, password_hash, role_id,
             department_id, manager_id, status, must_change_password)
        VALUES
            (:emp_id, :first_name, :last_name, :email, :pwd, :role_id,
             NULL, NULL, 'active', 1)
    ");
    $stmtCreate->execute([
        'emp_id'     => $empId,
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'email'      => $email,
        'pwd'        => password_hash($password, PASSWORD_BCRYPT),
        'role_id'    => (int)$adminRoleId,
    ]);
    $action = 'Created administrator';
}

echo "\n{$action} {$email} ({$empId})\n";
echo "Temporary password: {$password}\n\n";
echo "Shown once and stored only as a hash - write it down now.\n";
echo "You will be asked to set your own password the first time you sign in.\n";
