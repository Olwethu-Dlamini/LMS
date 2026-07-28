<?php
require_once __DIR__ . '/../../includes/functions.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Invalid security token. Please try again.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT u.*, r.name AS role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE u.email = :email AND u.status = 'active'
        ");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_emp_id'] = $user['emp_id'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = strtolower($user['role_name']);
            $_SESSION['department_id'] = $user['department_id'];

            header('Location: ' . APP_URL . '/modules/dashboard/index.php');
            exit;
        } else {
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
    <title>Login | <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/plugins/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/plugins/themify-icons/themify-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            overflow: hidden;
        }
        .login-header {
            background: #2563eb;
            color: #ffffff;
            padding: 30px 20px;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-header">
        <h4 class="font-weight-bold mb-1"><i class="ti-calendar"></i> <?php echo APP_NAME; ?></h4>
        <p class="mb-0 small text-light">Sign in to your LMS account</p>
    </div>
    <div class="p-4">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <div class="form-group mb-3">
                <label class="font-weight-bold text-secondary small">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="e.g. employee@lms.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="form-group mb-4">
                <label class="font-weight-bold text-secondary small">Password</label>
                <input type="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block font-weight-bold py-2">
                <i class="ti-shift-right"></i> Sign In
            </button>
        </form>

        <div class="mt-4 pt-3 border-top">
            <h6 class="small font-weight-bold text-muted mb-2">Test Demo Accounts (Password: <code>password123</code>):</h6>
            <ul class="list-unstyled small text-muted mb-0">
                <li>👤 <strong>Employee:</strong> <code>employee@lms.com</code></li>
                <li>👨‍💼 <strong>Line Manager:</strong> <code>manager@lms.com</code></li>
                <li>👩‍💻 <strong>HR Manager:</strong> <code>hr@lms.com</code></li>
                <li>👑 <strong>Executive/Boss:</strong> <code>boss@lms.com</code></li>
                <li>⚙️ <strong>System Admin:</strong> <code>admin@lms.com</code></li>
            </ul>
        </div>
    </div>
</div>
<script src="<?php echo APP_URL; ?>/assets/plugins/jQuery/jquery.min.js"></script>
<script src="<?php echo APP_URL; ?>/assets/plugins/bootstrap/bootstrap.min.js"></script>
</body>
</html>
