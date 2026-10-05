<?php
require_once __DIR__ . '/ticket_store.php';
$user = require_role(['admin', 'super_admin']);
$events = array_values(array_filter(store_read('audit'), static fn (array $event): bool => ($event['organization_id'] ?? '') === organization_id($user)));
$query = trim(ticket_request_string($_GET, 'q'));
$roleFilter = ticket_request_string($_GET, 'role');
$actionFilter = ticket_request_string($_GET, 'action');
$validRoles = array_merge(USER_ROLES, ['system']);
$actionTypes = array_values(array_unique(array_column($events, 'action')));
sort($actionTypes);
if (!in_array($roleFilter, $validRoles, true)) {
    $roleFilter = '';
}
if (!in_array($actionFilter, $actionTypes, true)) {
    $actionFilter = '';
}
$filtered = array_values(array_filter($events, static function (array $event) use ($query, $roleFilter, $actionFilter): bool {
    if ($roleFilter !== '' && ($event['actor_role'] ?? '') !== $roleFilter) {
        return false;
    }
    if ($actionFilter !== '' && ($event['action'] ?? '') !== $actionFilter) {
        return false;
    }
    return $query === '' || stripos(implode(' ', [
        (string) ($event['actor_name'] ?? ''),
        (string) ($event['actor_email'] ?? ''),
        (string) ($event['action'] ?? ''),
        (string) ($event['entity_type'] ?? ''),
        (string) ($event['entity_id'] ?? ''),
        json_encode($event['details'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]), $query) !== false;
}));
$pageSize = 40;
$pages = max(1, (int) ceil(count($filtered) / $pageSize));
$page = min($pages, max(1, (int) ticket_request_string($_GET, 'page')));
$visible = array_slice($filtered, ($page - 1) * $pageSize, $pageSize);
$baseQuery = array_filter(['q' => $query, 'role' => $roleFilter, 'action' => $actionFilter]);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Audit trail · MASIX IT Support</title><link rel="stylesheet" href="style.css"></head>
<body><div class="app-shell">
    <?php app_header('audit', $user); ?>
    <main class="page-content">
        <div class="page-heading"><div><p class="eyebrow">ACCOUNTABILITY & OVERSIGHT</p><h1>Audit trail</h1><p class="page-lead">A timestamped record of sign-ins, access changes, and service activity.</p></div><span class="badge badge--status-in-progress"><?= count($events) ?> recorded events</span></div>
        <section class="audit-summary"><span class="audit-summary-icon">≋</span><div><strong>Organization-wide activity history</strong><p>Events are recorded as key actions occur and retained when a request is deleted. Internal note content and passwords are never included.</p></div><span class="audit-summary-shield">✓ PRIVATE TO THIS ORGANIZATION</span></section>
        <section class="panel">
            <div class="panel-heading"><div><h2>Activity log <span class="count-pill"><?= count($filtered) ?></span></h2><p>Newest activity appears first. Search names, references, event types, or roles.</p></div></div>
            <form class="filters" method="GET" action="audit.php">
                <label class="search-field"><span class="visually-hidden">Search audit events</span><span aria-hidden="true">⌕</span><input type="search" name="q" value="<?= ticket_escape($query) ?>" placeholder="Search activity…"></label>
                <label><span class="visually-hidden">Filter by role</span><select name="role"><option value="">Any role</option><?php foreach ($validRoles as $role): ?><option value="<?= ticket_escape($role) ?>" <?= $roleFilter === $role ? 'selected' : '' ?>><?= ticket_escape(USER_ROLE_LABELS[$role] ?? ucfirst($role)) ?></option><?php endforeach; ?></select></label>
                <label><span class="visually-hidden">Filter by activity</span><select name="action"><option value="">Any activity</option><?php foreach ($actionTypes as $action): ?><option value="<?= ticket_escape($action) ?>" <?= $actionFilter === $action ? 'selected' : '' ?>><?= ticket_escape(str_replace(['.', '_'], [' › ', ' '], $action)) ?></option><?php endforeach; ?></select></label>
                <button class="button button--secondary" type="submit">Apply filters</button>
                <?php if ($query !== '' || $roleFilter !== '' || $actionFilter !== ''): ?><a class="clear-link" href="audit.php">Clear</a><?php endif; ?>
            </form>
            <?php if ($visible): ?>
                <div class="table-scroll"><table class="audit-table"><thead><tr><th>Activity</th><th>Performed by</th><th>Record</th><th>Details</th><th>Date & time</th></tr></thead><tbody>
                    <?php foreach ($visible as $event): $details = $event['details'] ?? []; $summary = implode(' · ', array_map(static fn ($key, $value): string => str_replace('_', ' ', $key) . ': ' . (is_scalar($value) ? (string) $value : json_encode($value)), array_keys($details), array_values($details))); ?>
                        <tr><td><span class="audit-action"><?= ticket_escape(str_replace(['.', '_'], [' › ', ' '], $event['action'] ?? 'Activity')) ?></span><span class="cell-subtitle"><?= ticket_escape(ucfirst($event['entity_type'] ?? 'event')) ?></span></td><td><strong><?= ticket_escape($event['actor_name'] ?? 'System') ?></strong><span class="cell-subtitle"><?= ticket_escape(USER_ROLE_LABELS[$event['actor_role'] ?? 'system'] ?? ucfirst($event['actor_role'] ?? 'system')) ?><?= !empty($event['actor_email']) ? ' · ' . ticket_escape($event['actor_email']) : '' ?></span></td><td><span class="ticket-id"><?= ticket_escape($event['entity_id'] ?? '—') ?></span></td><td class="audit-details"><?= ticket_escape($summary ?: '—') ?></td><td><time datetime="<?= ticket_escape($event['created_at'] ?? '') ?>"><?= ticket_escape(substr(str_replace('T', ' ', (string) ($event['created_at'] ?? '')), 0, 19)) ?></time></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <?php if ($pages > 1): ?><nav class="pagination" aria-label="Audit pages"><span>Page <?= $page ?> of <?= $pages ?></span><span><?php if ($page > 1): ?><a href="?<?= http_build_query($baseQuery + ['page' => $page - 1]) ?>">← Previous</a><?php endif; ?><?php if ($page < $pages): ?><a href="?<?= http_build_query($baseQuery + ['page' => $page + 1]) ?>">Next →</a><?php endif; ?></span></nav><?php endif; ?>
            <?php else: ?>
                <div class="empty-state"><span class="empty-icon" aria-hidden="true">≋</span><h3><?= $events ? 'No matching events' : 'Activity will appear here' ?></h3><p><?= $events ? 'Try another search or clear your filters.' : 'Account creation, ticket updates, and other important actions will be recorded automatically.' ?></p></div>
            <?php endif; ?>
        </section>
        <p class="page-footnote">Audit records cannot be edited from this application. Only administrators can access this page.</p>
    </main>
</div></body>
</html>
