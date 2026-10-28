<?php
require_once __DIR__ . '/partials/session.php';
require_once __DIR__ . '/partials/icons.php';

if (isset($_SESSION['user_id'])) {
    header('Location: toolshare.php');
    exit();
}

$error = (string) ($_GET['error'] ?? '');
$registered = isset($_GET['registered']);
$loggedOut = isset($_GET['logged_out']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Log in · ToolShare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="auth">
    <section class="auth-side">
        <a class="brand" href="login.php"><span class="brand-mark" aria-hidden="true">T</span><span><strong>ToolShare</strong><small>Community lending</small></span></a>
        <div>
            <h2>Tools shared. Community stronger.</h2>
            <p>One place to track community equipment, borrowing requests, reservations, due dates and returns.</p>
        </div>
        <ul class="auth-points">
            <li><?php echo icon('check'); ?> Live availability, no double-booking</li>
            <li><?php echo icon('check'); ?> Clear due dates and overdue reminders</li>
            <li><?php echo icon('check'); ?> Your personal details stay private</li>
        </ul>
    </section>
    <main class="auth-main">
        <div class="auth-card">
            <h1>Welcome back</h1>
            <p class="sub">Log in to continue to your community workspace.</p>

            <?php if ($error): ?><div class="flash error" role="alert"><?php echo icon('alert'); ?><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($registered): ?><div class="flash" role="status"><?php echo icon('check'); ?>Account created. You can log in now.</div><?php endif; ?>
            <?php if ($loggedOut): ?><div class="flash" role="status"><?php echo icon('check'); ?>You have been signed out.</div><?php endif; ?>

            <form class="stack" action="backend/auth_code.php" method="post">
                <div class="field"><label for="username">Username</label><input id="username" type="text" name="username" autocomplete="username" required autofocus></div>
                <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="current-password" required></div>
                <button type="submit" class="btn primary" name="login_user">Log in</button>
            </form>
            <p class="auth-switch">New resident? <a href="register.php">Create an account</a></p>
        </div>
    </main>
</div>
</body>
</html>
