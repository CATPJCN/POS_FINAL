<?php

$servername = "localhost";    
$username = "<your web user name>";   
$password = "<your user password>";    
$dbname = "pos"; 

// Create the connection
$mysqli = new mysqli($servername, $username, $password, $dbname);

// Check the connection
if ($mysqli->connect_errno) {
    // This stops the script and shows the error
    die("Connection failed: " . $mysqli->connect_error);
}

?>