<?php
// 03_orders_process_payment.php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: 00_login.php");
    exit;
}

require_once '00_db_connect.php';

// Basic validation
if (!isset($_POST['table_id']) || !isset($_POST['payment_method'])) {
    header("Location: 02_tables.php?error=Missing+payment+data");
    exit;
}

$table_id       = (int)$_POST['table_id'];
$payment_method = $_POST['payment_method'];
$basket_id      = isset($_POST['basket_id']) ? (int)$_POST['basket_id'] : null;

try {
    // Start transaction
    $mysqli->begin_transaction();

    $total = $_POST['amount'];

    if ($total <= 0) {
        throw new Exception("No completed orders to pay for on this table.");
    }

    // 2) Insert RECEIPT (BEFORE INSERT triggers will still run:
    //    - trg_invoice_gate
    //    - trg_price_lock_on_receipt)
    // After insert add sales too
    $sqlReceipt = "
        INSERT INTO RECEIPT (TABLE_ID, paid_amount, payment_method)
        VALUES (?, ?, ?)
    ";
    $stmt = $mysqli->prepare($sqlReceipt);
    if (!$stmt) {
        throw new Exception("Prepare failed (receipt): " . $mysqli->error);
    }

    $stmt->bind_param("ids", $table_id, $total, $payment_method);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed (receipt): " . $stmt->error);
    }

    $receipt_id = $stmt->insert_id;
    $stmt->close();


    // 4) Delete assignments for completed baskets
    $sqlDelAssign = "
        DELETE oa
        FROM ORDER_ASSIGNMENT oa
        JOIN ORDER_BASKET ob ON oa.BASKET_ID = ob.BASKET_ID
        WHERE ob.TABLE_ID = ?
          AND ob.status = 'complete'
    ";
    $stmt = $mysqli->prepare($sqlDelAssign);
    if (!$stmt) {
        throw new Exception("Prepare failed (delete assignments): " . $mysqli->error);
    }

    $stmt->bind_param("i", $table_id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed (delete assignments): " . $stmt->error);
    }
    $stmt->close();

    // 5) Delete ORDERED_ITEM from completed baskets
    $sqlDelItems = "
        DELETE oi
        FROM ORDERED_ITEM oi
        JOIN ORDER_BASKET ob ON oi.BASKET_ID = ob.BASKET_ID
        WHERE ob.TABLE_ID = ?
          AND ob.status = 'complete'
    ";
    $stmt = $mysqli->prepare($sqlDelItems);
    echo "<script>console.log('Prepared delete items SQL');</script>";
    echo "<script>console.log('SQL: ' + " . json_encode($sqlDelItems) . ");</script>";
    // echo "<script>console.log('. $stmt .');</script>";
    if (!$stmt) {
        throw new Exception("Prepare failed (delete items): " . $mysqli->error);
    }

    $stmt->bind_param("i", $table_id);
    echo "<script>console.log('Bound table_id parameter: ' + " . json_encode($table_id) . ");</script>";
    if (!$stmt->execute()) {
        throw new Exception("Execute failed (delete items): " . $stmt->error);
    }
    $stmt->close();
    echo "<script>console.log('Deleted ordered items');</script>";

    // 6) Delete completed ORDER_BASKET rows
    $sqlDelBaskets = "
        DELETE FROM ORDER_BASKET
        WHERE TABLE_ID = ?
          AND status = 'complete'
    ";
    $stmt = $mysqli->prepare($sqlDelBaskets);
    if (!$stmt) {
        throw new Exception("Prepare failed (delete baskets): " . $mysqli->error);
    }

    $stmt->bind_param("i", $table_id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed (delete baskets): " . $stmt->error);
    }
    $stmt->close();

    // 7) Check if there are any incomplete baskets left on this table
    $sqlCountIncomplete = "
        SELECT COUNT(*)
        FROM ORDER_BASKET
        WHERE TABLE_ID = ?
          AND status = 'incomplete'
    ";
    $stmt = $mysqli->prepare($sqlCountIncomplete);
    if (!$stmt) {
        throw new Exception("Prepare failed (count incomplete): " . $mysqli->error);
    }

    $stmt->bind_param("i", $table_id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed (count incomplete): " . $stmt->error);
    }

    $stmt->bind_result($incomplete_count);
    $stmt->fetch();
    $stmt->close();

    // 8) If no incomplete baskets remain, free the table
    if ($incomplete_count == 0) {
        $sqlFreeTable = "
            UPDATE TABLE_INFO
            SET table_status = 'available'
            WHERE TABLE_ID = ?
        ";
        $stmt = $mysqli->prepare($sqlFreeTable);
        if (!$stmt) {
            throw new Exception("Prepare failed (free table): " . $mysqli->error);
        }

        $stmt->bind_param("i", $table_id);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed (free table): " . $stmt->error);
        }
        $stmt->close();
    }

    // Everything OK -> commit
    $mysqli->commit();

    header("Location: 02_tables.php?success=Payment+processed");
    exit;

} catch (Exception $e) {
    // Rollback on any error
    if ($mysqli->errno || $mysqli->errno === 0) {
        $mysqli->rollback();
    }

    echo "Payment failed: " . htmlspecialchars($e->getMessage());
}
