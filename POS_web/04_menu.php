<?php
session_start();


// AUTHENTICATION GUARD
if (!isset($_SESSION['role'])) {
   header("Location: 00_login.php");
   exit;
}


// 1) DB CONNECT
require_once '00_db_connect.php'; // gives $mysqli
$conn = $mysqli;                  // normalize variable name


// Helper: money format (USD)
function money($amount) {
   return '$' . number_format((float)$amount, 2);
}


// Map UI category -> DB ENUM value
function uiCatToDbType($cat) {
   if ($cat === 'beverages') return 'beverages';
   if ($cat === 'mains')     return 'main courses';
   return 'appetizers'; // sides
}


// Map DB ENUM -> UI category key
function dbTypeToUiCat($type) {
   $t = strtolower($type);
   if ($t === 'beverages')      return 'beverages';
   if ($t === 'main courses')   return 'mains';
   if ($t === 'appetizers')     return 'sides';
   return 'sides';
}


// Helper: category -> icon name
function getIconForCat($cat) {
   if ($cat === 'beverages') return 'coffee';
   if ($cat === 'mains')     return 'fork-knife';
   return 'cookie'; // sides & others
}


/* -------------------------------------------------
  2) HANDLE POST (save / toggle / delete item)
  ------------------------------------------------- */


if ($_SERVER['REQUEST_METHOD'] === 'POST') {


   // DELETE ITEM
   if (isset($_POST['action']) && $_POST['action'] === 'delete_item') {
       $id = isset($_POST['menu_id']) ? (int)$_POST['menu_id'] : 0;


       if ($id > 0) {
           $stmt = $conn->prepare("DELETE FROM MENU WHERE MENU_ID = ?");
           if ($stmt) {
               $stmt->bind_param("i", $id);
               $stmt->execute();
               $stmt->close();
           }
       }


       header("Location: 04_menu.php");
       exit;
   }


   // SAVE (add or edit)
   if (isset($_POST['action']) && $_POST['action'] === 'save_item') {


       $id     = isset($_POST['menu_id']) ? (int)$_POST['menu_id'] : 0;
       $name   = trim($_POST['name'] ?? '');
       $price  = (float)($_POST['price'] ?? 0);
       $catUi  = $_POST['category'] ?? 'mains';  // beverages / mains / sides
       $tags   = trim($_POST['tags'] ?? '');
       $amount = (int)($_POST['amount'] ?? 0);   // real amount


       $dbType = uiCatToDbType($catUi);


       if ($name !== '' && $price > 0) {
           if ($id > 0) {
               // UPDATE existing menu item
               $stmt = $conn->prepare(
                   "UPDATE MENU
                    SET name = ?, price = ?, amount = ?, type = ?, ingredient = ?
                    WHERE MENU_ID = ?"
               );
               $stmt->bind_param("sdissi", $name, $price, $amount, $dbType, $tags, $id);
               $stmt->execute();
               $stmt->close();
           } else {
               // INSERT new item
               $stmt = $conn->prepare(
                   "INSERT INTO MENU(name, price, amount, type, ingredient)
                    VALUES (?,?,?,?,?)"
               );
               $stmt->bind_param("sdiss", $name, $price, $amount, $dbType, $tags);
               $stmt->execute();
               $stmt->close();
           }
       }


       header("Location: 04_menu.php");
       exit;
   }

// TOGGLE availability (status 'available' <-> 'disabled')
if (isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
 
    $id = isset($_POST['menu_id']) ? (int)$_POST['menu_id'] : 0;
    
    if ($id > 0) {
        
        // --- 1. FIXED: Securely SELECT the 'status' column ---
        $stmt_select = $conn->prepare("SELECT status FROM MENU WHERE MENU_ID = ?");
        $stmt_select->bind_param("i", $id);
        $stmt_select->execute();
        $res = $stmt_select->get_result();
        
        if ($row = $res->fetch_assoc()) {
            $current_status = $row['status']; 
            $newStatus = ($current_status === 'available') ? 'disabled' : 'available';
            $res->free();
            $stmt_select->close();
            $stmt_update = $conn->prepare("UPDATE MENU SET status = ? WHERE MENU_ID = ?");
            $stmt_update->bind_param("si", $newStatus, $id); 
            $stmt_update->execute();
            $conn->commit();
            $stmt_update->close();
            
        } else {
            if (isset($res)) $res->free();
            if (isset($stmt_select)) $stmt_select->close();
        }
    }
    
    // Redirect back to the page
    header("Location: 04_menu.php");
    exit;
}}



/* -------------------------------
  3) LOAD MENU ITEMS FROM DB
  ------------------------------- */


$all_menu_items = [];


$sql = "SELECT MENU_ID, name, price, amount, status, type, ingredient
       FROM MENU
       ORDER BY type, name";
if ($result = $conn->query($sql)) {
   while ($row = $result->fetch_assoc()) {


       $cat    = dbTypeToUiCat($row['type']);
       $amount = (int)$row['amount'];


       // Derive status safely (in case some old rows have NULL)
       $status = $row['status'] ?? '';
       if ($status !== 'available' && $status !== 'disabled') {
           $status = ($amount > 0) ? 'available' : 'disabled';
       }


       $all_menu_items[] = [
           'id'        => (int)$row['MENU_ID'],
           'name'      => $row['name'],
           'price'     => (float)$row['price'],
           'category'  => $cat,
           'tags'      => $row['ingredient'],
           'amount'    => $amount,
           'status'    => $status,
           'available' => ($status === 'available') ? 1 : 0,
           'featured'  => 0,
       ];
   }
   $result->free();
}
?>
<!doctype html>
<html lang="en">
<head>
 <meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
 <title>RestaurantPOS • Menu</title>
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
     <a href="04_menu.php" class="active"><i data-lucide="utensils"></i> Menu</a>
     <?php
     if (isset($_SESSION['role']) && $_SESSION['role'] === 'manager') {
         echo '<a href="05_reports.php"><i data-lucide="receipt"></i> Reports</a>';
     }
     ?>
   </nav>


   <nav class="nav" style="margin-top:auto;border-top:1px solid #eee;">
     <a href="00_logout.php">
       <i data-lucide="log-out"></i>
       Logout (<?php echo htmlspecialchars($_SESSION['name']); ?>)
     </a>
   </nav>
 </aside>


 <main class="main">
   <div class="topbar">
     <div id="topDate" class="muted"><?php echo date('l, j F Y'); ?></div>
     <div style="display:flex;gap:.6rem;align-items:center">
       <div style="position:relative">
         <i data-lucide="search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#6b7280"></i>
         <input id="q" class="input" placeholder="Search menu items..." style="padding-left:2rem;width:260px">
       </div>
       <button id="add" class="btn"><i data-lucide="plus"></i> Add New Menu</button>
     </div>
   </div>


   <div class="panel">
     <div class="container" style="display:grid;gap:1.25rem">
       <div>
         <h1 style="margin:.25rem 0;font-size:1.75rem;font-weight:800">Menu Management</h1>
         <p class="muted">Manage your restaurant's menu items and categories</p>
       </div>


       <div style="display:flex;gap:.5rem" id="tabs">
         <button class="pill active" data-cat="all"><i data-lucide="list"></i> All Items</button>
         <button class="pill" data-cat="beverages"><i data-lucide="coffee"></i> Beverages</button>
         <button class="pill" data-cat="mains"><i data-lucide="fork-knife"></i> Main Courses</button>
         <button class="pill" data-cat="sides"><i data-lucide="cookie"></i> Sides & Appetizers</button>
       </div>
       <div class="muted" style="font-size:.9rem">
         Items: <span id="count"><?php echo count($all_menu_items); ?></span>
       </div>


       <div id="grid" class="grid g3">
         <?php if (empty($all_menu_items)): ?>
           <div class="muted">No items</div>
         <?php else: ?>
           <?php foreach ($all_menu_items as $item): ?>
             <div class="card"
                  data-id="<?php echo (int)$item['id']; ?>"
                  data-name="<?php echo htmlspecialchars($item['name']); ?>"
                  data-price="<?php echo htmlspecialchars($item['price']); ?>"
                  data-cat="<?php echo htmlspecialchars($item['category']); ?>"
                  data-tags="<?php echo htmlspecialchars($item['tags'] ?? ''); ?>"
                  data-amount="<?php echo (int)$item['amount']; ?>"
                  data-status="<?php echo htmlspecialchars($item['status']); ?>"
                  data-featured="<?php echo !empty($item['featured']) ? '1':'0'; ?>"
                  >
               <div class="card-h" style="display:flex;align-items:center;justify-content:space-between">
                 <div style="display:flex;gap:.5rem;align-items:center">
                   <i data-lucide="<?php echo getIconForCat($item['category']); ?>"></i>
                   <div style="font-weight:700">
                     <?php echo htmlspecialchars($item['name']); ?>
                     <?php if (!empty($item['featured'])): ?>
                       <span class="badge">★ Featured</span>
                     <?php endif; ?>
                   </div>
                 </div>
                 <?php if (!empty($item['available'])): ?>
                   <span class="chip chip-available">Available</span>
                 <?php else: ?>
                   <span class="chip chip-reserved">Disabled</span>
                 <?php endif; ?>
               </div>
               <div class="card-c" style="display:grid;gap:.35rem">
                 <div class="muted" style="font-size:.9rem">
                   <?php echo !empty($item['tags']) ? htmlspecialchars($item['tags']) : '—'; ?>
                 </div>
                 <div style="font-size:1.2rem;font-weight:800">
                   <?php echo money($item['price']); ?>
                 </div>
                 <div class="muted" style="font-size:.8rem">
                   Amount: <?php echo (int)$item['amount']; ?> • Status: <?php echo htmlspecialchars($item['status']); ?>
                 </div>
                 <div style="display:flex;gap:.5rem">
                   <button type="button" class="btn sm edit-btn" data-edit-id="<?php echo (int)$item['id']; ?>">
                     <i data-lucide="square-pen"></i> Edit Item
                   </button>


                   <form method="post" style="margin:0">
                     <input type="hidden" name="action" value="toggle_status">
                     <input type="hidden" name="menu_id" value="<?php echo (int)$item['id']; ?>">
                     <button type="submit" class="btn sm">
                       <?php echo $item['available'] ? 'Disable' : 'Enable'; ?>
                     </button>
                   </form>
                 </div>
               </div>
             </div>
           <?php endforeach; ?>
         <?php endif; ?>
       </div>
     </div>
   </div>
 </main>
</div>


<!-- Modal (Add / Edit) -->
<div id="modal" class="modal">
 <div class="card" style="max-width:640px;width:100%;position:relative">
   <!-- MAIN FORM: add/edit -->
   <form id="modalForm" style="width:100%" method="post" action="04_menu.php">
     <input type="hidden" name="action" value="save_item">
     <input type="hidden" id="mId" name="menu_id">


     <div class="card-h" id="mTitle">Add Menu</div>
     <div class="card-c" style="display:grid;gap:.75rem">
       <div class="grid" style="grid-template-columns:1fr 1fr;gap:.75rem">
         <div>
           <label class="muted" style="font-size:.9rem">Name</label>
           <input id="mName" name="name" class="input" required>
         </div>
         <div>
           <label class="muted" style="font-size:.9rem">Price (USD)</label>
           <input id="mPrice" name="price" type="number" step="0.01" min="0" class="input" required>
         </div>
       </div>
       <div class="grid" style="grid-template-columns:1fr 1fr;gap:.75rem">
         <div>
           <label class="muted" style="font-size:.9rem">Category</label>
           <select id="mCat" name="category" class="input">
             <option value="beverages">Beverages</option>
             <option value="mains">Main Courses</option>
             <option value="sides">Sides & Appetizers</option>
           </select>
         </div>
         <div>
           <label class="muted" style="font-size:.9rem">Amount</label>
           <input id="mAmount" name="amount" type="number" min="0" class="input" required>
         </div>
       </div>
       <div>
         <label class="muted" style="font-size:.9rem">Tags (comma-separated)</label>
         <input id="mTags" name="tags" class="input" placeholder="e.g., Beef Patty, Lettuce">
       </div>
       <label style="display:flex;align-items:center;gap:.5rem">
         <input id="mFeat" type="checkbox"> Featured
       </label>
     </div>
     <div style="display:flex;gap:.5rem;justify-content:flex-end;padding:1rem;border-top:1px solid #eee">
       <button id="mCancel" class="btn" type="button">Cancel</button>
       <button id="mSave" class="btn" type="submit" style="background:#eaf2ff">Save</button>
     </div>
   </form>


   <!-- DELETE FORM: bottom-left corner -->
   <form id="deleteForm" method="post" action="04_menu.php"
         style="position:absolute;left:1rem;bottom:1rem;display:none">
     <input type="hidden" name="action" value="delete_item">
     <input type="hidden" id="dId" name="menu_id">
     <button type="submit" class="btn"
             style="background:#fee2e2;color:#b91c1c"
             onclick="return confirm('ลบเมนูนี้ออกจากระบบใช่ไหม?');">
       Delete
     </button>
   </form>
 </div>
</div>


<script>
 const tabs   = document.getElementById('tabs');
 const grid   = document.getElementById('grid');
 const qInput = document.getElementById('q');
 const countSpan = document.getElementById('count');
 const modal  = document.getElementById('modal');
 const mId    = document.getElementById('mId');
 const mName  = document.getElementById('mName');
 const mPrice = document.getElementById('mPrice');
 const mCat   = document.getElementById('mCat');
 const mAmount= document.getElementById('mAmount');
 const mTags  = document.getElementById('mTags');
 const mFeat  = document.getElementById('mFeat');
 const mTitle = document.getElementById('mTitle');


 const deleteForm = document.getElementById('deleteForm');
 const dId        = document.getElementById('dId');


 function openModal() { modal.classList.add('show'); }
 function closeModal() { modal.classList.remove('show'); }


 function applyFilters() {
   const activeTab = document.querySelector('#tabs .pill.active');
   const cat = activeTab ? activeTab.getAttribute('data-cat') : 'all';
   const q   = (qInput.value || '').toLowerCase();


   let visible = 0;
   grid.querySelectorAll('.card').forEach(card => {
     const itemCat = card.getAttribute('data-cat') || card.getAttribute('data-cat');
     const tags    = (card.getAttribute('data-tags') || '').toLowerCase();
     const name    = (card.getAttribute('data-name') || '').toLowerCase();


     const matchCat = (cat === 'all') || (itemCat === cat);
     const matchQ   = !q || name.includes(q) || tags.includes(q);


     if (matchCat && matchQ) {
       card.style.display = '';
       visible++;
     } else {
       card.style.display = 'none';
     }
   });
   countSpan.textContent = visible;
 }


 tabs.addEventListener('click', e => {
   const pill = e.target.closest('.pill[data-cat]');
   if (!pill) return;
   document.querySelectorAll('#tabs .pill').forEach(b => b.classList.remove('active'));
   pill.classList.add('active');
   applyFilters();
 });


 qInput.addEventListener('input', applyFilters);


 // OPEN ADD
 document.getElementById('add').addEventListener('click', () => {
   mTitle.textContent = 'Add Menu';
   mId.value = '';
   mName.value = '';
   mPrice.value = '';
   mCat.value = 'mains';
   mAmount.value = 1;
   mTags.value = '';
   mFeat.checked = false;


   if (deleteForm) {
     deleteForm.style.display = 'none';
     dId.value = '';
   }


   openModal();
 });


 // OPEN EDIT
 grid.addEventListener('click', e => {
   const btn = e.target.closest('.edit-btn');
   if (!btn) return;
   const card = btn.closest('.card');
   if (!card) return;


   mTitle.textContent = 'Edit Menu';
   mId.value     = card.getAttribute('data-id') || '';
   mName.value   = card.getAttribute('data-name') || '';
   mPrice.value  = card.getAttribute('data-price') || '';
   mCat.value    = card.getAttribute('data-cat') || 'mains';
   mAmount.value = card.getAttribute('data-amount') || 0;
   mTags.value   = card.getAttribute('data-tags') || '';
   mFeat.checked = card.getAttribute('data-featured') === '1';


   // show delete button for existing item
   if (deleteForm) {
     deleteForm.style.display = 'block';
     dId.value = mId.value;
   }


   openModal();
 });


 document.getElementById('mCancel').addEventListener('click', closeModal);


 document.addEventListener('DOMContentLoaded', () => {
   applyFilters();
   lucide.createIcons();
 });
</script>
</body>
</html>



