<?php

$servername = "localhost";    
$username = "Webemployees";   
$password = "employee555";   
$dbname = "pos"; 

// Create the connection
$mysqli = new mysqli($servername, $username, $password, $dbname);

// Check the connection
if ($mysqli->connect_errno) {
    // This stops the script and shows the error
    die("Connection failed: " . $mysqli->connect_error);
}

?>