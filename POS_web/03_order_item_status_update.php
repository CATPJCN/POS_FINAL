<?php
// 03_order_item_status_update.php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: 00_login.php");
    exit;
}

require_once '00_db_connect.php';

if (
    !isset($_POST['table_id']) ||
    !isset($_POST['basket_id']) ||
    !isset($_POST['menu_id'])
) {
    header("Location: 02_tables.php?error=Missing+parameters");
    exit;
}

$table_id  = (int)$_POST['table_id'];
$basket_id = (int)$_POST['basket_id'];
$menu_id   = (int)$_POST['menu_id'];

// อัปเดต status ของ ORDERED_ITEM เป็น finished ถ้ายังไม่ finished
$stmt = $mysqli->prepare("
    UPDATE ORDERED_ITEM
    SET status = 'finished'
    WHERE BASKET_ID = ? AND MENU_ID = ? AND status <> 'finished'
");
$stmt->bind_param("ii", $basket_id, $menu_id);
$stmt->execute();
$stmt->close();

// กลับไปหน้า orders ของโต๊ะเดิม
header("Location: 03_orders.php?table_id=" . $table_id);
exit;
