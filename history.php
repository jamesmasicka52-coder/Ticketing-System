<?php

$file = "tickets.json";

$tickets = [];

if(file_exists($file)){
    $tickets = json_decode(file_get_contents($file), true);
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Ticket History</title>
    <link rel="stylesheet" href="style.css">

    <meta http-equiv="refresh" content="5">

</head>
<body>

<div class="history-container">

    <div class="history-header">
        <h1>Ticket Management Dashboard</h1>

        <div class="header-buttons">
            <a href="New.html" class="main-btn">New Ticket</a>
            <a href="index.html" class="secondary-btn">Dashboard</a>
        </div>
    </div>

    <div class="table-wrapper">

        <table>

            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Issue</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Department</th>
                    <th>Assigned To</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>

            <?php if(!empty($tickets)): ?>

                <?php foreach(array_reverse($tickets) as $ticket): ?>

                    <tr>

                        <td>#<?php echo $ticket['ticket_id']; ?></td>

                        <td><?php echo $ticket['name']; ?></td>

                        <td><?php echo $ticket['Issue']; ?></td>

                        <td><?php echo $ticket['category']; ?></td>

                        <td>
                            <span class="priority <?php echo strtolower($ticket['priority']); ?>">
                                <?php echo $ticket['priority']; ?>
                            </span>
                        </td>

                        <td>
                            <span class="status-badge">
                                <?php echo $ticket['status']; ?>
                            </span>
                        </td>

                        <td><?php echo $ticket['department']; ?></td>

                        <td><?php echo $ticket['assigned_to']; ?></td>

                        <td><?php echo $ticket['date_reported']; ?></td>

                        <td>
                            <a href="manage_ticket.php?id=<?php echo $ticket['ticket_id']; ?>" class="main-btn">
                                Manage
                            </a>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

</body>
</html>