<?php

session_start();
require_once '00_db_connect.php'; // Gives $mysqli

// Check if $mysqli connection exists
if (!$mysqli) {
    // This is a server problem, so we can die.
    die("Connection failed: " . mysqli_connect_error());
}

// 1. Get data from the form
$name_from_form = $_POST['name'] ?? '';
$plaintext_password = $_POST['code'] ?? '';
$role_from_form = $_POST['role'] ?? '';

if (empty($name_from_form) || empty($plaintext_password) || empty($role_from_form)) {
    header("Location: 00_login.php?error=All fields are required.");
    exit;
}

// 2. SPLIT THE NAME
$parts = explode(' ', $name_from_form, 2);
$first_name = $parts[0];
$last_name = $parts[1] ?? '';

// 3. Prepare a query to fetch the hash
$sql = "SELECT EMPLOYEE_ID, First_Name, password, role 
        FROM EMPLOYEE 
        WHERE LOWER(First_Name) = LOWER(?) 
          AND LOWER(Last_Name) = LOWER(?) 
          AND role = ?";

$stmt = $mysqli->prepare($sql);

if ($stmt === false) {
    // A database error happened. Send user back to login.
    header("Location: 00_login.php?error=Invalid name, role, or code");
    exit;
}

$stmt->bind_param("sss", $first_name, $last_name, $role_from_form);
$stmt->execute();
$result = $stmt->get_result();

// 4. Check if a user was found
if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
    
    // 5. Verify the password
    if (password_verify($plaintext_password, $user['password'])) {
        
        // --- LOGIN SUCCESSFUL ---
        session_regenerate_id(true); // Security: prevent session fixation
        
        $_SESSION['user_id'] = $user['EMPLOYEE_ID'];
        $_SESSION['name'] = $user['First_Name'];
        $_SESSION['role'] = $user['role'];
        
        // This is the redirect to the dashboard
        header("Location: 01_dashboard.php");
        exit; // Always call exit() after a header redirect
    }
}

// --- LOGIN FAILED ---
// If the user wasn't found (step 4) or password was wrong (step 5)
// they end up here.
$stmt->close();
$mysqli->close();
header("Location: 00_login.php?error=Invalid name, role, or code.");
exit;
?>