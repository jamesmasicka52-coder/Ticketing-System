<?php
require_once __DIR__ . '/ticket_store.php';

if (!installation_ready()) {
    header('Location: setup.php');
    exit;
}
$user = require_login();
$role = $user['role'];
$tickets = visible_tickets($user);
$open = array_values(array_filter($tickets, static fn (array $ticket): bool => ticket_value($ticket, 'status', 'Open') === 'Open'));
$inProgress = array_values(array_filter($tickets, static fn (array $ticket): bool => ticket_value($ticket, 'status') === 'In Progress'));
$waiting = array_values(array_filter($tickets, static fn (array $ticket): bool => ticket_value($ticket, 'status') === 'Waiting on requester'));
$resolved = array_values(array_filter($tickets, static fn (array $ticket): bool => in_array(ticket_value($ticket, 'status'), ['Resolved', 'Closed'], true)));
$overdue = array_values(array_filter($tickets, static fn (array $ticket): bool => !in_array(ticket_value($ticket, 'status'), ['Resolved', 'Closed', 'Waiting on requester'], true) && empty($ticket['sla_paused_at']) && !empty($ticket['due_at']) && strtotime($ticket['due_at']) < time()));
$users = array_values(array_filter(store_read('users'), static fn (array $member): bool => organization_id($member) === organization_id($user) && ($member['active'] ?? false) === true));
$recent = array_slice(array_reverse($tickets), 0, 6);
$priorityHigh = count(array_filter($tickets, static fn (array $ticket): bool => ticket_priority($ticket) === 'High' && !in_array(ticket_value($ticket, 'status'), ['Resolved', 'Closed'], true)));
$greeting = match ($role) {
    'super_admin' => 'Your organization, in focus.',
    'admin' => 'Let’s keep things moving.',
    default => 'How can we help today?',
};
$roleIntro = match ($role) {
    'super_admin' => 'A clear view of service health, people, and every request across your organization.',
    'admin' => 'Your service desk at a glance. Triage requests and help your team get back to work.',
    default => 'See your requests, share an update, and get help from your service team.',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f5f7fb">
    <title><?= ticket_escape(USER_ROLE_LABELS[$role]) ?> dashboard · MASIX IT Support</title><link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <?php app_header('dashboard', $user); ?>
    <main class="page-content">
        <section class="welcome-banner">
            <div class="welcome-copy">
                <p class="eyebrow eyebrow--light"><?= ticket_escape(USER_ROLE_LABELS[$role]) ?> WORKSPACE</p>
                <h1><?= ticket_escape($greeting) ?></h1>
                <p><?= ticket_escape($roleIntro) ?></p>
                <div class="button-row">
                    <a class="button button--white" href="new.php">＋ Submit a request</a>
                    <a class="button button--outline-light" href="history.php"><?= $role === 'user' ? 'Track my requests' : 'Open service desk' ?></a>
                </div>
            </div>
            <div class="welcome-aside" aria-hidden="true">
                <span class="welcome-orbit welcome-orbit--outer"></span><span class="welcome-orbit welcome-orbit--inner"></span>
                <span class="welcome-symbol">M</span><span class="welcome-tag welcome-tag--one">Service, made simple</span><span class="welcome-tag welcome-tag--two">A better way to get help</span>
            </div>
        </section>

        <div class="section-heading">
            <div><p class="eyebrow">LIVE SERVICE HEALTH</p><h2><?= $role === 'user' ? 'Your request overview' : 'Service desk overview' ?></h2></div>
            <a class="text-link" href="history.php"><?= $role === 'user' ? 'View my requests' : 'View all tickets' ?> <span aria-hidden="true">→</span></a>
        </div>
        <section class="metric-grid metric-grid--home" aria-label="Ticket summary">
            <article class="metric-card"><span class="metric-icon metric-icon--blue" aria-hidden="true">◷</span><span class="metric-label"><?= $role === 'user' ? 'Awaiting support' : 'Open requests' ?></span><strong><?= count($open) ?></strong><span class="metric-note">Ready for a first response</span></article>
            <article class="metric-card"><span class="metric-icon metric-icon--violet" aria-hidden="true">↗</span><span class="metric-label">In progress</span><strong><?= count($inProgress) ?></strong><span class="metric-note">Currently being worked on</span></article>
            <?php if ($role === 'user'): ?>
                <article class="metric-card"><span class="metric-icon metric-icon--amber" aria-hidden="true">…</span><span class="metric-label">Waiting for your reply</span><strong><?= count($waiting) ?></strong><span class="metric-note">We need a little more detail</span></article>
                <article class="metric-card"><span class="metric-icon metric-icon--green" aria-hidden="true">✓</span><span class="metric-label">Resolved</span><strong><?= count($resolved) ?></strong><span class="metric-note">Your completed requests</span></article>
            <?php else: ?>
                <article class="metric-card metric-card--alert"><span class="metric-icon metric-icon--amber" aria-hidden="true">!</span><span class="metric-label">Past response target</span><strong><?= count($overdue) ?></strong><span class="metric-note">Active requests past their SLA</span></article>
                <article class="metric-card"><span class="metric-icon metric-icon--green" aria-hidden="true">◎</span><span class="metric-label"><?= $role === 'super_admin' ? 'Active accounts' : 'Unassigned requests' ?></span><strong><?= $role === 'super_admin' ? count($users) : count(array_filter($open, static fn (array $ticket): bool => empty($ticket['assignee_id']))) ?></strong><span class="metric-note"><?= $role === 'super_admin' ? 'Across your organization' : 'Waiting for an owner' ?></span></article>
            <?php endif; ?>
        </section>

        <?php if ($role !== 'user'): ?>
            <section class="service-insights">
                <div class="insight-heading"><div><p class="eyebrow">PRIORITY PULSE</p><h2>Focus where it matters</h2></div><span class="insight-subtitle">High-priority open requests</span></div>
                <div class="priority-pulse"><span class="pulse-indicator"></span><div><strong><?= $priorityHigh ?> high-priority <?= $priorityHigh === 1 ? 'request' : 'requests' ?> need attention</strong><p>Prioritize impact, keep requesters informed, and record important decisions.</p></div><a class="text-link" href="history.php?priority=High">Review high priority <span aria-hidden="true">→</span></a></div>
            </section>
        <?php endif; ?>

        <section class="panel recent-panel">
            <div class="panel-heading"><div><h2><?= $role === 'user' ? 'Your recent requests' : 'Recently updated' ?></h2><p><?= $role === 'user' ? 'Updates from your personal support queue.' : 'The latest requests from your service queue.' ?></p></div><a class="button button--secondary button--small" href="history.php"><?= $role === 'user' ? 'All my requests' : 'Open ticket queue' ?></a></div>
            <?php if ($recent): ?>
                <div class="recent-list">
                    <?php foreach ($recent as $ticket): $priority = ticket_priority($ticket); $status = ticket_value($ticket, 'status', 'Open'); ?>
                        <a class="recent-item" href="manage_ticket.php?id=<?= rawurlencode(ticket_value($ticket, 'ticket_id')) ?>">
                            <span class="recent-ticket-mark" aria-hidden="true">#</span>
                            <span class="recent-main"><strong><?= ticket_escape(ticket_value($ticket, 'Issue')) ?></strong><span><?= ticket_escape(ticket_value($ticket, 'ticket_id')) ?> · <?= ticket_escape(ticket_value($ticket, 'name')) ?></span></span>
                            <span class="badge badge--<?= strtolower($priority) ?>"><?= ticket_escape($priority) ?></span>
                            <span class="badge badge--status-<?= ticket_status_class($status) ?>"><?= ticket_escape($status) ?></span>
                            <span class="recent-arrow" aria-hidden="true">→</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state empty-state--compact"><span class="empty-icon" aria-hidden="true">✦</span><h3><?= $role === 'user' ? 'Your support space is ready' : 'A fresh start' ?></h3><p><?= $role === 'user' ? 'Submit a request whenever you need help. You can follow its progress here.' : 'New service requests will appear here as your team starts using MASIX.' ?></p><a class="button button--primary" href="new.php">Submit a request</a></div>
            <?php endif; ?>
        </section>
        <?php if ($role === 'super_admin'): ?><section class="admin-shortcuts"><a class="shortcut-card" href="users.php"><span class="shortcut-icon">♙</span><span><strong>Manage your team</strong><small>Provision service desk and employee accounts.</small></span><span aria-hidden="true">→</span></a><a class="shortcut-card" href="audit.php"><span class="shortcut-icon shortcut-icon--violet">≋</span><span><strong>Review audit trail</strong><small>Explore a timestamped record of workspace activity.</small></span><span aria-hidden="true">→</span></a></section><?php endif; ?>
        <footer class="site-footer"><span>MASIX IT SUPPORT · <?= ticket_escape(app_settings()['organization_name'] ?? '') ?></span><span>HELP FOR EVERY TEAM, ONE PLACE</span></footer>
    </main>
</div>
</body>
</html>
