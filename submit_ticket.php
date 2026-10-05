<?php
require_once __DIR__ . '/ticket_store.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the request form to submit a ticket.');
}
ticket_require_csrf();

$input = static fn (string $key): string => isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
$title = $input('title');
$description = $input('description');
$category = $input('category');
$priority = $input('priority');
$department = $input('department');
$location = $input('location');
$assetTag = $input('asset_tag');
$reportedVia = $input('issue_reported_via');
$errors = [];

if ($title === '' || strlen($title) > 160) {
    $errors[] = 'Enter a request title (up to 160 characters).';
}
if ($description === '' || strlen($description) > 5000) {
    $errors[] = 'Enter a description (up to 5,000 characters).';
}
if (!in_array($category, TICKET_CATEGORIES, true)) {
    $errors[] = 'Choose a valid service category.';
}
if (!in_array($priority, TICKET_PRIORITIES, true)) {
    $errors[] = 'Choose a valid priority.';
}
if (strlen($department) > 100 || strlen($location) > 120 || strlen($assetTag) > 100) {
    $errors[] = 'Check the length of your department, location, and asset details.';
}
if (!in_array($reportedVia, ['Portal', 'Email', 'Phone', 'In person', 'System alert'], true)) {
    $errors[] = 'Choose a valid request source.';
}

if ($errors) {
    http_response_code(422);
    ?>
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Check your request · MASIX IT Support</title><link rel="stylesheet" href="style.css"></head>
    <body><main class="page-content page-content--narrow"><section class="panel error-panel"><span class="error-icon" aria-hidden="true">!</span><p class="eyebrow">REQUEST NOT SUBMITTED</p><h1>Check your details</h1><p>Correct the following and try again:</p><ul><?php foreach ($errors as $error): ?><li><?= ticket_escape($error) ?></li><?php endforeach; ?></ul><a class="button button--primary" href="new.php">Return to request form</a></section></main></body></html>
    <?php
    exit;
}

$settings = app_settings();
$organizationId = organization_id($user);
$now = time();
$slaHours = ticket_sla_hours($priority);
$ticket = ticket_store_update(static function (array &$tickets) use ($user, $organizationId, $title, $description, $category, $priority, $department, $location, $assetTag, $reportedVia, $now, $slaHours, $settings): array {
    $lastId = 0;
    foreach ($tickets as $existing) {
        if (preg_match('/^MASIX-(\d+)$/', (string) ($existing['ticket_id'] ?? ''), $matches)) {
            $lastId = max($lastId, (int) $matches[1]);
        }
    }
    $created = date(DATE_ATOM, $now);
    $ticket = [
        'ticket_id' => 'MASIX-' . str_pad((string) ($lastId + 1), 5, '0', STR_PAD_LEFT),
        'organization_id' => $organizationId,
        'name' => (string) $user['name'],
        'requester_email' => (string) $user['email'],
        'submitter_id' => (string) $user['id'],
        'Issue' => $title,
        'issue' => $description,
        'category' => $category,
        'priority' => $priority,
        'status' => 'Open',
        'assigned_to' => '',
        'assignee_id' => '',
        'company' => (string) ($settings['organization_name'] ?? ''),
        'department' => $department,
        'location' => $location,
        'asset_tag' => $assetTag,
        'issue_reported_via' => $reportedVia,
        'date_reported' => date('Y-m-d', $now),
        'created_at' => $created,
        'updated_at' => $created,
        'due_at' => date(DATE_ATOM, $now + $slaHours * 3600),
        'comments' => [],
    ];
    $tickets[] = $ticket;
    return $ticket;
});

audit_event($user, 'ticket.created', 'ticket', $ticket['ticket_id'], ['title' => $title, 'priority' => $priority, 'category' => $category]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f5f7fb">
    <title>Request received · MASIX IT Support</title><link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <?php app_header('tickets', $user); ?>
    <main class="page-content page-content--narrow">
        <section class="panel success-panel">
            <span class="success-icon" aria-hidden="true">✓</span>
            <p class="eyebrow">REQUEST RECEIVED</p>
            <h1>Your request is in good hands.</h1>
            <p>Your service team has been notified. Keep this reference handy for follow-up.</p>
            <div class="success-ticket"><span>Request reference</span><strong><?= ticket_escape($ticket['ticket_id']) ?></strong><span class="badge badge--status-open">Open</span></div>
            <div class="success-summary"><span>REQUEST</span><strong><?= ticket_escape($title) ?></strong><span>PRIORITY</span><strong><?= ticket_escape($priority) ?></strong></div>
            <div class="button-row button-row--center"><a class="button button--primary" href="manage_ticket.php?id=<?= rawurlencode($ticket['ticket_id']) ?>">View request <span aria-hidden="true">→</span></a><a class="button button--secondary" href="index.php">My dashboard</a></div>
        </section>
    </main>
</div>
</body>
</html>
