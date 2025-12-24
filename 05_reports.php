<?php
/* File: 05_reports.php */

session_start();

// AUTH GUARD
if (!isset($_SESSION['role'])) {
    header("Location: 00_login.php");
    exit;
}

// MANAGER ONLY
if ($_SESSION['role'] !== 'manager') {
    header("Location: 01_dashboard.php?error=AccessDenied");
    exit;
}

// DB CONNECT
require_once '00_db_connect.php'; // defines $mysqli
$conn = $mysqli;

// Helper: money format (USD)
function money($amount) {
    return '$' . number_format((float)$amount, 2);
}

// Get date range from query string
$range = $_GET['range'] ?? 'today';

// Build WHERE clause on RECEIPT.created_at (alias r)
$where_clause = "";
if ($range === 'today') {
    $where_clause = "WHERE DATE(r.created_at) = CURDATE()";
} elseif ($range === 'yesterday') {
    $where_clause = "WHERE DATE(r.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
} elseif ($range === '7d') {
    $where_clause = "WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($range === '30d') {
    $where_clause = "WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

// Default values
$stat_sales   = 0;
$stat_orders  = 0;
$stat_cust    = 0;
$stat_tables  = 0;
$hourly_sales = [];
$top_items    = [];

// ----- Summary stats (use SALES) -----
// Total sales = sum of SALES.item_total
// Orders = number of receipts in range
// Tables = distinct TABLE_ID in receipts
$sql_stats = "
    SELECT 
        IFNULL(SUM(s.item_total),0) AS sales,
        COUNT(DISTINCT r.RECEIPT_ID) AS orders,
        COUNT(DISTINCT r.TABLE_ID)   AS tables
    FROM RECEIPT r
    LEFT JOIN SALES s ON s.RECEIPT_ID = r.RECEIPT_ID
    $where_clause
";
if ($res = $conn->query($sql_stats)) {
    if ($row = $res->fetch_assoc()) {
        $stat_orders = (int)$row['orders'];
        $stat_tables = (int)$row['tables'];
        $stat_cust   = $stat_orders; // rough: 1 receipt = 1 party
    }
    $res->free();
}

$sql_stats = "
    SELECT SUM(paid_amount) as sales 
            FROM RECEIPT r
    $where_clause
";
if ($res = $conn->query($sql_stats)) {
    if ($row = $res->fetch_assoc()) {
        $stat_sales = (float)$row['sales']; 
    }
    $res->free();
}

echo "<script>console.log('Stats - Sales: ' + " . json_encode($stat_sales) . " + ', Orders: ' + " . json_encode($stat_orders) . " + ', Tables: ' + " . json_encode($stat_tables) . ");</script>";

// ----- Hourly sales (from SALES) -----
$sql_hour = "
    SELECT 
        HOUR(r.created_at) AS hour,
        SUM(s.item_total)  AS total
    FROM RECEIPT r
    JOIN SALES s ON s.RECEIPT_ID = r.RECEIPT_ID
    $where_clause
    GROUP BY HOUR(r.created_at)
    ORDER BY hour
";
if ($res = $conn->query($sql_hour)) {
    while ($row = $res->fetch_assoc()) {
        $hourly_sales[] = [
            'hour'  => (int)$row['hour'],
            'total' => (float)$row['total'],
        ];
    }
    $res->free();
}

// ----- Top items (from SALES) -----
// Aggregate by MENU using SALES
$sql_top = "
    SELECT 
        m.name                   AS name,
        m.type                   AS type,
        SUM(s.amount)            AS qty,
        SUM(s.item_total)        AS revenue
    FROM RECEIPT r
    JOIN SALES s ON s.RECEIPT_ID = r.RECEIPT_ID
    JOIN MENU m  ON m.MENU_ID    = s.MENU_ID
    $where_clause
    GROUP BY m.MENU_ID, m.name, m.type
    ORDER BY revenue DESC
    LIMIT 8
";
if ($res = $conn->query($sql_top)) {
    while ($row = $res->fetch_assoc()) {
        $catLabel = 'Sides & Appetizers';
        $t = strtolower($row['type']);
        if ($t === 'beverages') {
            $catLabel = 'Beverages';
        } elseif ($t === 'main courses') {
            $catLabel = 'Main Courses';
        }

        $top_items[] = [
            'name'    => $row['name'],
            'cat'     => $catLabel,
            'qty'     => (int)$row['qty'],
            'revenue' => (float)$row['revenue'],
        ];
    }
    $res->free();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>RestaurantPOS • Reports</title>
  <link rel="stylesheet" href="09_style.css"/>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-badge">ϟ</div>
      <div>
        <div style="font-weight:700">RestaurantPOS</div>
        <div class="brand-sub">Professional Edition</div>
      </div>
    </div>
    
    <nav class="nav">
      <a href="01_dashboard.php"><i data-lucide="layout-dashboard"></i> Dashboard</a>
      <a href="02_tables.php"><i data-lucide="table-2"></i> Tables</a>
      <!-- *** NEW LINK *** -->
      <a href="02_reservation.php"><i data-lucide="book-marked"></i> Reservations</a>
      <a href="03_orders.php"><i data-lucide="shopping-bag"></i> Orders</a>
      <a href="04_menu.php"><i data-lucide="utensils"></i> Menu</a>
      <?php if ($_SESSION['role'] === 'manager'): ?>
        <a href="05_reports.php" class="active"><i data-lucide="receipt"></i> Reports</a>
      <?php endif; ?>
    </nav>
    
    <nav class="nav" style="margin-top:auto;border-top:1px solid #eee;">
      <a href="00_logout.php"><i data-lucide="log-out"></i> Logout (<?php echo htmlspecialchars($_SESSION['name']); ?>)</a>
    </nav>
  </aside>

  <main class="main">
    <div class="topbar">
      <div id="topDate" class="muted"><?php echo date('l, j F Y'); ?></div>
      
      <form method="GET" action="05_reports.php" style="display:flex;gap:.6rem;align-items:center">
        <select name="range" class="input" style="width:auto" onchange="this.form.submit()">
            <option value="today"     <?php if ($range === 'today') echo 'selected'; ?>>Today</option>
            <option value="yesterday" <?php if ($range === 'yesterday') echo 'selected'; ?>>Yesterday</option>
            <option value="7d"        <?php if ($range === '7d') echo 'selected'; ?>>Last 7 days</option>
            <option value="30d"       <?php if ($range === '30d') echo 'selected'; ?>>Last 30 days</option>
        </select>
        
        <button id="export" type="button" class="btn"><i data-lucide="download"></i> Export</button>
      </form>
    </div>

    <div class="panel">
      <div class="container" style="display:grid;gap:1.25rem">
        <div>
          <h1 style="margin:.25rem 0;font-size:1.75rem;font-weight:800">Sales Reports</h1>
          <p class="muted">Analyze performance and trends</p>
        </div>

        <!-- Stat cards -->
        <div class="grid g4">
          <div class="card stat">
            <div>Total Sales</div>
            <div id="statSales" style="font-weight:800;font-size:1.6rem">
              <?php echo money($stat_sales); ?>
            </div>
          </div>
          <div class="card stat">
            <div>Total Orders</div>
            <div id="statOrders" style="font-weight:800;font-size:1.6rem">
              <?php echo (int)$stat_orders; ?>
            </div>
          </div>
          <div class="card stat">
            <div>Customers Served</div>
            <div id="statCust" style="font-weight:800;font-size:1.6rem">
              <?php echo (int)$stat_cust; ?>
            </div>
          </div>
          <div class="card stat">
            <div>Total Tables</div>
            <div id="statTables" style="font-weight:800;font-size:1.6rem">
              <?php echo (int)$stat_tables; ?>
            </div>
          </div>
        </div>

        <div class="grid" style="grid-template-columns:1fr 1fr">
          <div class="card">
            <div class="card-h">Hourly Sales</div>
            <div id="hourly" class="card-c" style="display:grid;gap:.5rem">
                <?php if (empty($hourly_sales)): ?>
                    <div class="muted">No data</div>
                <?php else: ?>
                    <?php foreach ($hourly_sales as $h): ?>
                        <?php
                            $width_percent = ($stat_sales > 0)
                                ? round(($h['total'] / $stat_sales) * 100)
                                : 0;
                            if ($width_percent > 100) $width_percent = 100;
                        ?>
                        <div style="display:flex;gap:.6rem;align-items:center">
                          <div style="width:3.5rem;font-size:.9rem">
                            <?php echo str_pad($h['hour'], 2, '0', STR_PAD_LEFT); ?>:00
                          </div>
                          <div style="flex:1;height:12px;background:#e5e7eb;border-radius:8px">
                            <div style="width:<?php echo $width_percent; ?>%;height:12px;border-radius:8px;background:#c7d2fe"></div>
                          </div>
                          <div style="width:100px;text-align:right;font-size:.9rem">
                            <?php echo money($h['total']); ?>
                          </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
          </div>

          <div class="card">
            <div class="card-h">Top Selling Items</div>
            <div id="topItems" class="card-c" style="display:grid;gap:.5rem">
                <?php if (empty($top_items)): ?>
                    <div class="muted">No data</div>
                <?php else: ?>
                    <?php foreach ($top_items as $index => $item): ?>
                        <div style="display:flex;align-items:center;justify-content:space-between">
                          <div style="display:flex;gap:.6rem;align-items:center">
                            <div style="width:24px;height:24px;border-radius:999px;background:#f3f4f6;display:grid;place-items:center;font-size:.8rem;font-weight:700">
                              <?php echo $index + 1; ?>
                            </div>
                            <div>
                              <div style="font-weight:700">
                                <?php echo htmlspecialchars($item['name']); ?>
                              </div>
                              <div class="muted" style="font-size:.8rem">
                                <?php echo htmlspecialchars($item['cat']); ?> • <?php echo (int)$item['qty']; ?> sold
                              </div>
                            </div>
                          </div>
                          <div style="font-weight:800">
                            <?php echo money($item['revenue']); ?>
                          </div>
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
  // Export current stats as JSON (currency is USD)
  document.getElementById('export').addEventListener('click', function () {
    const data = {
      range: "<?php echo htmlspecialchars($range); ?>",
      generatedAt: new Date().toISOString(),
      stats: {
        sales: <?php echo json_encode($stat_sales); ?>,
        orders: <?php echo json_encode($stat_orders); ?>,
        customers: <?php echo json_encode($stat_cust); ?>,
        tables: <?php echo json_encode($stat_tables); ?>
      },
      hourly: <?php echo json_encode($hourly_sales); ?>,
      topItems: <?php echo json_encode($top_items); ?>
    };

    const blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'});
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url;
    a.download = 'reports-' + "<?php echo htmlspecialchars($range); ?>" + '.json';
    a.click();
    URL.revokeObjectURL(url);
  });

  lucide.createIcons();
</script>

</body>
</html>
