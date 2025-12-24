<?php

session_start();

// THE AUTHENTICATION GUARD IF Not login yet or session time out
if (!isset($_SESSION['role'])) {
    header("Location: 00_login.php");
    exit;
}

// GET DATA FROM DATABASE
require_once '00_db_connect.php';

// ____________________Update table every time this page is load___________
if (!$mysqli->query("CALL sp_update_table_status()")) {
    die("Error running status update: " . $mysqli->error);
}

//___________________________SQL Query_______________________________________

// SQL for the top 3 stat boxes (Today's Sales, Orders, Avg)
$sql_sales = "SELECT SUM(paid_amount) as sales, COUNT(RECEIPT_ID) as orders 
              FROM RECEIPT 
              WHERE DATE(created_at) = CURDATE()";
$result = $mysqli->query($sql_sales);

// SAFETY CHECK 1
if (!$result) {
    die("<strong>SQL Error (Sales Stats):</strong> " . $mysqli->error);
}

$stats = $result->fetch_assoc();
$today_sales = $stats['sales'] ?? 0;
$today_orders = $stats['orders'] ?? 0;
$today_avg = $today_orders > 0 ? $today_sales / $today_orders : 0;


// SQL for the 'Active Orders' badge (ORDER_BASKET == 'incomplete')
$sql_active = "SELECT COUNT(BASKET_ID) as active_count 
               FROM ORDER_BASKET 
               WHERE status = 'incomplete'";
$result = $mysqli->query($sql_active);

// SAFETY CHECK 2
if (!$result) {
    die("<strong>SQL Error (Active Orders):</strong> " . $mysqli->error);
}

$active_order_count = $result->fetch_assoc()['active_count'] ?? 0;

 
// SQL for the 'Active tables' list, using view in database
$all_tables = [];
$sql_tables = "SELECT * FROM v_alltable_need_detail";
$result = $mysqli->query($sql_tables);

// SAFETY CHECK 3 (This is the most likely problem)
if (!$result) {
    die("<strong>SQL Error (Active Tables View):</strong> " . $mysqli->error . 
       "<br><br><strong>Tip:</strong> Check that your VIEW `v_alltable_need_detail` was created successfully in the database.");
}
// This loop is now safe
while($row = $result->fetch_assoc()) { 
    $all_tables[] = $row; 
}

 
// SQL for the 'Kitchen Orders' list, using view in database
$kitchen_orders = [];
$sql_kitchen = "SELECT * FROM v_kitchen_orders";
$result = $mysqli->query($sql_kitchen);

// SAFETY CHECK 4 (Your original code was good, this is just more explicit)
if (!$result) {
    die("<strong>SQL Error (Kitchen Orders View):</strong> " . $mysqli->error .
       "<br><br><strong>Tip:</strong> Check that your VIEW `v_kitchen_orders` was created successfully in the database.");
}
// This loop is now safe
while($row = $result->fetch_assoc()) { 
    $kitchen_orders[] = $row; 
}

//___________________________other functions_______________________________________

// --- Helper Functions ---
function getTableChip($status) {
    if ($status === 'available') {
        return '<span class="chip chip-available">Available</span>';
    }
    if ($status === 'reserved') {
        return '<span class="chip chip-reserved">Reserved</span>';
    }
    if ($status === 'occupied') {
        return '<span class="chip chip-occupied">Occupied</span>';
    }
    return '';
}

function money($amount) {
    // This formats the number as money, e.g., 9,450.50
    return '$' . number_format($amount, 2);
}
?>


<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>RestaurantPOS • Dashboard</title>
  <link rel="stylesheet" href="09_style.css"/>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-badge">ϟ</div>
      <div><div style="font-weight:700">RestaurantPOS</div><div class="brand-sub">Professional Edition</div></div>
    </div>
    
    <nav class="nav">
      <a href="01_dashboard.php" class="active"><i data-lucide="layout-dashboard"></i> Dashboard</a>
      <a href="02_tables.php"><i data-lucide="table-2"></i> Tables</a>
      <!-- *** NEW LINK *** -->
      <a href="02_reservation.php"><i data-lucide="book-marked"></i> Reservations</a>
      <a href="03_orders.php"><i data-lucide="shopping-bag"></i> Orders</a>
      <a href="04_menu.php"><i data-lucide="utensils"></i> Menu</a>
      
      <?php
      // This is the Role-Based Access Control!
      // Only show this link if the user's role is 'manager'.
      if (isset($_SESSION['role']) && $_SESSION['role'] == 'manager') {
          echo '<a href="05_reports.php"><i data-lucide="receipt"></i> Reports</a>';
      }
      ?>
    </nav>
    
    <nav class="nav" style="margin-top:auto;border-top:1px solid #eee;">
      <a href="00_logout.php"><i data-lucide="log-out"></i> Logout</a>
    </nav>
  </aside>

  <main class="main">
    <div class="topbar">
      <div id="topDate" class="muted"><?php echo date('l, j F Y'); // PHP provides the date ?></div>
      
      <div style="display:flex;gap:.6rem;align-items:center">
        <span class="badge"><span id="badgeOpen"><?php echo $active_order_count; ?></span> Active Orders</span>
        <i data-lucide="bell"></i>
        
        <div id="userBox" class="muted" style="font-weight:600;">
            <?php echo htmlspecialchars($_SESSION['name'] ?? ''); ?> 
            (<?php echo htmlspecialchars($_SESSION['role'] ?? ''); ?>)
        </div>
        
        <a href="00_logout.php" class="btn sm">Logout</a>
      </div>
    </div>

    <div class="panel">
      <div class="container" style="display:grid;gap:1.25rem">
        <div>
          <h1 style="margin:.25rem 0;font-size:1.75rem;font-weight:800">Dashboard</h1>
          <p class="muted">Welcome back! Here's your restaurant overview</p>
        </div>

        <div class="grid g3">
          <div class="card"><div class="card-h">Today's Sales</div><div class="card-c"><div id="statSales" style="font-size:1.8rem;font-weight:800"><?php echo money($today_sales); ?></div><div class="muted" style="font-size:.85rem">Daily totals only</div></div></div>
          <div class="card"><div class="card-h">Total Orders</div><div class="card-c"><div id="statOrders" style="font-size:1.8rem;font-weight:800"><?php echo $today_orders; ?></div><div class="muted" style="font-size:.85rem">Completed today</div></div></div>
          <div class="card"><div class="card-h">Average Order</div><div class="card-c"><div id="statAvg" style="font-size:1.8rem;font-weight:800"><?php echo money($today_avg); ?></div><div class="muted" style="font-size:.85rem">Sales / Orders (today)</div></div></div>
        </div>

        <div class="grid g3" style="grid-template-columns:1fr 1fr">
          <div class="card">
            <div class="card-h" style="display:flex;gap:.5rem;align-items:center"><i data-lucide="users"></i> Active Tables</div>
            <div id="tablesList" class="card-c" style="display:grid;gap:.75rem">
                
                <?php foreach ($all_tables as $table): ?>
                    <div class="card" style="display:flex;align-items:center;justify-content:space-between;padding:.75rem">
                      <div>
                        <div style="display:flex;gap:.5rem;align-items:center">
                            <b>Table <?php echo $table['id']; ?></b>
                            <?php echo getTableChip($table['status']); ?>
                        </div>
                        <div class="muted" style="font-size:.9rem"><?php echo htmlspecialchars($table['customer'] ?? '') ?: ''; ?></div>
                        <div class="muted" style="font-size:.75rem">Server: <?php echo htmlspecialchars($table['server'] ?? '') ?: ''; ?></div>
                      </div>
                      <div style="text-align:right">
                        <div style="font-weight:700"><?php echo money($table['total'] ?? 0); ?></div>
                        
                        <a href="03_orders.php?table_id=<?php echo $table['id']; ?>" class="btn sm"><i data-lucide="eye"></i> View Order</a>
                      </div>
                    </div>
                <?php endforeach; ?>

            </div>
          </div>

          <div class="card">
            <div class="card-h" style="display:flex;gap:.5rem;align-items:center"><i data-lucide="chef-hat"></i> Kitchen Orders</div>
            <div id="ordersList" class="card-c" style="display:grid;gap:.75rem">

                <?php if (empty($kitchen_orders)): ?>
                    <div class="muted">No kitchen orders</div>
                <?php else: ?>
                    <?php foreach ($kitchen_orders as $order): ?>
                        <div class="card" style="padding:.75rem;display:flex;align-items:center;justify-content:space-between">
                          <div>
                            <div style="font-weight:600">Table <?php echo $order['table_id']; ?></div>
                            <div class="muted" style="font-size:.9rem"><?php echo htmlspecialchars($order['items_summary'] ?? ''); ?></div>
                          </div>
                          <div style="font-weight:700"><?php echo money($order['total'] ?? ''); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
  lucide.createIcons();
</script>

</body>
</html>
