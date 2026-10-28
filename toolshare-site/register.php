<?php
require_once __DIR__ . '/partials/session.php';
require_once __DIR__ . '/partials/icons.php';

if (isset($_SESSION['user_id'])) {
    header('Location: toolshare.php');
    exit();
}

$error = (string) ($_GET['error'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Create account · ToolShare</title>
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
            <h1>Create your account</h1>
            <p class="sub">Join as a resident to borrow and reserve community equipment.</p>

            <?php if ($error): ?><div class="flash error" role="alert"><?php echo icon('alert'); ?><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

            <form action="backend/auth_code.php" method="post">
                <div class="form-grid">
                    <div class="field"><label for="first_name">First name</label><input id="first_name" type="text" name="first_name" maxlength="100" autocomplete="given-name" required></div>
                    <div class="field"><label for="last_name">Last name</label><input id="last_name" type="text" name="last_name" maxlength="100" autocomplete="family-name" required></div>
                    <div class="field full"><label for="email">Email</label><input id="email" type="email" name="email" maxlength="255" autocomplete="email" required></div>
                    <div class="field"><label for="gender">Gender</label><select id="gender" name="gender" required><option value="" selected disabled>Select</option><option>Female</option><option>Male</option><option>Other</option></select></div>
                    <div class="field"><label for="birthdate">Birthdate</label><input id="birthdate" type="date" name="birthdate" max="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="field full"><label for="city">City / community</label><input id="city" type="text" name="city" maxlength="150" required></div>
                    <div class="field full"><label for="username">Username</label><input id="username" type="text" name="username" maxlength="100" autocomplete="username" required></div>
                    <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" minlength="8" autocomplete="new-password" required><span class="hint">At least 8 characters</span></div>
                    <div class="field"><label for="confirm_password">Confirm password</label><input id="confirm_password" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></div>
                    <div class="full"><button type="submit" class="btn primary" name="register_account">Create account</button></div>
                </div>
            </form>
            <p class="auth-switch">Already registered? <a href="login.php">Log in</a></p>
        </div>
    </main>
</div>
</body>
</html>
