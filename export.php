<?php
require_once __DIR__ . '/ticket_store.php';
$user = require_role(['admin', 'super_admin']);
$tickets = visible_tickets($user);
usort($tickets, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

$safeCsv = static function ($value): string {
    $text = (string) ($value ?? '');
    if (preg_match('/^[\s]*[=+\-@\t\r]/', $text)) {
        return "'" . $text;
    }
    return $text;
};

audit_event($user, 'ticket.exported', 'ticket_collection', organization_id($user), ['count' => count($tickets)]);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="masix-service-requests-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store, max-age=0');
echo "\xEF\xBB\xBF";
$output = fopen('php://output', 'wb');
if ($output === false) {
    throw new RuntimeException('Unable to prepare the request export.');
}
fputcsv($output, ['Reference', 'Title', 'Requester', 'Requester email', 'Department', 'Category', 'Priority', 'Status', 'Assignee', 'Location', 'Asset reference', 'Created', 'Updated', 'Response target'], ',', '"', '');
foreach ($tickets as $ticket) {
    fputcsv($output, array_map($safeCsv, [
        ticket_value($ticket, 'ticket_id'),
        ticket_value($ticket, 'Issue'),
        ticket_value($ticket, 'name'),
        ticket_value($ticket, 'requester_email'),
        ticket_value($ticket, 'department'),
        ticket_value($ticket, 'category'),
        ticket_priority($ticket),
        ticket_value($ticket, 'status', 'Open'),
        ticket_value($ticket, 'assigned_to'),
        ticket_value($ticket, 'location'),
        ticket_value($ticket, 'asset_tag'),
        ticket_value($ticket, 'created_at', ticket_value($ticket, 'date_reported')),
        ticket_value($ticket, 'updated_at'),
        ticket_value($ticket, 'due_at'),
    ]), ',', '"', '');
}
fclose($output);
