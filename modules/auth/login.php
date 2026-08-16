<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../helpers/LoginThrottle.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . landing_url());
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    $db        = getDBConnection();
    $throttle  = new LoginThrottle($db);
    $caller    = LoginThrottle::callerAddress($_SERVER);
    $mustWait  = $email === '' ? 0 : $throttle->secondsToWaitFor($email, $caller);

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Invalid security token. Please try again.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } elseif ($mustWait > 0) {
        // Deliberately says nothing about whether the account exists.
        $error = 'Too many sign-in attempts. Please wait '
               . LoginThrottle::waitLabel($mustWait) . ' and try again.';
    } else {
        $stmt = $db->prepare("
            SELECT u.*, r.name AS role_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.email = :email AND u.status = 'active'
        ");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $throttle->clear($email, $caller);
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_emp_id'] = $user['emp_id'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = strtolower($user['role_name']);
            $_SESSION['department_id'] = $user['department_id'];

            if (!empty($user['must_change_password'])) {
                $_SESSION['must_change_password'] = 1;
                header('Location: ' . APP_URL . '/modules/auth/change_password.php');
                exit;
            }

            header('Location: ' . landing_url());
            exit;
        } else {
            $throttle->recordFailure($email, $caller);
            $error = 'Invalid email address or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In | <?php echo APP_NAME; ?></title>
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
            <strong><?php echo htmlspecialchars(APP_SHORT_NAME); ?></strong>
            <span>Sign in with your <?php echo htmlspecialchars(ORG_NAME); ?> account</span>
        </div>

        <div class="ri-auth-body">
            <?php echo display_flash(); ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                <div class="form-group mb-3">
                    <label>Work Email Address</label>
                    <input type="email" name="email" class="form-control" required autofocus
                           autocomplete="username" placeholder="you@realnet.co.sz"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <div class="form-group mb-4">
                    <label>Password</label>
                    <div class="ri-pwfield">
                        <input type="password" name="password" class="form-control" required
                               autocomplete="current-password" placeholder="••••••••" data-reveal>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block font-weight-bold py-2">
                    <i class="ti-shift-right"></i> Sign In
                </button>
            </form>
        </div>

        <div class="ri-auth-foot">
            Forgot your password? Contact IT on <?php echo htmlspecialchars(ORG_PHONE); ?>
            or email <a href="mailto:<?php echo htmlspecialchars(ORG_EMAIL); ?>"><?php echo htmlspecialchars(ORG_EMAIL); ?></a>
            for a reset.
        </div>
    </div>
</div>

<script src="<?php echo APP_URL; ?>/assets/js/password-reveal.js"></script>
</body>
</html>
