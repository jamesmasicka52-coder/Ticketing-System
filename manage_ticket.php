<?php
require_once __DIR__ . '/ticket_store.php';
$user = require_login();
$role = $user['role'];
$id = '';
$ticket = null;

$findVisibleTicket = static function (array $tickets) use ($user, &$ticket, &$id): bool {
    foreach ($tickets as $candidate) {
        if (ticket_value($candidate, 'ticket_id') === $id && ($candidate['organization_id'] ?? organization_id($user)) === organization_id($user)) {
            if ($user['role'] !== 'user' || ($candidate['submitter_id'] ?? '') === $user['id']) {
                $ticket = $candidate;
                return true;
            }
        }
    }
    return false;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ticket_require_csrf();
    $id = isset($_POST['ticket_id']) && is_string($_POST['ticket_id']) ? trim($_POST['ticket_id']) : '';
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    if ($id === '') {
        http_response_code(400);
        exit('A request reference is required.');
    }

    if ($action === 'comment') {
        $body = isset($_POST['body']) && is_string($_POST['body']) ? trim($_POST['body']) : '';
        $visibility = $role !== 'user' && isset($_POST['visibility']) && $_POST['visibility'] === 'internal' ? 'internal' : 'public';
        if ($body === '' || strlen($body) > 3000) {
            http_response_code(422);
            exit('Enter a reply of up to 3,000 characters.');
        }
        $updated = ticket_store_update(static function (array &$tickets) use ($id, $user, $body, $visibility): bool {
            foreach ($tickets as &$candidate) {
                if (ticket_value($candidate, 'ticket_id') !== $id || ($candidate['organization_id'] ?? organization_id($user)) !== organization_id($user)) {
                    continue;
                }
                if ($user['role'] === 'user' && ($candidate['submitter_id'] ?? '') !== $user['id']) {
                    continue;
                }
                $candidate['comments'] ??= [];
                $candidate['comments'][] = [
                    'id' => bin2hex(random_bytes(12)),
                    'author_id' => $user['id'],
                    'author_name' => $user['name'],
                    'author_role' => $user['role'],
                    'body' => $body,
                    'visibility' => $visibility,
                    'created_at' => date(DATE_ATOM),
                ];
                $candidate['updated_at'] = date(DATE_ATOM);
                return true;
            }
            unset($candidate);
            return false;
        });
        if (!$updated) {
            http_response_code(404);
            exit('Request not found.');
        }
        audit_event($user, $visibility === 'internal' ? 'ticket.internal_note_added' : 'ticket.reply_added', 'ticket', $id);
        header('Location: manage_ticket.php?id=' . rawurlencode($id) . '&replied=1');
        exit;
    }

    if ($action === 'update' && $role !== 'user') {
        $status = isset($_POST['status']) && is_string($_POST['status']) ? $_POST['status'] : '';
        $priority = isset($_POST['priority']) && is_string($_POST['priority']) ? $_POST['priority'] : '';
        $assigneeId = isset($_POST['assignee_id']) && is_string($_POST['assignee_id']) ? trim($_POST['assignee_id']) : '';
        if (!in_array($status, TICKET_STATUSES, true) || !in_array($priority, TICKET_PRIORITIES, true)) {
            http_response_code(422);
            exit('Choose a valid request status and priority.');
        }
        $assignee = null;
        if ($assigneeId !== '') {
            foreach (store_read('users') as $member) {
                if (($member['id'] ?? '') === $assigneeId && organization_id($member) === organization_id($user) && ($member['active'] ?? false) && in_array($member['role'] ?? '', ['user', 'admin'], true)) {
                    $assignee = $member;
                    break;
                }
            }
            if ($assignee === null) {
                http_response_code(422);
                exit('Choose an active teammate from your organization.');
            }
        }
        $before = null;
        $updated = ticket_store_update(static function (array &$tickets) use ($id, $user, $status, $priority, $assignee, &$before): bool {
            foreach ($tickets as &$candidate) {
                if (ticket_value($candidate, 'ticket_id') !== $id || ($candidate['organization_id'] ?? organization_id($user)) !== organization_id($user)) {
                    continue;
                }
                $before = ['status' => ticket_value($candidate, 'status', 'Open'), 'priority' => ticket_priority($candidate), 'assignee_id' => (string) ($candidate['assignee_id'] ?? '')];
                $candidate['status'] = $status;
                $candidate['priority'] = $priority;
                $candidate['assignee_id'] = $assignee['id'] ?? '';
                $candidate['assigned_to'] = $assignee['name'] ?? '';
                $now = time();
                if ($before['priority'] !== $priority) {
                    $candidate['due_at'] = date(DATE_ATOM, $now + ticket_sla_hours($priority) * 3600);
                }
                if ($status === 'Waiting on requester' && $before['status'] !== 'Waiting on requester') {
                    $candidate['sla_paused_at'] = date(DATE_ATOM, $now);
                } elseif ($before['status'] === 'Waiting on requester' && $status !== 'Waiting on requester') {
                    $pausedAt = strtotime((string) ($candidate['sla_paused_at'] ?? ''));
                    $dueAt = strtotime((string) ($candidate['due_at'] ?? ''));
                    if ($pausedAt !== false && $dueAt !== false) {
                        $candidate['due_at'] = date(DATE_ATOM, $dueAt + max(0, $now - $pausedAt));
                    }
                    unset($candidate['sla_paused_at']);
                }
                $candidate['updated_at'] = date(DATE_ATOM);
                return true;
            }
            unset($candidate);
            return false;
        });
        if (!$updated) {
            http_response_code(404);
            exit('Request not found.');
        }
        $changes = [];
        if ($before['status'] !== $status) {
            $changes['status'] = ['from' => $before['status'], 'to' => $status];
        }
        if ($before['priority'] !== $priority) {
            $changes['priority'] = ['from' => $before['priority'], 'to' => $priority];
        }
        if ($before['assignee_id'] !== ($assignee['id'] ?? '')) {
            $changes['assigned_to'] = $assignee['name'] ?? 'Unassigned';
        }
        if ($changes) {
            audit_event($user, 'ticket.updated', 'ticket', $id, $changes);
        }
        header('Location: manage_ticket.php?id=' . rawurlencode($id) . '&saved=1');
        exit;
    }

    if (in_array($action, ['close', 'reopen'], true) && $role === 'user') {
        $targetStatus = $action === 'close' ? 'Closed' : 'Open';
        $expectedStatus = $action === 'close' ? 'Resolved' : 'Closed';
        $updated = ticket_store_update(static function (array &$tickets) use ($id, $user, $targetStatus, $expectedStatus): bool {
            foreach ($tickets as &$candidate) {
                if (ticket_value($candidate, 'ticket_id') === $id && ($candidate['organization_id'] ?? organization_id($user)) === organization_id($user) && ($candidate['submitter_id'] ?? '') === $user['id'] && ticket_value($candidate, 'status') === $expectedStatus) {
                    $candidate['status'] = $targetStatus;
                    if ($targetStatus === 'Open') {
                        $candidate['due_at'] = date(DATE_ATOM, time() + ticket_sla_hours(ticket_priority($candidate)) * 3600);
                        unset($candidate['sla_paused_at']);
                    }
                    $candidate['updated_at'] = date(DATE_ATOM);
                    return true;
                }
            }
            unset($candidate);
            return false;
        });
        if (!$updated) {
            http_response_code(409);
            exit('This request can no longer be updated. Refresh the page and try again.');
        }
        audit_event($user, $action === 'close' ? 'ticket.closed' : 'ticket.reopened', 'ticket', $id, ['status' => $targetStatus]);
        header('Location: manage_ticket.php?id=' . rawurlencode($id) . '&saved=1');
        exit;
    }

    if ($action === 'delete' && $role === 'super_admin') {
        $deleted = ticket_store_update(static function (array &$tickets) use ($id, $user): bool {
            foreach ($tickets as $key => $candidate) {
                if (ticket_value($candidate, 'ticket_id') === $id && ($candidate['organization_id'] ?? organization_id($user)) === organization_id($user)) {
                    unset($tickets[$key]);
                    return true;
                }
            }
            return false;
        });
        if (!$deleted) {
            http_response_code(404);
            exit('Request not found.');
        }
        audit_event($user, 'ticket.deleted', 'ticket', $id);
        header('Location: history.php?deleted=1');
        exit;
    }

    http_response_code(403);
    exit('You do not have permission to perform this action.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}
$id = isset($_GET['id']) && is_string($_GET['id']) ? trim($_GET['id']) : '';
if ($id === '') {
    http_response_code(400);
    exit('A request reference is required.');
}
$findVisibleTicket(ticket_store_read());
if ($ticket === null) {
    http_response_code(404);
}
$canManage = $role !== 'user';
$canPostInternal = $canManage;
$assignedTeammates = array_values(array_filter(store_read('users'), static fn (array $member): bool => organization_id($member) === organization_id($user) && ($member['active'] ?? false) && in_array($member['role'] ?? '', ['user', 'admin'], true)));
$comments = array_values(array_filter($ticket['comments'] ?? [], static fn (array $comment): bool => $role !== 'user' || ($comment['visibility'] ?? 'public') === 'public'));
$saved = isset($_GET['saved']) || isset($_GET['replied']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f5f7fb">
    <title><?= $ticket ? ticket_escape($id) . ' · MASIX IT Support' : 'Request not found · MASIX IT Support' ?></title><link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <?php app_header('tickets', $user); ?>
    <main class="page-content page-content--narrow">
        <a class="back-link" href="history.php">← Back to <?= $role === 'user' ? 'my requests' : 'service desk' ?></a>
        <?php if ($ticket !== null): $status = ticket_value($ticket, 'status', 'Open'); $priority = ticket_priority($ticket); ?>
            <section class="panel form-panel ticket-detail-panel">
                <div class="detail-heading"><div><p class="eyebrow">SERVICE REQUEST · <?= ticket_escape($ticket['ticket_id']) ?></p><h1><?= ticket_escape(ticket_value($ticket, 'Issue')) ?></h1><p class="page-lead">Submitted by <?= ticket_escape(ticket_value($ticket, 'name')) ?> · <?= ticket_escape(substr(ticket_value($ticket, 'created_at'), 0, 10)) ?></p></div><span class="badge badge--status-<?= ticket_status_class($status) ?>"><?= ticket_escape($status) ?></span></div>
                <?php if ($saved): ?><div class="notice notice--success" role="status"><?= isset($_GET['replied']) ? 'Your update was added to the request.' : 'Request updates saved successfully.' ?></div><?php endif; ?>
                <div class="detail-grid"><div><span>REQUESTER</span><strong><?= ticket_escape(ticket_value($ticket, 'name')) ?></strong></div><div><span>DEPARTMENT</span><strong><?= ticket_escape(ticket_value($ticket, 'department') ?: 'Not specified') ?></strong></div><div><span>SERVICE</span><strong><?= ticket_escape(ticket_value($ticket, 'category')) ?></strong></div><div><span>PRIORITY</span><strong><span class="badge badge--<?= strtolower($priority) ?>"><?= ticket_escape($priority) ?></span></strong></div><div><span>LOCATION</span><strong><?= ticket_escape(ticket_value($ticket, 'location') ?: 'Not specified') ?></strong></div><div><span>DEVICE / ASSET</span><strong><?= ticket_escape(ticket_value($ticket, 'asset_tag') ?: 'Not specified') ?></strong></div><div><span>ASSIGNED TO</span><strong><?= ticket_escape(ticket_value($ticket, 'assigned_to') ?: 'Not yet assigned') ?></strong></div><div><span>LAST UPDATED</span><strong><?= ticket_escape(substr(ticket_value($ticket, 'updated_at', ticket_value($ticket, 'created_at')), 0, 16)) ?></strong></div><?php if ($canManage && !empty($ticket['sla_paused_at'])): ?><div><span>RESPONSE TARGET</span><strong>SLA paused while awaiting requester</strong></div><?php elseif ($canManage && !empty($ticket['due_at'])): ?><div><span>RESPONSE TARGET</span><strong><?= ticket_escape(substr($ticket['due_at'], 0, 16)) ?></strong></div><?php endif; ?></div>
                <div class="description-block"><span>REQUEST DETAILS</span><p><?= nl2br(ticket_escape(ticket_value($ticket, 'issue'))) ?></p></div>

                <?php if ($canManage): ?>
                    <form method="POST" class="manage-form">
                        <input type="hidden" name="csrf_token" value="<?= ticket_escape(ticket_csrf_token()) ?>"><input type="hidden" name="ticket_id" value="<?= ticket_escape($id) ?>"><input type="hidden" name="action" value="update">
                        <h2>Manage request</h2>
                        <div class="form-grid"><div class="input-group"><label for="status">Status</label><select id="status" name="status"><?php foreach (TICKET_STATUSES as $option): ?><option value="<?= ticket_escape($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= ticket_escape($option) ?></option><?php endforeach; ?></select></div><div class="input-group"><label for="priority">Priority</label><select id="priority" name="priority"><?php foreach (TICKET_PRIORITIES as $option): ?><option value="<?= ticket_escape($option) ?>" <?= $priority === $option ? 'selected' : '' ?>><?= ticket_escape($option) ?> priority</option><?php endforeach; ?></select></div><div class="input-group input-group--wide"><label for="assignee_id">Assign to a teammate</label><select id="assignee_id" name="assignee_id"><option value="">Unassigned</option><?php foreach ($assignedTeammates as $member): ?><option value="<?= ticket_escape($member['id']) ?>" <?= ($ticket['assignee_id'] ?? '') === $member['id'] ? 'selected' : '' ?>><?= ticket_escape($member['name']) ?> · <?= ticket_escape(USER_ROLE_LABELS[$member['role']] ?? 'User') ?></option><?php endforeach; ?></select></div></div>
                        <div class="form-actions"><button class="button button--primary" type="submit">Save request changes</button></div>
                    </form>
                <?php elseif ($status === 'Resolved'): ?>
                    <form method="POST" class="request-status-action"><input type="hidden" name="csrf_token" value="<?= ticket_escape(ticket_csrf_token()) ?>"><input type="hidden" name="ticket_id" value="<?= ticket_escape($id) ?>"><input type="hidden" name="action" value="close"><span>Has this request been resolved for you?</span><button class="button button--primary" type="submit">Yes, close request</button></form>
                <?php elseif ($status === 'Closed'): ?>
                    <form method="POST" class="request-status-action"><input type="hidden" name="csrf_token" value="<?= ticket_escape(ticket_csrf_token()) ?>"><input type="hidden" name="ticket_id" value="<?= ticket_escape($id) ?>"><input type="hidden" name="action" value="reopen"><span>Still need help? Reopen this request for your service team.</span><button class="button button--secondary" type="submit">Reopen request</button></form>
                <?php endif; ?>

                <section class="conversation-section"><div class="conversation-heading"><div><p class="eyebrow">KEEP THINGS MOVING</p><h2>Conversation</h2></div><span class="count-pill"><?= count($comments) ?> <?= count($comments) === 1 ? 'update' : 'updates' ?></span></div>
                    <?php if ($comments): ?><div class="conversation-list"><?php foreach ($comments as $comment): $internal = ($comment['visibility'] ?? 'public') === 'internal'; ?><article class="comment-card<?= $internal ? ' comment-card--internal' : '' ?>"><span class="comment-avatar"><?= ticket_escape(strtoupper(substr((string) ($comment['author_name'] ?? 'M'), 0, 1))) ?></span><div class="comment-content"><div class="comment-meta"><strong><?= ticket_escape($comment['author_name'] ?? 'Team member') ?></strong><span><?= ticket_escape(USER_ROLE_LABELS[$comment['author_role'] ?? 'user'] ?? 'Team member') ?></span><time datetime="<?= ticket_escape($comment['created_at'] ?? '') ?>"><?= ticket_escape(substr((string) ($comment['created_at'] ?? ''), 0, 16)) ?></time><?php if ($internal): ?><span class="internal-pill">TEAM ONLY</span><?php endif; ?></div><p><?= nl2br(ticket_escape($comment['body'] ?? '')) ?></p></div></article><?php endforeach; ?></div><?php else: ?><div class="conversation-empty"><span class="empty-icon" aria-hidden="true">↗</span><strong>No updates yet</strong><span>Replies from you and the support team will appear here.</span></div><?php endif; ?>
                    <form method="POST" class="reply-form"><input type="hidden" name="csrf_token" value="<?= ticket_escape(ticket_csrf_token()) ?>"><input type="hidden" name="ticket_id" value="<?= ticket_escape($id) ?>"><input type="hidden" name="action" value="comment"><label class="input-group" for="body"><span><?= $canManage ? 'Reply or add an internal note' : 'Add a reply' ?></span><textarea id="body" name="body" rows="4" maxlength="3000" placeholder="<?= $canManage ? 'Share an update with the requester or leave a private team note…' : 'Share a detail or ask a follow-up question…' ?>" required></textarea></label><?php if ($canPostInternal): ?><label class="internal-toggle"><input type="checkbox" name="visibility" value="internal"><span><strong>Private internal note</strong><small>Only administrators can see internal notes.</small></span></label><?php endif; ?><div class="form-actions"><button class="button button--primary" type="submit">Post update <span aria-hidden="true">→</span></button></div></form>
                </section>
                <?php if ($role === 'super_admin'): ?><form method="POST" class="delete-form" onsubmit="return confirm('Permanently delete this request? Its audit events will remain.');"><input type="hidden" name="csrf_token" value="<?= ticket_escape(ticket_csrf_token()) ?>"><input type="hidden" name="ticket_id" value="<?= ticket_escape($id) ?>"><input type="hidden" name="action" value="delete"><div><strong>Permanently delete request</strong><span>Retained audit events will preserve evidence of the deletion.</span></div><button class="button button--danger" type="submit">Delete request</button></form><?php endif; ?>
            </section>
        <?php else: ?><section class="panel error-panel"><span class="error-icon" aria-hidden="true">!</span><p class="eyebrow">NOT FOUND</p><h1>This request isn’t available.</h1><p>It may have been removed or you may not have permission to view it.</p><a class="button button--primary" href="history.php">Back to your queue</a></section><?php endif; ?>
    </main>
</div>
</body>
</html>
