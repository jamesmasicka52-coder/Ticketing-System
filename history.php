<?php
require_once __DIR__ . '/ticket_store.php';
$user = require_login();
$role = $user['role'];
$allTickets = array_reverse(visible_tickets($user));
$query = trim(ticket_request_string($_GET, 'q'));
$statusFilter = ticket_request_string($_GET, 'status');
$priorityFilter = ticket_request_string($_GET, 'priority');
if (!in_array($statusFilter, TICKET_STATUSES, true)) {
    $statusFilter = '';
}
if (!in_array($priorityFilter, TICKET_PRIORITIES, true)) {
    $priorityFilter = '';
}
$filtered = array_values(array_filter($allTickets, static function (array $ticket) use ($query, $statusFilter, $priorityFilter): bool {
    if ($statusFilter !== '' && ticket_value($ticket, 'status', 'Open') !== $statusFilter) {
        return false;
    }
    if ($priorityFilter !== '' && ticket_priority($ticket) !== $priorityFilter) {
        return false;
    }
    if ($query === '') {
        return true;
    }
    $searchable = implode(' ', [
        ticket_value($ticket, 'ticket_id'),
        ticket_value($ticket, 'name'),
        ticket_value($ticket, 'requester_email'),
        ticket_value($ticket, 'Issue'),
        ticket_value($ticket, 'category'),
        ticket_value($ticket, 'department'),
        ticket_value($ticket, 'assigned_to'),
        ticket_value($ticket, 'location'),
        ticket_value($ticket, 'asset_tag'),
    ]);
    return stripos($searchable, $query) !== false;
}));
$openCount = count(array_filter($allTickets, static fn (array $ticket): bool => ticket_value($ticket, 'status', 'Open') === 'Open'));
$activeCount = count(array_filter($allTickets, static fn (array $ticket): bool => in_array(ticket_value($ticket, 'status'), ['In Progress', 'Waiting on requester'], true)));
$closedCount = count(array_filter($allTickets, static fn (array $ticket): bool => in_array(ticket_value($ticket, 'status'), ['Resolved', 'Closed'], true)));
$pages = max(1, (int) ceil(count($filtered) / 25));
$page = min($pages, max(1, (int) ticket_request_string($_GET, 'page')));
$visible = array_slice($filtered, ($page - 1) * 25, 25);
$baseQuery = array_filter(['q' => $query, 'status' => $statusFilter, 'priority' => $priorityFilter]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f5f7fb">
    <title><?= $role === 'user' ? 'My requests' : 'Service desk' ?> · MASIX IT Support</title><link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <?php app_header('tickets', $user); ?>
    <main class="page-content">
        <div class="page-heading"><div><p class="eyebrow"><?= $role === 'user' ? 'YOUR SUPPORT ACTIVITY' : 'SERVICE OPERATIONS' ?></p><h1><?= $role === 'user' ? 'My requests' : 'Service desk' ?></h1><p class="page-lead"><?= $role === 'user' ? 'Follow progress, add a reply, or review a completed request.' : 'A shared queue for your team to coordinate service and resolve requests.' ?></p></div><a class="button button--primary" href="new.php">＋ New request</a></div>

        <section class="metric-grid" aria-label="Request summary">
            <article class="metric-card"><span class="metric-label">All requests</span><strong><?= count($allTickets) ?></strong><span class="metric-note">In your <?= $role === 'user' ? 'personal queue' : 'organization' ?></span></article>
            <article class="metric-card"><span class="metric-label">Open</span><strong><?= $openCount ?></strong><span class="metric-note">Ready for a first response</span></article>
            <article class="metric-card"><span class="metric-label">Active</span><strong><?= $activeCount ?></strong><span class="metric-note">Being worked on</span></article>
            <article class="metric-card"><span class="metric-label">Resolved</span><strong><?= $closedCount ?></strong><span class="metric-note">Resolved or closed</span></article>
        </section>

        <section class="panel">
            <div class="panel-heading"><div><h2><?= $role === 'user' ? 'Request history' : 'All requests' ?> <span class="count-pill"><?= count($filtered) ?></span></h2><p>Search by reference, requester, service, department, or device.</p></div><?php if ($role !== 'user'): ?><a class="button button--secondary button--small" href="export.php">↓ Export CSV</a><?php endif; ?></div>
            <?php if (isset($_GET['deleted']) && $_GET['deleted'] === '1'): ?><div class="notice notice--success history-notice" role="status">Request removed.</div><?php endif; ?>
            <form class="filters" method="GET" action="history.php">
                <label class="search-field"><span class="visually-hidden">Search requests</span><span aria-hidden="true">⌕</span><input type="search" name="q" value="<?= ticket_escape($query) ?>" placeholder="Search reference, requester, device…"></label>
                <label><span class="visually-hidden">Filter by status</span><select name="status"><option value="">Any status</option><?php foreach (TICKET_STATUSES as $status): ?><option value="<?= ticket_escape($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= ticket_escape($status) ?></option><?php endforeach; ?></select></label>
                <label><span class="visually-hidden">Filter by priority</span><select name="priority"><option value="">Any priority</option><?php foreach (TICKET_PRIORITIES as $priority): ?><option value="<?= ticket_escape($priority) ?>" <?= $priorityFilter === $priority ? 'selected' : '' ?>><?= ticket_escape($priority) ?></option><?php endforeach; ?></select></label>
                <button class="button button--secondary" type="submit">Apply filters</button>
                <?php if ($query !== '' || $statusFilter !== '' || $priorityFilter !== ''): ?><a class="clear-link" href="history.php">Clear</a><?php endif; ?>
            </form>
            <?php if ($visible): ?>
                <div class="table-scroll"><table>
                    <thead><tr><th>Reference</th><?php if ($role !== 'user'): ?><th>Requester</th><?php endif; ?><th>Request</th><th>Priority</th><th>Status</th><?php if ($role !== 'user'): ?><th>Assignee</th><?php endif; ?><th>Updated</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($visible as $ticket): $priority = ticket_priority($ticket); $status = ticket_value($ticket, 'status', 'Open'); $dueAt = strtotime((string) ($ticket['due_at'] ?? '')); $isOverdue = $dueAt && $dueAt < time() && empty($ticket['sla_paused_at']) && !in_array($status, ['Resolved', 'Closed', 'Waiting on requester'], true); ?>
                        <tr>
                            <td><span class="ticket-id"><?= ticket_escape($ticket['ticket_id'] ?? '') ?></span><span class="cell-subtitle"><?= ticket_escape(ticket_value($ticket, 'category')) ?></span></td>
                            <?php if ($role !== 'user'): ?><td><strong><?= ticket_escape(ticket_value($ticket, 'name')) ?></strong><span class="cell-subtitle"><?= ticket_escape(ticket_value($ticket, 'department') ?: ticket_value($ticket, 'company')) ?></span></td><?php endif; ?>
                            <td class="issue-cell"><strong><?= ticket_escape(ticket_value($ticket, 'Issue')) ?></strong><span class="cell-subtitle"><?= ticket_escape(ticket_value($ticket, 'location') ?: ticket_value($ticket, 'department')) ?></span></td>
                            <td><span class="badge badge--<?= strtolower($priority) ?>"><?= ticket_escape($priority) ?></span></td>
                            <td><span class="badge badge--status-<?= ticket_status_class($status) ?>"><?= ticket_escape($status) ?></span><?php if ($isOverdue && $role !== 'user'): ?><span class="cell-subtitle cell-subtitle--alert">Past SLA target</span><?php endif; ?></td>
                            <?php if ($role !== 'user'): ?><td><?= ticket_escape(ticket_value($ticket, 'assigned_to') ?: 'Unassigned') ?></td><?php endif; ?>
                            <td><?= ticket_escape(substr(ticket_value($ticket, 'updated_at', ticket_value($ticket, 'created_at')), 0, 10)) ?></td>
                            <td><a class="table-action" href="manage_ticket.php?id=<?= rawurlencode(ticket_value($ticket, 'ticket_id')) ?>"><?= $role === 'user' ? 'View' : 'Manage' ?> <span aria-hidden="true">→</span></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php if ($pages > 1): ?><nav class="pagination" aria-label="Request pages"><span>Page <?= $page ?> of <?= $pages ?></span><span><?php if ($page > 1): ?><a href="?<?= http_build_query($baseQuery + ['page' => $page - 1]) ?>">← Previous</a><?php endif; ?><?php if ($page < $pages): ?><a href="?<?= http_build_query($baseQuery + ['page' => $page + 1]) ?>">Next →</a><?php endif; ?></span></nav><?php endif; ?>
            <?php else: ?>
                <div class="empty-state"><span class="empty-icon" aria-hidden="true">✓</span><h3><?= $allTickets ? 'No matching requests' : 'Nothing in your queue yet' ?></h3><p><?= $allTickets ? 'Try a different search or remove a filter.' : 'When you submit a request, its progress and updates will appear here.' ?></p><?php if (!$allTickets): ?><a class="button button--primary" href="new.php">Submit a request</a><?php elseif ($query !== '' || $statusFilter !== '' || $priorityFilter !== ''): ?><a class="button button--secondary" href="history.php">Clear filters</a><?php endif; ?></div>
            <?php endif; ?>
        </section>
        <p class="page-footnote">Showing <?= count($visible) ?> of <?= count($filtered) ?> matching requests · Private to your organization.</p>
    </main>
</div>
</body>
</html>
