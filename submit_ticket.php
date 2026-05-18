<?php

$file = "tickets.json";

$last_id = 0;

if(file_exists($file)){

    $existingTickets = json_decode(file_get_contents($file), true);

    if(!empty($existingTickets)){

        $lastTicket = end($existingTickets);

        $last_id = intval(str_replace("MASIX-", "", $lastTicket['ticket_id']));

    }

}

$new_ticket_id = "MASIX-" . str_pad($last_id + 1, 4, "0", STR_PAD_LEFT);

if($_SERVER["REQUEST_METHOD"] == "POST"){

    $ticket = [

        "ticket_id" => $new_ticket_id,
        "name" => $_POST['name'],
        "Issue" => $_POST['Issue'],
        "issue" => $_POST['issue'],
        "category" => $_POST['category'],
        "priority" => $_POST['priority'],
        "status" => $_POST['status'],
        "assigned_to" => $_POST['assigned_to'],
        "company" => $_POST['company'],
        "department" => $_POST['department'],
        "issue_reported_via" => $_POST['issue_reported_via'],
        "date_reported" => $_POST['date_reported'],
        "created_at" => date("Y-m-d H:i:s")

    ];

    $tickets = [];

    if(file_exists($file)){
        $tickets = json_decode(file_get_contents($file), true);
    }

    $tickets[] = $ticket;

    file_put_contents($file, json_encode($tickets, JSON_PRETTY_PRINT));

}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Ticket Submitted</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="success-card">

    <div class="success-icon">✓</div>

    <h1>Ticket Submitted Successfully</h1>

    <p>Your ticket has been recorded in the system.</p>

    <div class="ticket-summary">

        <p><strong>Ticket ID:</strong> #<?php echo $ticket['ticket_id']; ?></p>
        <p><strong>Issue:</strong> <?php echo $ticket['Issue']; ?></p>
        <p><strong>Priority:</strong> <?php echo $ticket['priority']; ?></p>
        <p><strong>Status:</strong> <?php echo $ticket['status']; ?></p>

    </div>

    <div class="action-buttons">
        <a href="HIstory.php" class="main-btn">View Tickets</a>
        <a href="index.html" class="secondary-btn">Dashboard</a>
    </div>

</div>

</body>
</html>