<?php
require_once __DIR__ . '/ticket_store.php';
$user = require_login();
$organizationUsers = array_values(array_filter(store_read('users'), static fn (array $member): bool => organization_id($member) === organization_id($user)));
$departments = array_values(array_unique(array_filter(array_map(static fn (array $member): string => trim((string) ($member['department'] ?? '')), $organizationUsers))));
sort($departments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f5f7fb">
    <title>New request · MASIX IT Support</title><link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <?php app_header('tickets', $user); ?>
    <main class="page-content page-content--narrow">
        <a class="back-link" href="history.php">← Back to <?= $user['role'] === 'user' ? 'my requests' : 'ticket queue' ?></a>
        <section class="panel form-panel">
            <div class="form-heading">
                <span class="form-icon" aria-hidden="true">✦</span><p class="eyebrow">SUPPORT REQUEST</p><h1>Tell us how we can help</h1>
                <p>Your request will be sent to <?= ticket_escape(app_settings()['organization_name'] ?? 'your support team') ?>’s service desk.</p>
            </div>
            <form action="submit_ticket.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= ticket_escape(ticket_csrf_token()) ?>">
                <div class="requester-card"><span class="account-avatar"><?= ticket_escape(strtoupper(substr((string) $user['name'], 0, 1))) ?></span><span><strong><?= ticket_escape($user['name']) ?></strong><small><?= ticket_escape($user['email']) ?></small></span><span class="requester-verified">Signed in</span></div>
                <div class="form-grid">
                    <div class="input-group input-group--wide"><label for="title">Request title <span class="required-mark">*</span></label><input id="title" type="text" name="title" maxlength="160" placeholder="Briefly describe what you need" required></div>
                    <div class="input-group"><label for="category">Service category <span class="required-mark">*</span></label><select id="category" name="category" required><?php foreach (TICKET_CATEGORIES as $category): ?><option value="<?= ticket_escape($category) ?>"><?= ticket_escape($category) ?></option><?php endforeach; ?></select></div>
                    <div class="input-group"><label for="priority">Priority <span class="required-mark">*</span></label><select id="priority" name="priority" required><option value="Normal" selected>Normal · Standard request</option><option value="Low">Low · Flexible timing</option><option value="High">High · Work significantly impacted</option></select><span class="field-hint">Choose High only when work or essential services are significantly impacted.</span></div>
                    <div class="input-group"><label for="department">Department</label><?php if ($departments): ?><select id="department" name="department"><option value="">Choose department (optional)</option><?php foreach ($departments as $department): ?><option value="<?= ticket_escape($department) ?>"><?= ticket_escape($department) ?></option><?php endforeach; ?><option value="Other">Other</option></select><?php else: ?><input id="department" type="text" name="department" maxlength="100" placeholder="e.g. Finance or Student Services"><?php endif; ?></div>
                    <div class="input-group"><label for="location">Office or campus</label><input id="location" type="text" name="location" maxlength="120" placeholder="Building, site, campus, or remote"></div>
                    <div class="input-group"><label for="asset_tag">Device or asset reference</label><input id="asset_tag" type="text" name="asset_tag" maxlength="100" placeholder="Optional asset tag or inventory ID"></div>
                    <div class="input-group"><label for="issue_reported_via">How should we classify this?</label><select id="issue_reported_via" name="issue_reported_via"><option>Portal</option><option>Email</option><option>Phone</option><option>In person</option><option>System alert</option></select></div>
                    <div class="input-group input-group--wide"><label for="description">What’s happening? <span class="required-mark">*</span></label><textarea id="description" name="description" rows="6" maxlength="5000" placeholder="Include what you were doing, what you expected, and any error messages." required></textarea><span class="field-hint">Please do not include passwords or other sensitive personal information.</span></div>
                </div>
                <div class="form-actions"><a class="button button--secondary" href="history.php">Cancel</a><button class="button button--primary" type="submit">Submit request <span aria-hidden="true">→</span></button></div>
            </form>
        </section>
        <p class="page-footnote page-footnote--center">You can check your request’s progress and reply to updates at any time.</p>
    </main>
</div>
</body>
</html>
