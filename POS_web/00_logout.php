<?php

session_start();

session_unset();

session_destroy();

header("Location: 00_login.php?message=You have been logged out.");
exit;
?>