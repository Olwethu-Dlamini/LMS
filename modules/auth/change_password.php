<?php
require_once __DIR__ . '/../../includes/functions.php';

check_auth(); // enforce_password_change() deliberately skips this script

$db     = getDBConnection();
$userId = (int)$_SESSION['user_id'];
$forced = !empty($_SESSION['must_change_password']);

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password_hash'])) {
            $errors[] = 'Your current password is incorrect.';
        }
        if ($new !== $confirm) {
            $errors[] = 'The new password and confirmation do not match.';
        }
        if ($current !== '' && $new === $current) {
            $errors[] = 'Your new password must be different from your current one.';
        }
        $errors = array_merge($errors, password_policy_errors($new));

        if (empty($errors)) {
            $stmtUpd = $db->prepare("
                UPDATE users
                SET password_hash = :pwd,
                    must_change_password = 0,
                    password_changed_at = NOW()
                WHERE id = :id
            ");
            $stmtUpd->execute([
                'pwd' => password_hash($new, PASSWORD_BCRYPT),
                'id'  => $userId,
            ]);

            unset($_SESSION['must_change_password']);
            session_regenerate_id(true); // new credentials, new session id
            $success = true;

            set_flash('success', 'Your password has been updated successfully.');
            header('Location: ' . landing_url());
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password | <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,300..900;1,300..900&display=swap">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/plugins/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/plugins/themify-icons/themify-icons.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/ri-theme.css">
</head>
<body>
<div class="ri-auth">
    <div class="ri-auth-card">
        <div class="ri-auth-head">
            <img src="<?php echo APP_URL; ?>/assets/images/ri-logo-navy.png"
                 alt="<?php echo htmlspecialchars(ORG_NAME); ?>" class="ri-auth-logo">
            <strong><?php echo $forced ? 'Set Your New Password' : 'Change Password'; ?></strong>
            <span><?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?></span>
        </div>

        <div class="ri-auth-body">
            <?php if ($forced): ?>
                <div class="alert alert-warning mb-4">
                    <i class="ti-lock"></i> Your account is using a temporary password.
                    Please choose your own password before continuing.
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mb-4">
                    <?php foreach ($errors as $e): ?>
                        <div><?php echo htmlspecialchars($e); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="pwForm">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                <div class="form-group mb-3">
                    <label><?php echo $forced ? 'Temporary Password' : 'Current Password'; ?></label>
                    <input type="password" name="current_password" class="form-control" required autofocus
                           autocomplete="current-password" placeholder="Enter your current password">
                </div>

                <div class="form-group mb-2">
                    <label>New Password</label>
                    <input type="password" name="new_password" id="newPw" class="form-control" required
                           autocomplete="new-password" placeholder="Choose a new password">
                    <div class="ri-pwbar"><span id="pwBar"></span></div>
                    <ul class="ri-pwrules" id="pwRules">
                        <li data-rule="len">At least 10 characters</li>
                        <li data-rule="upper">One uppercase letter</li>
                        <li data-rule="lower">One lowercase letter</li>
                        <li data-rule="digit">One number</li>
                    </ul>
                </div>

                <div class="form-group mb-4 mt-3">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" id="confirmPw" class="form-control" required
                           autocomplete="new-password" placeholder="Re-enter the new password">
                    <small class="form-text text-danger" id="matchMsg" style="display:none;">
                        Passwords do not match.
                    </small>
                </div>

                <button type="submit" class="btn btn-primary btn-block font-weight-bold py-2">
                    <i class="ti-check"></i> Update Password
                </button>

                <?php if (!$forced): ?>
                    <a href="<?php echo landing_url(); ?>"
                       class="btn btn-outline-secondary btn-block font-weight-bold mt-2">Cancel</a>
                <?php else: ?>
                    <a href="<?php echo APP_URL; ?>/modules/auth/logout.php"
                       class="btn btn-outline-secondary btn-block font-weight-bold mt-2">Sign out instead</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="ri-auth-foot">
            Trouble signing in? Contact IT on <?php echo htmlspecialchars(ORG_PHONE); ?>
        </div>
    </div>
</div>

<script>
(function () {
    var pw = document.getElementById('newPw'),
        confirmPw = document.getElementById('confirmPw'),
        bar = document.getElementById('pwBar'),
        rules = document.getElementById('pwRules'),
        matchMsg = document.getElementById('matchMsg');

    var tests = {
        len:   function (v) { return v.length >= 10; },
        upper: function (v) { return /[A-Z]/.test(v); },
        lower: function (v) { return /[a-z]/.test(v); },
        digit: function (v) { return /[0-9]/.test(v); }
    };

    function score() {
        var v = pw.value, passed = 0;
        Object.keys(tests).forEach(function (key) {
            var ok = tests[key](v);
            if (ok) passed++;
            var li = rules.querySelector('[data-rule="' + key + '"]');
            if (li) li.classList.toggle('ok', ok);
        });
        var pct = (passed / 4) * 100;
        bar.style.width = pct + '%';
        bar.style.background = pct < 50 ? '#b3261e' : (pct < 100 ? '#c47f00' : '#17864a');
        checkMatch();
    }

    function checkMatch() {
        var show = confirmPw.value !== '' && confirmPw.value !== pw.value;
        matchMsg.style.display = show ? 'block' : 'none';
    }

    pw.addEventListener('input', score);
    confirmPw.addEventListener('input', checkMatch);
})();
</script>
</body>
</html>
