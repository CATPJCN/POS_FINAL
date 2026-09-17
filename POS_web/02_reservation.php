<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '00_db_connect.php';

//  THE AUTHENTICATION GUARD
if (!isset($_SESSION['user_id'])) {
    die("Error: You must be logged in.");
}

// -----------------------------------------------------
// HELPER FUNCTION : Find or Create Customer
// -----------------------------------------------------
function find_or_create_customer(mysqli $mysqli, string $first_name, string $last_name, string $phone): int
{
    $first_name = trim($first_name);
    $last_name  = trim($last_name);
    $phone      = trim($phone);

    if ($first_name === '' && $last_name === '') {
        throw new Exception("Customer name is required.");
    }

    // 1) หาใน view ถอดรหัส
    $sql = "SELECT CUSTOMER_ID 
            FROM v_decrypted_customers 
            WHERE First_Name = ? AND
            Last_Name = ? AND
            phone      = ?
            LIMIT 1";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("sss", $first_name, $last_name, $phone);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res && isset($res['CUSTOMER_ID'])) {
        return (int)$res['CUSTOMER_ID'];
    }

    // 1. SQL now calls the procedure and sets a session variable
    $sql_insert = "CALL sp_insert_customers(?, ?, NULL, ?, NULL, @new_customer_id)";
    $stmt = $mysqli->prepare($sql_insert);

    // 2. The bind_param stays the same (it only binds IN parameters)
    $stmt->bind_param("sss", $first_name, $last_name, $phone);
    $stmt->execute();
    $stmt->close(); // Close the first statement

    // 3. Run a SECOND query to get the value from the OUT parameter
    $res = $mysqli->query("SELECT @new_customer_id AS new_id");
    $row = $res->fetch_assoc();
    $new_id = $row['new_id'];
    return (int)$new_id;

}

// -----------------------------------------------------
// PART 1: HANDLE POST ACTIONS (Create, Edit, Cancel)
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $action   = $_POST['action'] ?? '';
    $table_id = (int)($_POST['table_id'] ?? 0);

    try {
        switch ($action) {

            // CREATE RESERVATION
            case 'create_reservation':
                $first_name          = $_POST['first_name'] ?? '';
                $last_name           = $_POST['last_name'] ?? '';
                $phone               = $_POST['phone'] ?? '';
                $party_size          = (int)($_POST['party_size'] ?? 1);
                $reservation_time_raw = $_POST['reservation_time'] ?? '';

                if ($table_id === 0 || empty($reservation_time_raw)) {
                    throw new Exception("Table and Reservation Time are required.");
                }
                if (trim($first_name) === '' && trim($last_name) === '') {
                    throw new Exception("Customer name is required.");
                }

                // แปลงจาก 2025-11-14T20:00 -> 2025-11-14 20:00:00
                $dt = new DateTime($reservation_time_raw);
                $reservation_time = $dt->format('Y-m-d H:i:s');

                // ตรวจเวลาชน (2 ชั่วโมง)
                $new_start = $reservation_time;
                $new_end   = (new DateTime($reservation_time))
                                ->add(new DateInterval('PT2H'))
                                ->format('Y-m-d H:i:s');

                $stmt = $mysqli->prepare(
                    "SELECT COUNT(*) as overlap_count
                     FROM RESERVATION
                     WHERE TABLE_ID = ?
                       AND ? < DATE_ADD(reservation_time, INTERVAL 2 HOUR)
                       AND ? > reservation_time"
                );
                $stmt->bind_param("iss", $table_id, $new_start, $new_end);
                $stmt->execute();
                $overlap_count = $stmt->get_result()->fetch_assoc()['overlap_count'];
                $stmt->close();

                if ($overlap_count > 0) {
                    throw new Exception("This table is already reserved within 2 hours of that time. Please choose a different time.");
                }

                // หา/สร้าง CUSTOMER
                $customer_id = find_or_create_customer($mysqli, $first_name, $last_name, $phone);

                // INSERT RESERVATION
                $stmt = $mysqli->prepare(
                    "INSERT INTO RESERVATION (TABLE_ID, CUSTOMER_ID, number_of_customer, reservation_time) 
                     VALUES (?, ?, ?, ?)"
                );
                $stmt->bind_param("iiis", $table_id, $customer_id, $party_size, $reservation_time);
                $stmt->execute();
                $stmt->close();

                $_SESSION['success_message'] = "Reservation created for Table $table_id.";
                break;

            // EDIT RESERVATION
            case 'edit_reservation':
                $reservation_id        = (int)($_POST['reservation_id'] ?? 0);
                $party_size            = (int)($_POST['party_size'] ?? 1);
                $reservation_time_raw  = $_POST['reservation_time'] ?? '';

                if ($reservation_id === 0) {
                    throw new Exception("Invalid Reservation ID. Cannot edit.");
                }
                if (empty($reservation_time_raw)) {
                    throw new Exception("Reservation time is required.");
                }

                // แปลงรูปแบบวันที่เวลา
                $dt = new DateTime($reservation_time_raw);
                $reservation_time = $dt->format('Y-m-d H:i:s');

                $new_start = $reservation_time;
                $new_end   = (new DateTime($reservation_time))
                                ->add(new DateInterval('PT2H'))
                                ->format('Y-m-d H:i:s');

                $stmt = $mysqli->prepare(
                    "SELECT COUNT(*) as overlap_count
                     FROM RESERVATION
                     WHERE TABLE_ID = ?
                       AND RESERVATION_ID != ?
                       AND ? < DATE_ADD(reservation_time, INTERVAL 2 HOUR)
                       AND ? > reservation_time"
                );
                $stmt->bind_param("iiss", $table_id, $reservation_id, $new_start, $new_end);
                $stmt->execute();
                $overlap_count = $stmt->get_result()->fetch_assoc()['overlap_count'];
                $stmt->close();

                if ($overlap_count > 0) {
                    throw new Exception("This table is already reserved within 2 hours of that time. Please choose a different time.");
                }

                $stmt = $mysqli->prepare(
                    "UPDATE RESERVATION
                     SET TABLE_ID = ?, number_of_customer = ?, reservation_time = ?
                     WHERE RESERVATION_ID = ?"
                );
                $stmt->bind_param("iisi", $table_id, $party_size, $reservation_time, $reservation_id);
                $stmt->execute();
                $stmt->close();

                $_SESSION['success_message'] = "Reservation updated successfully.";
                break;

            // CANCEL RESERVATION
            case 'cancel_reservation':
                $reservation_id = (int)($_POST['reservation_id'] ?? 0);
                if ($reservation_id === 0) {
                    throw new Exception("Invalid Reservation ID. Cannot cancel.");
                }

                $stmt = $mysqli->prepare("DELETE FROM RESERVATION WHERE RESERVATION_ID = ?");
                $stmt->bind_param("i", $reservation_id);
                $stmt->execute();
                $stmt->close();

                $_SESSION['success_message'] = "Reservation has been cancelled.";
                break;

            default:
                throw new Exception("Invalid action specified.");
        }

    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }

    header("Location: 02_reservation.php");
    exit;
}

// -----------------------------------------------------
// PART 2: LOAD DATA FOR PAGE (GET Request)
// -----------------------------------------------------

$error_msg = $_SESSION['error_message'] ?? null;
unset($_SESSION['error_message']);

$success_msg = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);

// Active orders
$sql_active_count = "SELECT COUNT(BASKET_ID) as active_count 
                     FROM ORDER_BASKET 
                     WHERE status = 'incomplete'";
$result = $mysqli->query($sql_active_count);
if (!$result) die("SQL Error (Active Orders): " . $mysqli->error);
$active_order_count = $result->fetch_assoc()['active_count'] ?? 0;

// All future reservations (stored procedure)
$all_reservations = [];
$sql_all_res = "SELECT * FROM v_get_all_future_reservations";
$result = $mysqli->query($sql_all_res);
if (!$result) die("SQL Error (All Reservations): ". $mysqli->error . "<br><br>Tip: Make sure you ran 00_permissions.sql to create the procedure.");

while ($row = $result->fetch_assoc()) {
    $all_reservations[] = $row;
}
$result->close();
// flush extra result sets
while ($mysqli->more_results() && $mysqli->next_result()) { /* flush */ }

// ต่อใหม่อีกรอบ
$mysqli->close();
require '00_db_connect.php';

// Tables (for select + capacity map)
$available_tables = [];
$sql_tables = "SELECT TABLE_ID, number_of_seat FROM TABLE_INFO ORDER BY TABLE_ID";
$result_tables = $mysqli->query($sql_tables);
if (!$result_tables) die("SQL Error (All Tables): " . $mysqli->error);
while ($row = $result_tables->fetch_assoc()) {
    $available_tables[] = $row;
}

// Helper functions
function fmtDT($isoLike) {
    if (empty($isoLike)) return '—';
    try {
        $date = new DateTime($isoLike);
        return $date->format('j M Y, H:i');
    } catch (Exception $e) {
        return $isoLike;
    }
}

function toInputDT($isoLike) {
    if (empty($isoLike)) return '';
    try {
        $date = new DateTime($isoLike);
        return $date->format('Y-m-d\TH:i');
    } catch (Exception $e) {
        return '';
    }
}

$min_datetime_local = (new DateTime('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d\TH:i');

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>RestaurantPOS • Reservations</title>
  <link rel="stylesheet" href="09_style.css"/>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div class="brand-badge">ϟ</div><div><div style="font-weight:700">RestaurantPOS</div><div class="brand-sub">Professional Edition</div></div></div>

    <nav class="nav">
      <a href="01_dashboard.php"><i data-lucide="layout-dashboard"></i> Dashboard</a>
      <a href="02_tables.php"><i data-lucide="table-2"></i> Tables</a>
      <a href="02_reservation.php" class="active"><i data-lucide="book-marked"></i> Reservations</a>
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

    <div class="panel">
      <div class="container" style="display:grid;gap:1.25rem">
        <div><h1 style="margin:.25rem 0;font-size:1.75rem;font-weight:800">Reservations</h1>
          <p class="muted">Manage all future bookings</p></div>

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

        <div class="grid" style="grid-template-columns:1fr 2fr">

          <!-- Create Reservation Form -->
          <div class="card">
            <div class="card-h">Create Reservation</div>
            <div class="card-c">
                <form id="formCreateRes" action="02_reservation.php" method="POST" style="display:grid;gap:.75rem">
                    <input type="hidden" name="action" value="create_reservation">

                    <div>
                        <label class="muted" style="font-size:.9rem">Table</label>
                        <select id="tableSelect" name="table_id" class="input" required>
                            <option value="">Select a table...</option>
                            <?php foreach ($available_tables as $t): ?>
                                <option value="<?php echo $t['TABLE_ID']; ?>">
                                    Table <?php echo $t['TABLE_ID']; ?> (<?php echo $t['number_of_seat']; ?> seats)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Customer Name & Phone -->
                    <div class="grid" style="grid-template-columns:1fr 1fr; gap:.75rem">
                        <div>
                            <label class="muted" style="font-size:.9rem">First Name</label>
                            <input name="first_name" type="text" class="input" placeholder="Alice" required>
                        </div>
                        <div>
                            <label class="muted" style="font-size:.9rem">Last Name</label>
                            <input name="last_name" type="text" class="input" placeholder="Smith">
                        </div>
                    </div>
                    <div>
                        <label class="muted" style="font-size:.9rem">Phone</label>
                        <input name="phone" type="text" class="input" placeholder="080-000-0000">
                    </div>

                    <div>
                        <label class="muted" style="font-size:.9rem">Party Size</label>
                        <!-- dropdown ตามจำนวนที่นั่ง -->
                        <select id="partySize" name="party_size" class="input" required>
                            <option value="">Select party size...</option>
                        </select>
                    </div>
                    <div>
                        <label class="muted" style="font-size:.9rem">Reservation Date & Time</label>
                        <input name="reservation_time" type="datetime-local" class="input" min="<?php echo $min_datetime_local; ?>" required>
                    </div>
                    <button id="btnCreate" type="submit" class="btn" style="width:100%;background:#22c55e1a">Create Reservation</button>
                </form>
            </div>
          </div>

          <!-- Reservation List -->
          <div class="card">
            <div class="card-h">Upcoming Bookings</div>
            <div class="card-c" style="padding:0">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Table</th>
                            <th>Party</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_reservations)): ?>
                            <tr><td colspan="4" class="muted" style="padding:1rem">No upcoming reservations found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($all_reservations as $res): ?>
                                <tr>
                                    <td><?php echo fmtDT($res['reservation_time']); ?></td>
                                    <td>Table <?php echo $res['TABLE_ID']; ?></td>
                                    <td><?php echo $res['number_of_customer']; ?></td>
                                    <td>
                                        <div style="display:flex; gap: 0.5rem;">
                                            <button class="btn btn-edit"
                                                data-resid="<?php echo $res['RESERVATION_ID']; ?>"
                                                data-tableid="<?php echo $res['TABLE_ID']; ?>"
                                                data-fname="<?php echo htmlspecialchars($res['First_Name'] ?? ''); ?>"
                                                data-lname="<?php echo htmlspecialchars($res['Last_name'] ?? ''); ?>"
                                                data-phone="<?php echo htmlspecialchars($res['phone'] ?? ''); ?>"
                                                data-party="<?php echo $res['number_of_customer']; ?>"
                                                data-seats="<?php echo $res['number_of_seat']; ?>"
                                                data-time="<?php echo toInputDT($res['reservation_time']); ?>">
                                                <i data-lucide="square-pen"></i>
                                            </button>
                                            <button class="btn btn-cancel"
                                                data-resid="<?php echo $res['RESERVATION_ID']; ?>">
                                                <i data-lucide="trash-2"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
          </div>

        </div>
      </div>
    </div>
  </main>
</div>

<!-- Forms for Modals -->
<form id="formCancelRes" action="02_reservation.php" method="POST" style="display:none;">
    <input type="hidden" name="action" value="cancel_reservation">
    <input type="hidden" name="reservation_id" id="cancelResId" value="0">
</form>

<!-- Modals -->
<div id="confirmModal" class="modal"><div class="card" style="max-width:420px;width:100%">
  <div class="card-h">Confirm Cancellation</div>
  <div class="card-c">
    <p id="confirmText">Are you sure you want to cancel this reservation?</p>
    <p class="muted" style="font-size:.85rem" id="confirmSubtext">This action cannot be undone.</p>
  </div>
  <div style="display:flex;gap:.5rem;justify-content:flex-end;padding:1rem;border-top:1px solid #eee">
    <button id="confirmCancel" class="btn">Close</button>
    <button id="confirmOk" class="btn" style="background:#eaf2ff">Yes, Cancel</button>
  </div>
</div></div>

<div id="editModal" class="modal"><div class="card" style="max-width:520px;width:100%">
    <div class="card-h">Edit Reservation</div>

    <div style="padding: 0.75rem 1.25rem; background: #f8fafc; border-bottom: 1px solid #eee;">
        <div id="edModalCustomerName" style="font-weight: 700; font-size: 1.1rem; color: #1e293b;"></div>
        <div id="edModalCustomerPhone" style="font-size: 0.9rem; color: #64748b;"></div>
    </div>

    <form id="editForm" action="02_reservation.php" method="POST">
        <input type="hidden" name="action" value="edit_reservation">
        <input type="hidden" name="reservation_id" id="edResId" value="0">

        <div class="card-c" style="display:grid;gap:.75rem">
          <div class="grid" style="grid-template-columns:1fr 1fr; gap:.75rem">
            <div>
              <label class="muted" style="font-size:.9rem">Table</label>
              <select id="edTableId" name="table_id" class="input">
                  <?php foreach ($available_tables as $t): ?>
                      <option value="<?php echo $t['TABLE_ID']; ?>">
                          Table <?php echo $t['TABLE_ID']; ?> (<?php echo $t['number_of_seat']; ?> seats)
                      </option>
                  <?php endforeach; ?>
              </select>
            </div>
            <div>
                <label class="muted" style="font-size:.9rem">Party Size</label>
                <select id="edParty" name="party_size" class="input" required></select>
            </div>
            <div style="grid-column: 1 / -1">
              <label class="muted" style="font-size:.9rem">Reservation Date & Time</label>
              <input id="edDT" name="reservation_time" type="datetime-local" class="input" min="<?php echo $min_datetime_local; ?>">
            </div>
          </div>
        </div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;padding:1rem;border-top:1px solid #eee">
          <button id="edCancel" type="button" class="btn">Close</button>
          <button id="edSave" type="submit" class="btn" style="background:#eaf2ff">Save changes</button>
        </div>
    </form>
</div></div>

<script>
  const $ = id => document.getElementById(id);

  const confirmModal = $('confirmModal');
  const editModal = $('editModal');

  // ====== MAP จำนวนที่นั่งของแต่ละโต๊ะ (จาก PHP -> JS) ======
  const tableCapacities = <?php
      $cap = [];
      foreach ($available_tables as $t) {
          $cap[$t['TABLE_ID']] = (int)$t['number_of_seat'];
      }
      echo json_encode($cap);
  ?>;

  function populatePartyOptions(selectEl, capacity, selectedValue = null) {
      selectEl.innerHTML = '';
      if (!capacity) {
          const opt = document.createElement('option');
          opt.value = '';
          opt.textContent = 'Select party size...';
          selectEl.appendChild(opt);
          return;
      }
      for (let i = 1; i <= capacity; i++) {
          const opt = document.createElement('option');
          opt.value = i;
          opt.textContent = i;
          if (selectedValue && Number(selectedValue) === i) {
              opt.selected = true;
          }
          selectEl.appendChild(opt);
      }
  }

  // CREATE form
  const tableSelect = document.getElementById('tableSelect');
  const partySelect = document.getElementById('partySize');

  if (tableSelect && partySelect) {
      tableSelect.addEventListener('change', () => {
          const tid = tableSelect.value;
          const cap = tableCapacities[tid] || 0;
          populatePartyOptions(partySelect, cap, null);
      });
  }

  // EDIT modal
  const edTableId = document.getElementById('edTableId');
  const edParty   = document.getElementById('edParty');

  document.querySelectorAll('.btn-edit').forEach(btn => {
      btn.onclick = () => {
          const tid   = btn.dataset.tableid;
          const party = btn.dataset.party;
          const cap   = tableCapacities[tid] || Number(btn.dataset.seats) || 0;

          $('edResId').value   = btn.dataset.resid;
          edTableId.value      = tid;
          populatePartyOptions(edParty, cap, party);
          $('edDT').value      = btn.dataset.time;

          const fullName = (btn.dataset.fname + ' ' + btn.dataset.lname).trim();
          $('edModalCustomerName').textContent  = fullName || '';
          $('edModalCustomerPhone').textContent = btn.dataset.phone || '';

          editModal.classList.add('show');
      };
  });

  if (edTableId && edParty) {
      edTableId.addEventListener('change', () => {
          const tid = edTableId.value;
          const cap = tableCapacities[tid] || 0;
          populatePartyOptions(edParty, cap, null);
      });
  }

  if ($('edCancel')) $('edCancel').onclick = () => editModal.classList.remove('show');

  // Confirmation Modal (Cancel)
  let formToSubmit = null;

  document.querySelectorAll('.btn-cancel').forEach(btn => {
      btn.onclick = () => {
          $('cancelResId').value = btn.dataset.resid;
          formToSubmit = 'formCancelRes';
          confirmModal.classList.add('show');
      };
  });

  if ($('confirmCancel')) $('confirmCancel').onclick = () => {
      confirmModal.classList.remove('show');
      formToSubmit = null;
  };

  if ($('confirmOk')) $('confirmOk').onclick = () => {
      if (formToSubmit) {
          $(formToSubmit).submit();
      }
  };

  lucide.createIcons();
</script>
</body>
</html>
