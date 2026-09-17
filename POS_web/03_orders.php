<?php
// 03_orders.php

session_start();

// โชว์ error ตอน dev
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ถ้ายังไม่ login → เด้งกลับ
if (!isset($_SESSION['role'])) {
    header("Location: 00_login.php");
    exit;
}

require_once '00_db_connect.php'; // ใช้ $mysqli

// ===== 1) รับ table_id จาก query string =====
$table_id = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
if ($table_id === 0) {
    header("Location: 02_tables.php?error=No+table+selected");
    exit;
}

// ===== 2) หา basket ทั้งหมดของโต๊ะนี้ (ทั้ง incomplete และ complete) =====
$active_baskets = [];
$stmt = $mysqli->prepare("
    SELECT BASKET_ID, comment, COALESCE(total_price,0) AS total_price, status
    FROM ORDER_BASKET
    WHERE TABLE_ID = ?
      AND status IN ('incomplete','complete')
    ORDER BY BASKET_ID ASC
");
$stmt->bind_param("i", $table_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $active_baskets[] = $row;
}
$stmt->close();


// ===== 3) ดึงรายการอาหารทั้งหมดสำหรับ basket ที่ active =====
$all_order_items = []; // Grouped by BASKET_ID
$payment_subtotal = 0.0; // Total for 'complete' baskets only (for payment)
$display_subtotal = 0.0; // Total for ALL baskets (for display)

$has_incomplete_baskets = false;
$has_complete_baskets = false;

if (!empty($active_baskets)) {
    // Get all basket IDs
    $basket_ids = array_map(function($b) { return (int)$b['BASKET_ID']; }, $active_baskets);
    
    // Create placeholders for IN() clause: ?,?,?
    $placeholders = implode(',', array_fill(0, count($basket_ids), '?'));
    // Create types string for bind_param: "iii"
    $types = str_repeat('i', count($basket_ids));

    // Fetch all items for all active baskets
    $stmt = $mysqli->prepare("
        SELECT 
            oi.BASKET_ID,
            oi.MENU_ID,
            oi.amount,
            oi.status,
            m.name,
            m.price
        FROM ORDERED_ITEM oi
        JOIN MENU m ON oi.MENU_ID = m.MENU_ID
        WHERE oi.BASKET_ID IN ($placeholders)
        ORDER BY oi.BASKET_ID ASC, oi.MENU_ID
    ");
    
    $stmt->bind_param($types, ...$basket_ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        // Group items by their basket ID
        $all_order_items[(int)$row['BASKET_ID']][] = $row;
    }
    $stmt->close();
    
    // Calculate totals and check statuses
    foreach ($active_baskets as $basket) {
        $basket_total = (float)$basket['total_price'];
        $display_subtotal += $basket_total; // Show total for all baskets
        
        if ($basket['status'] === 'complete') {
            $payment_subtotal += $basket_total; // Only 'complete' baskets are payable
            $has_complete_baskets = true;
        } else {
            // Any basket that is not 'complete' is considered 'incomplete'
            $has_incomplete_baskets = true;
        }
    }
}

// Payment is allowed ONLY if there are complete baskets AND no incomplete ones.
// This matches the trg_invoice_gate database trigger logic.
$can_process_payment = $has_complete_baskets && !$has_incomplete_baskets;

// Calculate final payment amount (based on 'complete' baskets only)
$subtotal = $payment_subtotal*100/100.08;
$tax      = $payment_subtotal - $subtotal;
$total    = $subtotal + $tax;

function money($n) {
    return '฿' . number_format((float)$n, 2);
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>RestaurantPOS • Orders</title>
    <link rel="stylesheet" href="09_style.css"/>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        button:disabled{
            opacity:.4;
            cursor:not-allowed;
        }
        .table-orders{
            width:100%;
            border-collapse:collapse;
            font-size:.9rem;
        }
        .table-orders th,
        .table-orders td{
            padding:.6rem .8rem;
            border-bottom:1px solid #e5e7eb;
            text-align:left;
        }
        /* Remove bottom border for last row in table */
        .table-orders tr:last-child td {
            border-bottom: none;
        }
        .status-pill{
            border-radius:999px;
            padding:.2rem .7rem;
            font-size:.8rem;
            border:1px solid rgba(0,0,0,.08);
            background:#fef9c3; /* Yellowish for incomplete */
        }
        .status-pill.done{
            background:#dcfce7; /* Green for done */
            color:#166534;
        }
    </style>
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
            <a href="02_reservation.php"><i data-lucide="book-marked"></i> Reservations</a>
            <a href="03_orders.php" class="active"><i data-lucide="shopping-bag"></i> Orders</a>
            <a href="04_menu.php"><i data-lucide="utensils"></i> Menu</a>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'manager'): ?>
                <a href="05_reports.php"><i data-lucide="receipt"></i> Reports</a>
            <?php endif; ?>
        </nav>
        <nav class="nav" style="margin-top:auto;border-top:1px solid #eee;">
            <a href="00_logout.php"><i data-lucide="log-out"></i> Logout</a>
        </nav>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <div class="muted" style="font-size:.8rem">ORDERS</div>
                <div style="font-size:1.1rem;font-weight:600">Table <?php echo htmlspecialchars($table_id); ?></div>
                <div class="muted" style="font-size:.8rem">
                    <?php if (!empty($active_baskets)): ?>
                        Active Baskets: <?php echo htmlspecialchars(implode(', ', array_map(function($b) { return $b['BASKET_ID']; }, $active_baskets))); ?>
                    <?php else: ?>
                        No active basket for this table.
                    <?php endif; ?>
                </div>
            </div>
            <a href="02_tables.php" class="btn"><i data-lucide="arrow-left"></i> Back to tables</a>
        </div>

        <div class="panel">
            <div class="container" style="display:grid;gap:1.5rem;grid-template-columns:2fr 1fr;align-items:flex-start;">

                <div class="card">
                    <div class="card-h" style="display:flex;justify-content:space-between;align-items:center;">
                        <span>Order items</span>
                        <span class="muted" style="font-size:.8rem;">
                            Total (All Baskets): <?php echo money($display_subtotal); ?>
                        </span>
                    </div>
                    <div class="card-c" style="padding: 0.5rem;"> <?php if (empty($active_baskets)): ?>
                            <div class="muted" style="padding: 1rem;">This table has no active order (no incomplete or complete basket found).</div>
                        <?php else: ?>
                            <?php foreach ($active_baskets as $basket): 
                                $basket_id = (int)$basket['BASKET_ID'];
                                $items_in_this_basket = isset($all_order_items[$basket_id]) ? $all_order_items[$basket_id] : [];
                            ?>
                                <div style="margin: 0.5rem; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                                    <div style="background: #f9fafb; padding: .6rem 1rem; border-bottom: 1px solid #e5e7eb; display:flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <strong style="font-size: 1.1rem;">Basket #<?php echo $basket_id; ?></strong>
                                            <span class="status-pill <?php echo $basket['status'] === 'complete' ? 'done' : ''; ?>" style="margin-left: 8px;">
                                                <?php echo htmlspecialchars(ucfirst($basket['status'])); ?>
                                            </span>
                                        </div>
                                        <div style="font-weight: 600;">
                                            Basket Total: <?php echo money($basket['total_price']); ?>
                                        </div>
                                    </div>
                                    
                                    <?php if (empty($items_in_this_basket)): ?>
                                        <div class="muted" style="padding: 1rem;">
                                            Comment: <?php echo htmlspecialchars($basket['comment'] ?: '-'); ?><br><br>
                                            No items in this basket.
                                        </div>
                                    <?php else: ?>
                                        <div class="muted" style="padding: .6rem 1rem .2rem; font-size:.85rem;">
                                            Comment: <?php echo htmlspecialchars($basket['comment'] ?: '-'); ?>
                                        </div>
                                        <table class="table-orders">
                                            <thead>
                                            <tr>
                                                <th style="width:40px;padding-left:1rem;">#</th>
                                                <th>Menu</th>
                                                <th style="width:70px;">Qty</th>
                                                <th style="width:90px;">Price</th>
                                                <th style="width:100px;">Line total</th>
                                                <th style="width:130px;padding-right:1rem;">Status</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php
                                            $i = 1;
                                            foreach ($items_in_this_basket as $item):
                                                $line_total = $item['price'] * $item['amount'];
                                            ?>
                                                <tr>
                                                    <td style="padding-left:1rem;"><?php echo $i++; ?></td>
                                                    <td><?php echo htmlspecialchars($item['name']); ?></td>
                                                    <td><?php echo (int)$item['amount']; ?></td>
                                                    <td><?php echo money($item['price']); ?></td>
                                                    <td><?php echo money($line_total); ?></td>
                                                    <td style="padding-right:1rem;">
                                                        <?php if ($item['status'] === 'finished'): ?>
                                                            <span class="status-pill done">Completed</span>
                                                        <?php else: ?>
                                                            <form method="POST" action="03_order_item_status_update.php" style="margin:0">
                                                                <input type="hidden" name="table_id" value="<?php echo $table_id; ?>">
                                                                <input type="hidden" name="basket_id" value="<?php echo $basket_id; ?>">
                                                                <input type="hidden" name="menu_id" value="<?php echo (int)$item['MENU_ID']; ?>">
                                                                <button type="submit" class="status-pill">
                                                                    Mark complete
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-h">Process payment</div>
                    <div class="card-c" style="display:grid;gap:.9rem;font-size:.9rem;">
                        <div style="font-size:.8rem;color:#b91c1c;">
                            ⚠ Payment is enabled only when there are 'Complete' baskets
                            <span style="font-weight:600;">and NO 'Incomplete' baskets</span>.
                        </div>

                        <div>
                            <div class="muted" style="font-size:.8rem;margin-bottom:.25rem;">Payment Summary (Complete Baskets Only)</div>
                            <div style="display:flex;justify-content:space-between;">
                                <span>Order total (before tax)</span>
                                <span><?php echo money($subtotal); ?></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;">
                                <span>Tax (8%)</span>
                                <span><?php echo money($tax); ?></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;margin-top:.4rem;font-weight:700;font-size:1.1rem;">
                                <span>To pay</span>
                                <span><?php echo money($total); ?></span>
                            </div>
                        </div>

                        <form method="POST" action="03_orders_process_payment.php" style="display:grid;gap:.75rem;">
                            <input type="hidden" name="table_id" value="<?php echo $table_id; ?>">
                            <input type="hidden" name="basket_id" value="<?php echo !empty($active_baskets) ? $active_baskets[0]['BASKET_ID'] : ''; ?>">
                            <input type="hidden" name="amount" value="<?php echo $total; ?>">

                            <div>
                                <label class="muted" style="font-size:.8rem;display:block;margin-bottom:.25rem;">
                                    Payment method
                                </label>
                                <select name="payment_method">
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="debit">Debit</option>
                                    <option value="credit">Credit</option>
                                </select>
                            </div>

                            <button type="submit"
                                    class="btn"
                                    style="width:100%;justify-content:center;background:#0f172a;color:#fff;font-weight:600;padding:.8rem 1rem;"
                                    <?php echo ($can_process_payment ? '' : 'disabled'); ?>>
                                <i data-lucide="badge-dollar-sign"></i>
                                Process payment
                            </button>
                        </form>

                        <div>
                            <a href="02_tables.php" class="muted" style="font-size:.8rem;">
                                ← Back to tables
                            </a>
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