<?php

$file = "tickets.json";

$tickets = [];

if(file_exists($file)){
    $tickets = json_decode(file_get_contents($file), true);
}

/* DELETE TICKET */

if(isset($_GET['delete'])){

    $id = $_GET['delete'];

    foreach($tickets as $key => $ticket){

        if($ticket['ticket_id'] == $id){

            unset($tickets[$key]);

        }

    }

    file_put_contents($file, json_encode(array_values($tickets), JSON_PRETTY_PRINT));

    header("Location: history.php");
    exit();

}

/* UPDATE TICKET */

if($_SERVER["REQUEST_METHOD"] == "POST"){

    $id = $_POST['ticket_id'];

    foreach($tickets as &$ticket){

        if($ticket['ticket_id'] == $id){

            $ticket['status'] = $_POST['status'];
            $ticket['priority'] = $_POST['priority'];
            $ticket['assigned_to'] = $_POST['assigned_to'];

        }

    }

    file_put_contents($file, json_encode($tickets, JSON_PRETTY_PRINT));

    header("Location: history.php");
    exit();

}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Ticket</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="form-container">

    <h1>Manage Ticket</h1>

    <?php

    if(isset($_GET['id'])){

        $id = $_GET['id'];

        foreach($tickets as $ticket){

            if($ticket['ticket_id'] == $id){

    ?>

    <form method="POST">

        <input type="hidden" name="ticket_id" value="<?php echo $ticket['ticket_id']; ?>">

        <div class="form-grid">

            <div class="input-group">
                <label>Ticket ID</label>
                <input type="text" value="#<?php echo $ticket['ticket_id']; ?>" disabled>
            </div>

            <div class="input-group">
                <label>Client Name</label>
                <input type="text" value="<?php echo $ticket['name']; ?>" disabled>
            </div>

            <div class="input-group">
                <label>Status</label>
                <select name="status">

                    <option <?php if($ticket['status']=="Open") echo "selected"; ?>>
                        Open
                    </option>

                    <option <?php if($ticket['status']=="In Progress") echo "selected"; ?>>
                        In Progress
                    </option>

                    <option <?php if($ticket['status']=="Closed") echo "selected"; ?>>
                        Closed
                    </option>

                </select>
            </div>

            <div class="input-group">
                <label>Priority</label>
                <select name="priority">

                    <option <?php if($ticket['priority']=="Low") echo "selected"; ?>>
                        Low
                    </option>

                    <option <?php if($ticket['priority']=="Medium") echo "selected"; ?>>
                        Medium
                    </option>

                    <option <?php if($ticket['priority']=="High") echo "selected"; ?>>
                        High
                    </option>

                    <option <?php if($ticket['priority']=="Critical") echo "selected"; ?>>
                        Critical
                    </option>

                </select>
            </div>

            <div class="input-group full-width">
                <label>Assigned Technician</label>
                <input type="text" name="assigned_to"
                value="<?php echo $ticket['assigned_to']; ?>">
            </div>

        </div>

        <button type="submit" class="submit-btn">
            Update Ticket
        </button>

    </form>

    <br>

    <a href="manage_ticket.php?delete=<?php echo $ticket['ticket_id']; ?>"
       class="secondary-btn"
       onclick="return confirm('Delete this ticket permanently?')">

       Delete Ticket

    </a>

    <?php

            }

        }

    }

    ?>

</div>

</body>
</html>