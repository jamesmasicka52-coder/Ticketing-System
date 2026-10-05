<?php
require_once __DIR__ . '/ticket_store.php';

if (!installation_ready()) {
    header('Location: setup.php');
    exit;
}
if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = false;
$email = '';
$locked = (int) ($_SESSION['login_locked_until'] ?? 0) > time();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ticket_require_csrf();
    $email = strtolower(trim(ticket_request_string($_POST, 'email')));
    $password = ticket_request_string($_POST, 'password');
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
    $locked = (int) ($_SESSION['login_locked_until'] ?? 0) > time();
    $matchedUser = null;
    if (!$locked) {
        foreach (store_read('users') as $user) {
            if (strtolower((string) ($user['email'] ?? '')) === $email && ($user['active'] ?? false) === true) {
                $matchedUser = $user;
                break;
            }
        }
    }

    if ($matchedUser !== null && password_verify($password, (string) ($matchedUser['password_hash'] ?? ''))) {
        if (password_needs_rehash($matchedUser['password_hash'], PASSWORD_DEFAULT)) {
            store_update('users', static function (array &$users) use ($matchedUser, $password): void {
                foreach ($users as &$user) {
                    if (($user['id'] ?? '') === $matchedUser['id']) {
                        $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                        break;
                    }
                }
                unset($user);
            });
        }
        session_regenerate_id(true);
        unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
        $_SESSION['user_id'] = $matchedUser['id'];
        $_SESSION['last_activity'] = time();
        audit_event($matchedUser, 'auth.login', 'user', $matchedUser['id']);
        header('Location: ' . (($matchedUser['password_reset_required'] ?? false) ? 'account.php?force=1' : 'index.php'));
        exit;
    }

    if (!$locked) {
        $attempts++;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= 8) {
            $_SESSION['login_locked_until'] = time() + 300;
            $locked = true;
        }
    }
    $error = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f5f7fb">
    <title>Sign in · MASIX IT Support</title><link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<main class="auth-layout">
    <a class="brand auth-brand" href="login.php"><span class="brand-mark">M</span><span>MASIX <span class="brand-light">IT SUPPORT</span></span></a>
    <section class="panel auth-card">
        <span class="auth-icon">↗</span>
        <p class="eyebrow">YOUR SERVICE WORKSPACE</p>
        <h1>Welcome back</h1>
        <p class="auth-description">Sign in with your organization account to continue.</p>
        <?php if ($error): ?><div class="form-errors" role="alert"><?= $locked ? 'Too many sign-in attempts. Please wait five minutes and try again.' : 'The email address or password is incorrect.' ?></div><?php endif; ?>
        <form method="POST" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= ticket_escape(ticket_csrf_token()) ?>">
            <label class="input-group"><span>Work email</span><input name="email" type="email" maxlength="254" autocomplete="username" value="<?= ticket_escape($email) ?>" required></label>
            <label class="input-group"><span>Password</span><input name="password" type="password" maxlength="200" autocomplete="current-password" required></label>
            <button class="button button--primary auth-submit" type="submit" <?= $locked ? 'disabled' : '' ?>>Sign in <span aria-hidden="true">→</span></button>
        </form>
        <p class="auth-footnote">Need access? Ask your organization administrator to create your account.</p>
    </section>
    <p class="auth-copyright">MASIX IT SUPPORT · PROFESSIONAL SERVICE MANAGEMENT</p>
</main>
</body>
</html>
