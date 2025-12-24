<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '00_db_connect.php';

//  THE AUTHENTICATION GUARD
if (!isset($_SESSION['user_id'])) {
    header("Location: 00_login.php");
    exit;
}

// ____________________Update table every time this page is load___________
if (!$mysqli->query("CALL sp_update_table_status()")) {
    die("Error running status update: " . $mysqli->error);
}

// -----------------------------------------------------
// HELPER FUNCTIONS (for PHP actions)
// -----------------------------------------------------

function seat_table($mysqli, $table_id, $party_size, $employee_id) {
    

    // update table status
    $stmt = $mysqli->prepare("UPDATE TABLE_INFO SET table_status = 'occupied' WHERE TABLE_ID = ? ");
    $stmt->bind_param("i", $table_id);
    $stmt->execute();
    $stmt->close();

}


// -----------------------------------------------------
// PART 1: HANDLE POST ACTIONS (Walk-in, Check-in, Complete)
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    $action = $_POST['action'] ?? '';
    $table_id = (int)($_POST['table_id'] ?? 0);
    $employee_id = $_SESSION['user_id'] ?? 0;

    if ($table_id === 0 || $employee_id === 0) {
        $_SESSION['error_message'] = "Invalid Table ID or Employee ID.";
        header("Location: 02_tables.php");
        exit;
    }

    // Clear old messages
    unset($_SESSION['error_message']);
    unset($_SESSION['success_message']);

    try {
        switch ($action) {
            //-----------------------------------
            // ACTION: Seat a Walk-in Customer
            //-----------------------------------
            case 'walkin':
                // 1. Get data (Only party size)
                $party_size = (int)($_POST['party_size'] ?? 1);
                $customer_id = null; // Walk-ins are not added to the CUSTOMER table

                // 2. Seat the table (creates basket, updates status via trigger)
                seat_table($mysqli, $table_id,  $party_size, $employee_id);
                
                $_SESSION['success_message'] = "Table $table_id has been seated.";
                break;

            //-----------------------------------
            // ACTION: Check In a Reservation
            //-----------------------------------
            case 'checkin':
                // 1. Get reservation details
                // *** THIS QUERY REQUIRES 'reservation_id' IN YOUR VIEW ***
                // *** PLEASE RUN 00_fix_views.sql AGAIN TO FIX THIS ***
                $stmt = $mysqli->prepare("SELECT party FROM v_alltable_need_detail WHERE id = ? AND status = 'reserved'");
                $stmt->bind_param("i", $table_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception("Could not find reservation to check in.");
                }
                
                $res = $result->fetch_assoc();
                $stmt->close();

                $stmt = $mysqli->prepare("SELECT RESERVATION_ID as reservation_id, CUSTOMER_ID as customer_id FROM RESERVATION");
                $stmt->execute();
                $customer_id = $res['customer_id'];
                $party_size = $res['party'];
                $reservation_id_to_delete = $res['reservation_id'];
                $result = $stmt->get_result();
                $stmt->close();
                
                // 2. Seat the table (creates basket)
                seat_table($mysqli, $table_id, $party_size, $employee_id);

                // 3. Delete the *specific* reservation record that was just checked in
                $stmt = $mysqli->prepare("DELETE FROM RESERVATION WHERE RESERVATION_ID = ?");
                $stmt->bind_param("i", $reservation_id_to_delete);
                $stmt->execute();
                $stmt->close();

                $_SESSION['success_message'] = "Table $table_id checked in and seated.";
                break;

            //-----------------------------------
            // ACTION: Complete an Order
            //-----------------------------------
            case 'complete_order':
              // trigger 19 will deal with the basket
              $stmt_rec = $mysqli->prepare("INSERT INTO RECEIPT (TABLE_ID, paid_amount, payment_method) VALUES (?, ?, 'Cash')");
              $stmt_rec->bind_param("id", $table_id, $total_price);
              $stmt_rec->execute();
              $stmt_rec->close();
              break;
        }

    } catch (Exception $e) {
        // This will catch the "Unknown column 'reservation_id'" error
        // if the view is not fixed.
        $_SESSION['error_message'] = $e->getMessage();
    }

    // -----------------------------------------------------
    // REDIRECT BACK (to this same page)
    // -----------------------------------------------------
    header("Location: 02_tables.php?table_id=" . $table_id);
    exit;
}
// END OF POST ACTION HANDLING
// -----------------------------------------------------


// -----------------------------------------------------
// PART 2: LOAD DATA FOR PAGE (GET Request)
// -----------------------------------------------------

// Check for session messages
$error_msg = $_SESSION['error_message'] ?? null;
unset($_SESSION['error_message']); // Clear it after reading

$success_msg = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']); // Clear it after reading

// Get Active Order Count
$sql_active_count = "SELECT COUNT(BASKET_ID) as active_count 
                     FROM ORDER_BASKET 
                     WHERE status = 'incomplete'";
$result = $mysqli->query($sql_active_count);
if (!$result) die("SQL Error (Active Orders): " . $mysqli->error);
$active_order_count = $result->fetch_assoc()['active_count'] ?? 0;

// Get All Table Data
$all_tables = [];
$sql_all_tables = "SELECT * FROM v_alltable_need_detail";

$result = $mysqli->query($sql_all_tables);
if (!$result) die("SQL Error (All Tables): " . $mysqli->error . "<br><br><strong>Tip:</strong> Make sure `v_alltable_need_detail` view exists and user has permission. If you see `Unknown column 'reservation_id'`, please run `00_fix_views.sql`.");

while($row = $result->fetch_assoc()) {
    $all_tables[] = $row;
}

// Helper functions for formatting
function money($amount) {
    return '$' . number_format($amount, 2);
}

function badge($status) {
    if ($status === 'available') return '<span class="chip chip-available">Available</span>';
    if ($status === 'reserved') return '<span class="chip chip-reserved">Reserved</span>';
    return '<span class="chip chip-occupied">Occupied</span>';
}

function fmtDT($isoLike) {
    if (empty($isoLike)) return '—';
    try {
        $date = new DateTime($isoLike);
        return $date->format('j M Y, H:i'); // e.g., 10 Nov 2025, 20:00
    } catch (Exception $e) {
        return $isoLike;
    }
}

// Calculate stats for the top bar
$stat_total = count($all_tables);
$stat_avail = 0;
$stat_occ = 0;
$stat_res = 0;
foreach ($all_tables as $t) {
    if ($t['status'] == 'available') $stat_avail++;
    elseif ($t['status'] == 'occupied') $stat_occ++;
    elseif ($t['status'] == 'reserved') $stat_res++;
}

// Get selected table from URL (?table_id=...)
$selected_id = 0;
if (isset($_GET['table_id'])) {
    $selected_id = (int)$_GET['table_id'];
} else if ($stat_total > 0) {
    $selected_id = $all_tables[0]['id']; // Default to first table
}

$selected_table = null;
if ($selected_id > 0) {
    foreach ($all_tables as $t) {
        if ($t['id'] == $selected_id) {
            $selected_table = $t;
            break;
        }
    }
}
if ($selected_table === null && $stat_total > 0) {
    $selected_table = $all_tables[0];
    $selected_id = $selected_table['id'];
}
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>RestaurantPOS • Tables</title>
  <link rel="stylesheet" href="09_style.css"/>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div class="brand-badge">ϟ</div><div><div style="font-weight:700">RestaurantPOS</div><div class="brand-sub">Professional Edition</div></div></div>

    <nav class="nav">
      <a href="01_dashboard.php"><i data-lucide="layout-dashboard"></i> Dashboard</a>
      <a href="02_tables.php" class="active"><i data-lucide="table-2"></i> Tables</a>
      <a href="02_reservation.php"><i data-lucide="book-marked"></i> Reservations</a>
      <a href="03_orders.php"><i data-lucide="shopping-bag"></i> Orders</a>
      <a href="04_menu.php"><i data-lucide="utensils"></i> Menu</a>
      
      <?php
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
      <div id="topDate" class="muted"><?php echo date('l, j F Y'); ?></div>
      <div style="display:flex;gap:.6rem;align-items:center">
        <span class="badge"><span id="badgeOpen"><?php echo $active_order_count; ?></span> Active Orders</span>
        <i data-lucide="bell"></i>
      </div>
    </div>

    <div classs="panel">
      <div class="container" style="display:grid;gap:1.25rem">
        <div><h1 style="margin:.25rem 0;font-size:1.75rem;font-weight:800">Tables</h1>
          <p class="muted">Manage seating, reservations, and live orders</p></div>

        <!-- Display Session Messages -->
        <?php if ($error_msg): ?>
            <div class="card" style="background-color: #ffeaea; border-color: #f5c6cb; color: #721c24; padding: 1rem;">
                <strong>Error:</strong> <?php echo htmlspecialchars($error_msg ?? ''); ?>
            </div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="card" style="background-color: #d4edda; border-color: #c3e6cb; color: #155724; padding: 1rem;">
                <strong>Success:</strong> <?php echo htmlspecialchars($success_msg ?? ''); ?>
            </div>
        <?php endif; ?>

        <div class="grid g4">
          <div class="card stat"><div>Total Tables</div><div id="statTotal" style="font-weight:800;font-size:1.6rem"><?php echo $stat_total; ?></div></div>
          <div class="card stat"><div>Available</div><div id="statAvail" style="font-weight:800;font-size:1.6rem"><?php echo $stat_avail; ?></div></div>
          <div class="card stat"><div>Occupied</div><div id="statOcc" style="font-weight:800;font-size:1.6rem"><?php echo $stat_occ; ?></div></div>
          <div class="card stat"><div>Reserved</div><div id="statRes" style="font-weight:8S00;font-size:1.6rem"><?php echo $stat_res; ?></div></div>
        </div>

        <div class="grid" style="grid-template-columns:2fr 1fr">
          <div class="card">
            <div class="card-h">Floor</div>
            <div class="card-c"><div id="floor" class="grid g3">
                <?php if (empty($all_tables)): ?>
                    <div class="muted">No tables found in the database.</div>
                <?php else: ?>
                    <?php foreach ($all_tables as $t):
                        $is_selected = ($t['id'] == $selected_id) ? 'selected' : '';
                    ?>
                        <a href="02_tables.php?table_id=<?php echo $t['id']; ?>" class="seat <?php echo $t['status']; ?> <?php echo $is_selected; ?>">
                          <div style="display:flex;justify-content:space-between"><?php echo badge($t['status']); ?></div>
                          <div style="margin-top:.5rem;font-weight:700">Table <?php echo $t['id']; ?></div>
                          <div class="muted" style="font-size:.9rem"><?php echo $t['seats']; ?> seats</div>
                          <div class="muted" style="font-size:.9rem"><?php echo htmlspecialchars($t['customer'] ?? ''); ?></div>
                          <div style="margin-top:.35rem;font-weight:700;<?php echo $t['total'] ? '' : 'opacity:0'; ?>"><?php echo money($t['total'] ?? 0); ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div></div>
          </div>
          <div class="card">
            <div class="card-h"><span id="detailTitle">Table <?php echo $selected_id; ?> Details</span></div>
            <div id="detailBody" class="card-c" style="display:grid;gap:.75rem">
                
                <?php if (!$selected_table): ?>
                    <div class="muted">Please add tables to the database.</div>
                
                <?php elseif ($selected_table['status'] == 'available'): ?>
                    
                    <div class="chip chip-available">Available</div>
                    <div class="muted">Capacity: <?php echo $selected_table['seats']; ?> seats</div>
                    
                    <form id="formSeat" action="02_tables.php" method="POST" style="display:grid;gap:.75rem">
                        <input type="hidden" name="table_id" value="<?php echo $selected_id; ?>">
                        
                        <div>
                            <label class="muted" style="font-size:.9rem">Party Size</label>
                            <select name="party_size" class="input">
                                <?php for ($i = 1; $i <= $selected_table['seats']; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php if ($i == 2) echo 'selected'; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr;gap:.5rem">
                          <button id="btnWalkIn" type="submit" name="action" value="walkin" class="btn" style="width:100%;background:#dcfce7">Seat Walk-in</button>
                        </div>
                    </form>

                <?php elseif ($selected_table['status'] == 'reserved'): ?>
                
                    <div class="chip chip-reserved">Reserved</div>
                    <div class="muted">Capacity: <?php echo $selected_table['seats']; ?> seats</div>
                    <div classs="card" style="padding:.75rem">
                      <div style="font-weight:700"><?php echo htmlspecialchars($selected_table['customer'] ?? ''); ?></div>
                      <div class="muted" style="font-size:.9rem">Party of <?php echo htmlspecialchars($selected_table['party'] ?? ''); ?></div>
                      <div class="muted" style="font-size:.9rem">Time: <?php echo fmtDT($selected_table['reservationAt']); ?></div>
                      <div class="muted" style="font-size:.9rem">Phone: <?php echo htmlspecialchars($selected_table['customer_phone'] ?? ''); ?></div>
                    </div>
                    
                    <form action="02_tables.php" method="POST" style="display:grid;gap:.5rem">
                        <input type="hidden" name="table_id" value="<?php echo $selected_id; ?>">
                        <button type="submit" name="action" value="checkin" class="btn" style="background:#eaf2ff; width:100%;">Check In Customer</button>
                    </form>

                <?php else: // 'occupied' ?>
                
                    <div class="chip chip-occupied">Occupied</div>
                    <div class="muted">Capacity: <?php echo $selected_table['seats']; ?> seats</div>
                    <div class="muted">Server: <?php echo htmlspecialchars($selected_table['server'] ?? ''); ?></div>
                    <div class="card" style="padding:.75rem">
                      <div style="margin-top:.25rem;font-weight:800"><?php echo money($selected_table['total'] ?? 0); ?></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr;gap:.5rem">
  <a href="03_orders.php?table_id=<?php echo $selected_id; ?>" class="btn">
      <i data-lucide="eye"></i> View Order
  </a>
</div>
                
                <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<form id="formComplete" action="02_tables.php" method="POST" style="display:none;">
    <input type="hidden" name="table_id" value="<?php echo $selected_id; ?>">
    <input type="hidden" name="action" value="complete_order">
</form>

<div id="alertModal" class="modal"><div class="card" style="max-width:420px;width:100%">
  <div class="card-h">Notice</div>
  <div class="card-c">
    <p id="alertText">Please fill all required fields.</p>
  </div>
  <div style="display:flex;gap:.5rem;justify-content:flex-end;padding:1rem;border-top:1px solid #eee">
    <button id="alertOk" class="btn" style="background:#eaf2ff">OK</button>
  </div>
</div></div>

<div id="confirmModal" class="modal"><div class="card" style="max-width:420px;width:100%">
  <div class="card-h">Confirm</div>
  <div class="card-c">
    <p id="confirmText">Confirm?</p>
    <p class="muted" style="font-size:.85rem" id="confirmSubtext">This action cannot be undone.</p>
  </div>
  <div style="display:flex;gap:.5rem;justify-content:flex-end;padding:1rem;border-top:1px solid #eee">
    <button id="confirmCancel" class="btn">Cancel</button>
    <button id="confirmOk" class="btn" style="background:#eaf2ff">OK</button>
  </div>
</div></div>

<script>
  // Get element helper
  const $ = id => document.getElementById(id);

  const alertModal = $('alertModal');
  const alertText = $('alertText');
  const alertOk = $('alertOk');
  
  function showAlert(message) {
      alertText.textContent = message;
      alertModal.classList.add('show');
  }
  alertOk.onclick = () => alertModal.classList.remove('show');

  // --- Confirmation Modal (for Complete) ---
  let formToSubmit = null;
  const btnComplete = $('actComplete');
  
  if (btnComplete) {
      btnComplete.onclick = () => {
          $('confirmText').textContent = 'Confirm Payment & Complete Order?';
          $('confirmSubtext').textContent = 'This will process payment and free the table.';
          formToSubmit = 'formComplete';
          confirmModal.classList.add('show');
      };
  }
  
  if($('confirmCancel')) $('confirmCancel').onclick = () => {
      confirmModal.classList.remove('show');
      formToSubmit = null;
  };
  
  if($('confirmOk')) $('confirmOk').onclick = () => {
      if (formToSubmit) {
          $(formToSubmit).submit();
      }
  };

  lucide.createIcons();
</script>
</body>
</html>