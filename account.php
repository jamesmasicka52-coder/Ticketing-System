<?php
require_once __DIR__ . '/ticket_store.php';
$user = require_login();
$errors = [];
$mustChangePassword = (bool) ($user['password_reset_required'] ?? false);
$success = isset($_GET['saved']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ticket_require_csrf();
    $action = ticket_request_string($_POST, 'action');
    if ($mustChangePassword && $action !== 'password') {
        http_response_code(403);
        exit('Update your temporary password before changing other account settings.');
    }
    if ($action === 'profile') {
        $name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
        $department = isset($_POST['department']) && is_string($_POST['department']) ? trim($_POST['department']) : '';
        if ($name === '' || strlen($name) > 100 || strlen($department) > 100) {
            $errors[] = 'Enter a name and department of up to 100 characters each.';
        } else {
            store_update('users', static function (array &$users) use ($user, $name, $department): void {
                foreach ($users as &$member) {
                    if ($member['id'] === $user['id']) {
                        $member['name'] = $name;
                        $member['department'] = $department;
                        break;
                    }
                }
                unset($member);
            });
            audit_event(array_merge($user, ['name' => $name]), 'account.profile_updated', 'user', $user['id']);
            header('Location: account.php?saved=1');
            exit;
        }
    } elseif ($action === 'password') {
        $currentPassword = ticket_request_string($_POST, 'current_password');
        $newPassword = ticket_request_string($_POST, 'new_password');
        $confirmPassword = ticket_request_string($_POST, 'confirm_password');
        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            $errors[] = 'Your current password is incorrect.';
        } elseif (strlen($newPassword) < 12 || strlen($newPassword) > 200) {
            $errors[] = 'Choose a new password between 12 and 200 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'The new password and confirmation do not match.';
        } else {
            store_update('users', static function (array &$users) use ($user, $newPassword): void {
                foreach ($users as &$member) {
                    if ($member['id'] === $user['id']) {
                        $member['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                        $member['password_changed_at'] = date(DATE_ATOM);
                        $member['password_reset_required'] = false;
                        break;
                    }
                }
                unset($member);
            });
            audit_event($user, 'account.password_changed', 'user', $user['id']);
            header('Location: account.php?saved=1');
            exit;
        }
    } else {
        http_response_code(400);
        exit('Invalid account action.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Account · MASIX IT Support</title><link rel="stylesheet" href="style.css"></head>
<body><div class="app-shell">
    <?php app_header('', $user); ?>
    <main class="page-content page-content--narrow">
        <div class="page-heading"><div><p class="eyebrow"><?= $mustChangePassword ? 'WELCOME · FIRST SIGN-IN' : 'PERSONAL SETTINGS' ?></p><h1><?= $mustChangePassword ? 'Set your personal password' : 'Your account' ?></h1><p class="page-lead"><?= $mustChangePassword ? 'Your administrator created a temporary password. Choose a private password to unlock your workspace.' : 'Manage your profile and keep your account secure.' ?></p></div></div>
        <?php if ($success): ?><div class="notice notice--success account-success" role="status">Your account changes have been saved.</div><?php endif; ?>
        <?php if ($errors): ?><div class="form-errors" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= ticket_escape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <?php if (!$mustChangePassword): ?><section class="panel account-panel"><div class="panel-heading"><div><h2>Profile details</h2><p>Your organization uses this information to identify your requests.</p></div></div><form method="POST" class="account-form"><input type="hidden" name="csrf_token" value="<?= ticket_escape(ticket_csrf_token()) ?>"><input type="hidden" name="action" value="profile"><div class="form-grid"><label class="input-group"><span>Full name</span><input name="name" maxlength="100" autocomplete="name" value="<?= ticket_escape($user['name']) ?>" required></label><label class="input-group"><span>Work email</span><input type="email" value="<?= ticket_escape($user['email']) ?>" disabled></label><label class="input-group"><span>Department</span><input name="department" maxlength="100" value="<?= ticket_escape($user['department'] ?? '') ?>" placeholder="Optional"></label><label class="input-group"><span>Role</span><input value="<?= ticket_escape(USER_ROLE_LABELS[$user['role']]) ?>" disabled></label></div><div class="form-actions"><button class="button button--primary" type="submit">Save profile</button></div></form></section><?php endif; ?>
        <section class="panel account-panel"><div class="panel-heading"><div><h2><?= $mustChangePassword ? 'Choose a new password' : 'Change password' ?></h2><p>Use at least 12 characters and never reuse a shared password.</p></div></div><form method="POST" class="account-form"><input type="hidden" name="csrf_token" value="<?= ticket_escape(ticket_csrf_token()) ?>"><input type="hidden" name="action" value="password"><div class="form-grid"><label class="input-group"><span><?= $mustChangePassword ? 'Temporary password' : 'Current password' ?></span><input type="password" name="current_password" autocomplete="current-password" required></label><span></span><label class="input-group"><span>New password</span><input type="password" name="new_password" minlength="12" maxlength="200" autocomplete="new-password" required></label><label class="input-group"><span>Confirm new password</span><input type="password" name="confirm_password" minlength="12" maxlength="200" autocomplete="new-password" required></label></div><div class="form-actions"><button class="button button--primary" type="submit"><?= $mustChangePassword ? 'Set my password' : 'Update password' ?></button></div></form></section>
    </main>
</div></body>
</html>
