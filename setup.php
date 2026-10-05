<?php
require_once __DIR__ . '/ticket_store.php';

if (installation_ready()) {
    header('Location: login.php');
    exit;
}

$errors = [];
$organizationName = '';
$adminName = '';
$adminEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ticket_require_csrf();
    $organizationName = trim(ticket_request_string($_POST, 'organization_name'));
    $adminName = trim(ticket_request_string($_POST, 'admin_name'));
    $adminEmail = strtolower(trim(ticket_request_string($_POST, 'email')));
    $password = ticket_request_string($_POST, 'password');
    $passwordConfirmation = ticket_request_string($_POST, 'password_confirmation');

    if ($organizationName === '' || strlen($organizationName) > 120) {
        $errors[] = 'Organization name is required and must be 120 characters or fewer.';
    }
    if ($adminName === '' || strlen($adminName) > 100) {
        $errors[] = 'Administrator name is required and must be 100 characters or fewer.';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminEmail) > 254) {
        $errors[] = 'Enter a valid administrator email address.';
    }
    if (strlen($password) < 12 || strlen($password) > 200) {
        $errors[] = 'Choose a password between 12 and 200 characters.';
    } elseif ($password !== $passwordConfirmation) {
        $errors[] = 'The password confirmation does not match.';
    }

    if (!$errors) {
        $organizationId = bin2hex(random_bytes(12));
        $firstAdmin = [
            'id' => bin2hex(random_bytes(16)),
            'organization_id' => $organizationId,
            'name' => $adminName,
            'email' => $adminEmail,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'super_admin',
            'department' => '',
            'active' => true,
            'created_at' => date(DATE_ATOM),
        ];
        $created = store_update('users', static function (array &$users) use ($firstAdmin, $organizationId, $organizationName): bool {
            if ($users) {
                return false;
            }
            store_update('settings', static function (array &$settings) use ($organizationId, $organizationName): void {
                $settings['organization_id'] = $organizationId;
                $settings['organization_name'] = $organizationName;
                $settings['created_at'] = date(DATE_ATOM);
            });
            $users[] = $firstAdmin;
            return true;
        });

        if (!$created) {
            header('Location: login.php');
            exit;
        }

        ticket_store_update(static function (array &$tickets) use ($organizationId): void {
            foreach ($tickets as &$ticket) {
                $ticket['organization_id'] ??= $organizationId;
            }
            unset($ticket);
        });
        audit_event($firstAdmin, 'organization.created', 'organization', $organizationId, ['name' => $organizationName]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $firstAdmin['id'];
        $_SESSION['last_activity'] = time();
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f5f7fb">
    <title>Set up MASIX IT Support</title><link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<main class="auth-layout">
    <a class="brand auth-brand" href="setup.php"><span class="brand-mark">M</span><span>MASIX <span class="brand-light">IT SUPPORT</span></span></a>
    <section class="panel auth-card setup-card">
        <span class="auth-icon">✦</span>
        <p class="eyebrow">WELCOME TO MASIX</p>
        <h1>Set up your support workspace</h1>
        <p class="auth-description">Create your organization and its first super administrator. The installer closes as soon as setup is complete.</p>
        <?php if ($errors): ?><div class="form-errors" role="alert"><strong>We couldn’t finish setup:</strong><ul><?php foreach ($errors as $error): ?><li><?= ticket_escape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="POST" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= ticket_escape(ticket_csrf_token()) ?>">
            <label class="input-group"><span>Company or institution</span><input name="organization_name" maxlength="120" autocomplete="organization" value="<?= ticket_escape($organizationName) ?>" placeholder="e.g. Acme University" required></label>
            <div class="auth-divider"><span>SUPER ADMINISTRATOR</span></div>
            <label class="input-group"><span>Full name</span><input name="admin_name" maxlength="100" autocomplete="name" value="<?= ticket_escape($adminName) ?>" required></label>
            <label class="input-group"><span>Work email</span><input name="email" type="email" maxlength="254" autocomplete="email" value="<?= ticket_escape($adminEmail) ?>" required></label>
            <label class="input-group"><span>Password <span class="field-hint-inline">12 characters minimum</span></span><input name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required></label>
            <label class="input-group"><span>Confirm password</span><input name="password_confirmation" type="password" minlength="12" maxlength="200" autocomplete="new-password" required></label>
            <button class="button button--primary auth-submit" type="submit">Create workspace <span aria-hidden="true">→</span></button>
        </form>
        <p class="auth-footnote">Your workspace data is stored on this server. Choose a unique, strong password.</p>
    </section>
    <p class="auth-copyright">MASIX IT SUPPORT · PROFESSIONAL SERVICE MANAGEMENT</p>
</main>
</body>
</html>
