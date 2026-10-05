<?php
require_once __DIR__ . '/ticket_store.php';
$actor = require_role(['admin', 'super_admin']);
$errors = [];
$success = isset($_GET['created']);
$oneTimePassword = ticket_request_string($_SESSION, 'one_time_user_password');
unset($_SESSION['one_time_user_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ticket_require_csrf();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : 'create';

    if ($action === 'create') {
        $name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
        $email = isset($_POST['email']) && is_string($_POST['email']) ? strtolower(trim($_POST['email'])) : '';
        $department = isset($_POST['department']) && is_string($_POST['department']) ? trim($_POST['department']) : '';
        $role = isset($_POST['role']) && is_string($_POST['role']) ? $_POST['role'] : 'user';
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        if ($name === '' || strlen($name) > 100) {
            $errors[] = 'Enter a name of up to 100 characters.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $errors[] = 'Enter a valid work email.';
        }
        if (strlen($department) > 100) {
            $errors[] = 'Department must be 100 characters or fewer.';
        }
        if (strlen($password) < 12 || strlen($password) > 200) {
            $errors[] = 'Choose an initial password between 12 and 200 characters.';
        }
        if (!in_array($role, $actor['role'] === 'super_admin' ? ['user', 'admin'] : ['user'], true)) {
            $errors[] = $actor['role'] === 'super_admin' ? 'Super administrators can add administrators or regular users.' : 'Administrators can only add regular users.';
        }
        if (!$errors) {
            $member = [
                'id' => bin2hex(random_bytes(16)),
                'organization_id' => organization_id($actor),
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'department' => $department,
                'active' => true,
                'password_reset_required' => true,
                'created_at' => date(DATE_ATOM),
                'created_by' => $actor['id'],
            ];
            $created = store_update('users', static function (array &$users) use ($member): bool {
                foreach ($users as $existing) {
                    if (strtolower((string) ($existing['email'] ?? '')) === $member['email']) {
                        return false;
                    }
                }
                $users[] = $member;
                return true;
            });
            if (!$created) {
                $errors[] = 'An account with that email address already exists.';
            } else {
                audit_event($actor, 'user.created', 'user', $member['id'], ['name' => $name, 'email' => $email, 'role' => $role]);
                header('Location: users.php?created=1');
                exit;
            }
        }
    } elseif ($action === 'reset_password') {
        $targetId = ticket_request_string($_POST, 'user_id');
        $target = null;
        foreach (store_read('users') as $member) {
            if (($member['id'] ?? '') === $targetId && organization_id($member) === organization_id($actor)) {
                $target = $member;
                break;
            }
        }
        if ($target === null || $targetId === $actor['id'] || ($target['role'] ?? '') === 'super_admin' || ($actor['role'] === 'admin' && ($target['role'] ?? '') !== 'user')) {
            http_response_code(403);
            exit('You cannot reset credentials for this account.');
        }
        $temporaryPassword = bin2hex(random_bytes(10));
        store_update('users', static function (array &$users) use ($targetId, $temporaryPassword): void {
            foreach ($users as &$member) {
                if ($member['id'] === $targetId) {
                    $member['password_hash'] = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                    $member['password_reset_required'] = true;
                    $member['updated_at'] = date(DATE_ATOM);
                    break;
                }
            }
            unset($member);
        });
        audit_event($actor, 'user.password_reset', 'user', $targetId, ['email' => $target['email'], 'role' => $target['role']]);
        $_SESSION['one_time_user_password'] = $temporaryPassword;
        header('Location: users.php?reset=1');
        exit;
    } elseif ($action === 'toggle_active') {
        $targetId = isset($_POST['user_id']) && is_string($_POST['user_id']) ? $_POST['user_id'] : '';
        $target = null;
        foreach (store_read('users') as $member) {
            if (($member['id'] ?? '') === $targetId && organization_id($member) === organization_id($actor)) {
                $target = $member;
                break;
            }
        }
        if ($target === null || $targetId === $actor['id'] || ($actor['role'] === 'admin' && ($target['role'] ?? '') !== 'user') || ($target['role'] ?? '') === 'super_admin') {
            http_response_code(403);
            exit('You cannot change access for this account.');
        }
        $newState = !($target['active'] ?? false);
        store_update('users', static function (array &$users) use ($targetId, $newState): void {
            foreach ($users as &$member) {
                if ($member['id'] === $targetId) {
                    $member['active'] = $newState;
                    $member['updated_at'] = date(DATE_ATOM);
                    break;
                }
            }
            unset($member);
        });
        audit_event($actor, $newState ? 'user.activated' : 'user.deactivated', 'user', $targetId, ['email' => $target['email'], 'role' => $target['role']]);
        header('Location: users.php?access=' . ($newState ? 'restored' : 'suspended'));
        exit;
    } else {
        http_response_code(400);
        exit('Invalid account action.');
    }
}

$users = array_values(array_filter(store_read('users'), static fn (array $member): bool => organization_id($member) === organization_id($actor)));
usort($users, static fn (array $a, array $b): int => [!($a['active'] ?? false), $a['role'], strtolower($a['name'])] <=> [!($b['active'] ?? false), $b['role'], strtolower($b['name'])]);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>People · MASIX IT Support</title><link rel="stylesheet" href="style.css"></head>
<body><div class="app-shell">
    <?php app_header('users', $actor); ?>
    <main class="page-content">
        <div class="page-heading"><div><p class="eyebrow">ACCESS MANAGEMENT</p><h1>People & permissions</h1><p class="page-lead"><?= $actor['role'] === 'super_admin' ? 'Build your service team and grant the right level of access.' : 'Add regular users and manage access for your organization.' ?></p></div><span class="badge badge--status-in-progress"><?= count(array_filter($users, static fn (array $member): bool => $member['active'] ?? false)) ?> active accounts</span></div>
        <?php if ($success): ?><div class="notice notice--success account-success" role="status">The account was created. Share the temporary password securely with its owner.</div><?php endif; ?>
        <?php if (isset($_GET['access'])): ?><div class="notice notice--success account-success" role="status">Account access <?= ticket_escape($_GET['access']) ?>.</div><?php endif; ?>
        <?php if (isset($_GET['reset']) && $_GET['reset'] === '1' && $oneTimePassword !== ''): ?><div class="temporary-password-notice" role="status"><span class="temp-password-icon">✓</span><div><strong>One-time temporary password</strong><p>Share this password with the account owner using a secure channel. They must choose their own password before continuing.</p><code><?= ticket_escape($oneTimePassword) ?></code></div></div><?php endif; ?>
        <?php if ($errors): ?><div class="form-errors" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= ticket_escape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <section class="panel account-panel">
            <div class="panel-heading"><div><h2>Add a teammate</h2><p><?= $actor['role'] === 'super_admin' ? 'You can create administrator or regular user accounts.' : 'Administrator accounts can only be created by a super administrator.' ?></p></div><span class="form-icon form-icon--small">＋</span></div>
            <form method="POST" class="account-form"><input type="hidden" name="csrf_token" value="<?= ticket_escape(ticket_csrf_token()) ?>"><input type="hidden" name="action" value="create">
                <div class="form-grid"><label class="input-group"><span>Full name</span><input name="name" maxlength="100" autocomplete="name" required></label><label class="input-group"><span>Work email</span><input name="email" type="email" maxlength="254" autocomplete="email" required></label><label class="input-group"><span>Department</span><input name="department" maxlength="100" placeholder="Optional"></label><label class="input-group"><span>Initial password <small class="field-hint-inline">12+ characters · share securely</small></span><input name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required></label><label class="input-group"><span>Access level</span><select name="role"><?php if ($actor['role'] === 'super_admin'): ?><option value="admin">Administrator · Manage requests and regular users</option><?php endif; ?><option value="user">Regular user · Submit and track own requests</option></select></label></div>
                <div class="form-actions"><button class="button button--primary" type="submit">Create account <span aria-hidden="true">→</span></button></div>
            </form>
        </section>
        <section class="panel users-list-panel"><div class="panel-heading"><div><h2>Organization accounts <span class="count-pill"><?= count($users) ?></span></h2><p>Access is private to <?= ticket_escape(app_settings()['organization_name'] ?? 'your organization') ?>.</p></div></div>
            <div class="table-scroll"><table class="users-table"><thead><tr><th>Person</th><th>Department</th><th>Role</th><th>Joined</th><th>Access</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
            <?php foreach ($users as $member): ?><tr><td><strong><?= ticket_escape($member['name']) ?></strong><span class="cell-subtitle"><?= ticket_escape($member['email']) ?></span></td><td><?= ticket_escape($member['department'] ?? '—') ?></td><td><span class="role-pill role-pill--<?= ticket_escape($member['role']) ?>"><?= ticket_escape(USER_ROLE_LABELS[$member['role']] ?? $member['role']) ?></span></td><td><?= ticket_escape(substr((string) ($member['created_at'] ?? ''), 0, 10)) ?></td><td><span class="access-state<?= ($member['active'] ?? false) ? '' : ' access-state--inactive' ?>"><i></i><?= ($member['active'] ?? false) ? 'Active' : 'Suspended' ?></span></td><td><?php if ($member['id'] !== $actor['id'] && $member['role'] !== 'super_admin' && ($actor['role'] === 'super_admin' || $member['role'] === 'user')): ?><div class="user-row-actions"><form method="POST"><input type="hidden" name="csrf_token" value="<?= ticket_escape(ticket_csrf_token()) ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= ticket_escape($member['id']) ?>"><button class="table-action table-action--plain" type="submit">Reset password</button></form><form method="POST"><input type="hidden" name="csrf_token" value="<?= ticket_escape(ticket_csrf_token()) ?>"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="user_id" value="<?= ticket_escape($member['id']) ?>"><button class="table-action table-action--plain" type="submit"><?= ($member['active'] ?? false) ? 'Suspend' : 'Restore' ?></button></form></div><?php else: ?><span class="cell-subtitle"><?= $member['id'] === $actor['id'] ? 'You' : 'Protected' ?></span><?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
        <p class="page-footnote">Role policy: super administrators create administrators and regular users. Administrators can create regular users only.</p>
    </main>
</div></body>
</html>
