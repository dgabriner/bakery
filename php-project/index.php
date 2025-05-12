<?php
require_once 'config/database.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $message = $_POST['message'];
    
    try {
        $stmt = $db->prepare("INSERT INTO messages (message) VALUES (?)");
        $stmt->execute([$message]);
        echo "<div style='color: green;'>Message added successfully!</div>";
    } catch(PDOException $e) {
        echo "<div style='color: red;'>Error: " . $e->getMessage() . "</div>";
    }
}

// Get all messages
try {
    $stmt = $db->query("SELECT * FROM messages ORDER BY created_at DESC");
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $messages = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Message Board</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f0f2f5;
        }
        .container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .message {
            border: 1px solid #ddd;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .timestamp {
            color: #666;
            font-size: 0.9em;
        }
        form {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Message Board</h1>
        
        <form method="POST" action="">
            <textarea name="message" rows="3" placeholder="Enter your message..." required></textarea><br>
            <button type="submit">Add Message</button>
        </form>

        <?php foreach ($messages as $message): ?>
            <div class="message">
                <p><?php echo htmlspecialchars($message['message']); ?></p>
                <div class="timestamp">
                    Posted on: <?php echo date('Y-m-d H:i:s', strtotime($message['created_at'])); ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
