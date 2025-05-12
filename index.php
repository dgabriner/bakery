<?php
$customers = [
    ['name' => 'Alice Baker', 'favorite_bread' => 'Sourdough'],
    ['name' => 'Bob Boulanger', 'favorite_bread' => 'Baguette'],
    ['name' => 'Charlie Crumb', 'favorite_bread' => 'Whole Wheat']
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bread Customers</title>
    <style>
        .customer-card { 
            border: 1px solid #ddd; 
            padding: 1rem; 
            margin: 1rem 0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <h1>Our Bread Customers</h1>
    
    <?php foreach ($customers as $customer): ?>
        <div class="customer-card">
            <h3><?= htmlspecialchars($customer['name']) ?></h3>
            <p>Favorite Bread: <?= htmlspecialchars($customer['favorite_bread']) ?></p>
        </div>
    <?php endforeach; ?>
</body>
</html>
