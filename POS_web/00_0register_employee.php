<?php

session_start();
// DATABASE CONNECTION only for admin
$servername = "localhost";    
$username = "<your admin user name/posadmin by default>";   
$password = "<your user password>";   
$dbname = "pos"; 

$mysqli = new mysqli($servername, $username, $password, $dbname);

// Check the connection
if ($mysqli->connect_errno) {
    die("Connection failed: " . $mysqli->connect_error);
}

// FORM PROCESSING (Check if the form was submitted)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Get data from the form
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $role = $_POST['role'] ?? '';
    $plaintext_password = $_POST['code'] ?? '';

    // Validate data
    if (empty($first_name) || empty($role) || empty($plaintext_password)) {
        $message = "Error: First Name, Role, and Code are required.";
    
    } elseif ($role !== 'staff' && $role !== 'manager') {
        $message = "Error: Invalid role selected.";
    
    } else {
        // Data is valid, proceed with insertion
        try {
            $hashed_password = password_hash($plaintext_password, PASSWORD_BCRYPT);
            
            // Prepare the SQL to insert into EMPLOYEE table
            $stmt = $mysqli->prepare(
                "INSERT INTO EMPLOYEE (First_Name, Last_Name, password, role) 
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param("ssss", $first_name, $last_name, $hashed_password, $role);
            $stmt->execute();

            $message = "Success! Employee '" . htmlspecialchars($first_name) . "' has been created.";
            $message_type = 'success';
            $stmt->close();

        } catch (mysqli_sql_exception $e) {
            // Catch errors, like if the user already exists (if you add a UNIQUE constraint)
            $message = "Database error. User may already exist.";
        }
    }
}

// Close the database connection
$mysqli->close();

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Add Employee • RestaurantPOS</title>
  <link rel="stylesheet" href="09_style.css"/>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body style="min-height:100vh;display:grid;place-items:center;background:#f3f4f6">

  <div class="card" style="padding:1.25rem;border-radius:1rem;max-width:100%;width:100%">
    
    <h1 style="font-weight:700;font-size:2.0 rem;margin:0 0 .75rem">Add New Employee</h1>

    <?php if (!empty($message)): ?>
      <p id="msg" style="color: <?php echo ($message_type === 'success') ? '#16a34a' : '#dc2626'; ?>; border: 1px solid <?php echo ($message_type === 'success') ? '#16a34a' : '#dc2626'; ?>; background: <?php echo ($message_type === 'success') ? '#f0fdf4' : '#fef2f2'; ?>; border-radius: .6rem; padding: .5rem .7rem; height:auto;margin:.25rem 0 .75rem;font-size:.9rem">
        <?php echo $message; ?>
      </p>
    <?php endif; ?>

    <form id="registerForm" method="POST" action="">
      
      <div style="display:flex; gap: 1rem;">
          <div style="flex: 1;">
            <label class="muted" style="font-size:1.5rem">First Name</label>
            <input id="first_name" name="first_name" class="input" placeholder="e.g., Atikarn" style="margin:.5rem 0 1rem"/>
          </div>
          <div style="flex: 1;">
            <label class="muted" style="font-size:1.5rem">Last Name</label>
            <input id="last_name" name="last_name" class="input" placeholder="e.g., C." style="margin:.5rem 0 1rem"/>
          </div>
      </div>

      <label class="muted" style="font-size:1.5rem">Role</label>
      <select id="role" name="role" class="input" style="margin:.25rem 0 1rem">
        <option value="staff">Staff</option>
        <option value="manager">Manager</option>
      </select>

      <div>
        <label class="muted" style="font-size:1.5rem;">Password</label>
        <input id="code" name="code" type="password" class="input" placeholder="Enter new password" style="margin:.25rem 0 1rem"/>
      </div>

      <button id="login" type="submit" class="btn" style="width:100%;background:#4f46e5;color:#fff;font-weight:600">
        <i data-lucide="user-plus"></i> Create Employee
      </button>
    </form>
  </div>

<script>
  
  lucide.createIcons();
</script>
</body>
</html>