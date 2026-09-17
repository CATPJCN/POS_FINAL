USE pos;

-- ============================================================
-- CREATE EVENT
-- ============================================================
DROP EVENT IF EXISTS evt_expire_reservations;
CREATE EVENT evt_expire_reservations
ON SCHEDULE EVERY 1 DAY STARTS '2025-11-15 00:00:00' ENDS '2025-12-15 00:00:00'
DO
  UPDATE TABLE_INFO t
  JOIN RESERVATION r ON t.TABLE_ID = r.TABLE_ID
  SET t.table_status = 'available'
  WHERE t.table_status = 'reserved'
  -- This finds all reservations from before today
  AND r.reservation_time < CURDATE();


-- ============================================================
-- CREATE TRIGGERS
-- ============================================================
DELIMITER $$

-- ============================================================
-- TRIGGER 1: Recompute ORDER_BASKET.status when items are added
CREATE TRIGGER trg_order_status_recompute_insert
AFTER INSERT ON ORDERED_ITEM
FOR EACH ROW
BEGIN
   DECLARE all_finished INT;
  
   -- Check if all items in this basket are 'finished'
   SELECT COUNT(*) INTO all_finished
   FROM ORDERED_ITEM
   WHERE BASKET_ID = NEW.BASKET_ID
   AND status != 'finished';
  
   -- Update basket status
   IF all_finished = 0 THEN
       UPDATE ORDER_BASKET
       SET status = 'complete'
       WHERE BASKET_ID = NEW.BASKET_ID;
   ELSE
       UPDATE ORDER_BASKET
       SET status = 'incomplete'
       WHERE BASKET_ID = NEW.BASKET_ID;
   END IF;
END$$


-- ============================================================
-- TRIGGER 2: Recompute ORDER_BASKET.status when items are updated

CREATE TRIGGER trg_order_status_recompute_update
AFTER UPDATE ON ORDERED_ITEM
FOR EACH ROW
BEGIN
   DECLARE all_finished INT;
  
   SELECT COUNT(*) INTO all_finished
   FROM ORDERED_ITEM
   WHERE BASKET_ID = NEW.BASKET_ID
   AND status != 'finished';
  
   IF all_finished = 0 THEN
       UPDATE ORDER_BASKET
       SET status = 'complete'
       WHERE BASKET_ID = NEW.BASKET_ID;
   ELSE
       UPDATE ORDER_BASKET
       SET status = 'incomplete'
       WHERE BASKET_ID = NEW.BASKET_ID;
   END IF;

   IF NEW.status = 'finished' THEN
        UPDATE MENU
        SET amount = amount - NEW.amount
        WHERE MENU_ID = NEW.MENU_ID;
    END IF;
END$$



-- ============================================================
-- TRIGGER 4:  Block receipt creation if order is not complete
DELIMITER $$
DROP TRIGGER IF EXISTS trg_invoice_gate$$

CREATE TRIGGER trg_invoice_gate
BEFORE INSERT ON RECEIPT
FOR EACH ROW
BEGIN
    DECLARE incomplete_count INT;
    DECLARE calculated_subtotal DECIMAL(10, 2);
    DECLARE full_message VARCHAR(255);
 
    -- 1. Gatekeeper: Check for incomplete orders
    SELECT COUNT(1) INTO incomplete_count
    FROM ORDER_BASKET
    WHERE TABLE_ID = NEW.TABLE_ID
    AND (status != 'complete');
 
    IF incomplete_count > 0 THEN
        SET full_message = CONCAT('Cannot create receipt: Table has ', incomplete_count, ' incomplete orders');
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = full_message;
    END IF;

    -- 2. Auto-Calculate Logic: If paid_amount is NULL, calculate it from baskets
    IF NEW.paid_amount IS NULL THEN
        -- Sum the total_price of all complete baskets for this table
        SELECT SUM(total_price) INTO calculated_subtotal
        FROM ORDER_BASKET
        WHERE TABLE_ID = NEW.TABLE_ID
        AND status = 'complete';
        -- Set the paid_amount (Subtotal + 8% Tax), default to 0 if no baskets found
        SET NEW.paid_amount = calculated_subtotal;
    END IF;

END$$

-- ============================================================
-- TRIGGER 5: Prevent deletion of receipts (sales history protection)
-- ============================================================
CREATE TRIGGER trg_receipt_nodelete
BEFORE DELETE ON RECEIPT
FOR EACH ROW
BEGIN
   SIGNAL SQLSTATE '45000'
   SET MESSAGE_TEXT = 'Receipt deletion is not allowed - sales history must be preserved';
END$$


-- ============================================================
-- TRIGGER 6: Clean up unfinished orders when table becomes available
-- ============================================================
CREATE TRIGGER trg_table_available_cleanup
AFTER UPDATE ON TABLE_INFO
FOR EACH ROW
BEGIN
   IF NEW.table_status = 'available' AND OLD.table_status != 'available' THEN
       -- Delete unfinished ordered items for this table's baskets
       DELETE oi FROM ORDERED_ITEM oi
       INNER JOIN ORDER_BASKET ob ON oi.BASKET_ID = ob.BASKET_ID
       WHERE ob.TABLE_ID = NEW.TABLE_ID
       AND oi.status != 'finished';
      
       -- Delete incomplete baskets
       DELETE FROM ORDER_BASKET
       WHERE TABLE_ID = NEW.TABLE_ID
       AND (status IS NULL OR status = 'incomplete');
   END IF;
END$$



/*>>>>>>>>>>>>>>> RESERVATION RELATED<<<<<<<<<<<<<<<<<<<<<<<<<<<*/

-- ============================================================
-- TRIGGER 10: trg_reservation_guard Prevent double-booking reservations

DELIMITER $$

-- 2. Now, run your full CREATE TRIGGER command
CREATE TRIGGER trg_reservation_guard
BEFORE INSERT ON RESERVATION
FOR EACH ROW
BEGIN

    -- Check if that table is 'occupied'
    DECLARE v_table_status VARCHAR(50);
    DECLARE overlap_count INT;

    SELECT table_status INTO v_table_status
    FROM TABLE_INFO
    WHERE TABLE_ID = NEW.TABLE_ID;

    IF v_table_status = 'occupied' THEN
        IF NEW.reservation_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 HOUR) THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'This table is currently occupied. You cannot make a reservation for a time within the next hour.';
        END IF;
    END IF;

    -- Check for overlapping reservations (2-hour window)
    IF NEW.reservation_time IS NOT NULL THEN
        SELECT COUNT(*) INTO overlap_count
        FROM RESERVATION
        WHERE TABLE_ID = NEW.TABLE_ID
        AND reservation_time IS NOT NULL
        -- This checks for any existing booking 2 hours before OR 2 hours after the new time
        AND reservation_time BETWEEN
            DATE_SUB(NEW.reservation_time, INTERVAL 2 HOUR)
            AND DATE_ADD(NEW.reservation_time, INTERVAL 2 HOUR);
      
        IF overlap_count > 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Reservation time overlaps with existing reservation';
        END IF;
    END IF;
END$$

-- ============================================================
-- TRIGGER 17: Free table when last reservation is deleted

CREATE TRIGGER trg_cancel_reservation_free_table
AFTER DELETE ON RESERVATION
FOR EACH ROW
BEGIN
   DECLARE active_reservations INT;
  
   -- Check if there are other reservations for this table
   SELECT COUNT(*) INTO active_reservations
   FROM RESERVATION
   WHERE TABLE_ID = OLD.TABLE_ID;
  
   -- If no reservations left, set table to available
   -- But only if table is currently reserved (not occupied)
   IF active_reservations = 0 THEN
       UPDATE TABLE_INFO
       SET table_status = 'available'
       WHERE TABLE_ID = OLD.TABLE_ID
       AND table_status = 'reserved';
   END IF;
END$$




-- ============================================================
-- TRIGGER 12: trg_price_snapshot_on_order Calculate and lock price when items are ordered

CREATE TRIGGER trg_price_snapshot_on_order
AFTER INSERT ON ORDERED_ITEM
FOR EACH ROW
BEGIN
   -- Update basket total price by summing all items
   UPDATE ORDER_BASKET
   SET total_price = (
       SELECT COALESCE(SUM(m.price * oi.amount), 0)
       FROM ORDERED_ITEM oi
       JOIN MENU m ON oi.MENU_ID = m.MENU_ID
       WHERE oi.BASKET_ID = NEW.BASKET_ID
   )
   WHERE BASKET_ID = NEW.BASKET_ID;
END$$


-- ============================================================
-- TRIGGER 13: Recalculate price when items are updated
-- ============================================================
CREATE TRIGGER trg_price_snapshot_on_order_update
AFTER UPDATE ON ORDERED_ITEM
FOR EACH ROW
BEGIN
   -- Recalculate when quantity changes
   UPDATE ORDER_BASKET
   SET total_price = (
       SELECT COALESCE(SUM(m.price * oi.amount), 0)
       FROM ORDERED_ITEM oi
       JOIN MENU m ON oi.MENU_ID = m.MENU_ID
       WHERE oi.BASKET_ID = NEW.BASKET_ID
   )
   WHERE BASKET_ID = NEW.BASKET_ID;
END$$


-- ============================================================
-- TRIGGER 15: Ensure prices are calculated before creating receipt
-- ============================================================
CREATE TRIGGER trg_price_lock_on_receipt
BEFORE INSERT ON RECEIPT
FOR EACH ROW
BEGIN
   DECLARE missing_prices INT;
  
   -- Check if any basket for this table has NULL total_price
   SELECT COUNT(*) INTO missing_prices
   FROM ORDER_BASKET
   WHERE TABLE_ID = NEW.TABLE_ID
   AND total_price IS NULL;
  
   IF missing_prices > 0 THEN
       SIGNAL SQLSTATE '45000'
       SET MESSAGE_TEXT = 'Cannot create receipt: Some orders missing price calculation';
   END IF;
END$$





-- ============================================================
-- TRIGGER 18: Set default status for new baskets (table must be occupied)
-- ============================================================

CREATE TRIGGER trg_basket_guard
BEFORE INSERT ON ORDER_BASKET
FOR EACH ROW
BEGIN
    DECLARE current_table_status VARCHAR(20);
    
    SELECT table_status INTO current_table_status
    FROM TABLE_INFO
    WHERE TABLE_ID = NEW.TABLE_ID;

    IF current_table_status != 'occupied' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cannot create a new order: Table status is not occupied.';

    ELSEIF NEW.status IS NULL THEN
        SET NEW.status = 'incomplete';
    END IF;
END$$

-- ============================================================
-- TRIGGER 19: 
-- Fires AFTER payment (receipt) is created.
-- 1. Archives all 'complete' order items to the SALES table.
-- 2. Deletes the original 'complete' basket, items, and assignment.
-- 3. Frees the table IF no other 'incomplete' baskets remain.
-- ============================================================

DELIMITER $$
DROP TRIGGER IF EXISTS trg_process_sales_and_cleanup$$

CREATE TRIGGER trg_process_sales_and_cleanup
AFTER INSERT ON RECEIPT
FOR EACH ROW
BEGIN
    DECLARE v_incomplete_baskets INT;

    -- Step 1: Copy all items from 'complete' baskets for this table to the permanant SALES table.
    INSERT INTO SALES (RECEIPT_ID, MENU_ID, amount, price_at_sale, item_total, created_at)
    SELECT
        NEW.RECEIPT_ID,
        oi.MENU_ID,
        oi.amount,
        m.price,
        (oi.amount * m.price),
        NEW.created_at          -- <-- This is the 6th value you were missing
    FROM ORDERED_ITEM oi
    JOIN MENU m ON oi.MENU_ID = m.MENU_ID
    JOIN ORDER_BASKET ob ON oi.BASKET_ID = ob.BASKET_ID
    WHERE ob.TABLE_ID = NEW.TABLE_ID AND ob.status = 'complete';

    -- Step 2: Clean up the 'complete' baskets (order is important: children first)

    -- Delete assignments for the completed baskets
    DELETE oa FROM ORDER_ASSIGNMENT oa
    JOIN ORDER_BASKET ob ON oa.BASKET_ID = ob.BASKET_ID
    WHERE ob.TABLE_ID = NEW.TABLE_ID AND ob.status = 'complete';

    -- Delete items from the completed baskets
    DELETE oi FROM ORDERED_ITEM oi
    JOIN ORDER_BASKET ob ON oi.BASKET_ID = ob.BASKET_ID
    WHERE ob.TABLE_ID = NEW.TABLE_ID AND ob.status = 'complete';

    -- Delete the completed baskets themselves
    DELETE FROM ORDER_BASKET
    WHERE TABLE_ID = NEW.TABLE_ID AND status = 'complete';

    -- Step 3: Check if any 'incomplete' baskets are *still* on the table
    -- (e.g., a new order was started before the old one was paid)
    SELECT COUNT(*) INTO v_incomplete_baskets
    FROM ORDER_BASKET
    WHERE TABLE_ID = NEW.TABLE_ID AND status = 'incomplete';

    -- Step 4: Update table status ONLY if no incomplete baskets remain.
    IF v_incomplete_baskets = 0 THEN
        UPDATE TABLE_INFO
        SET table_status = 'available'
        WHERE TABLE_ID = NEW.TABLE_ID;
    END IF;
    
END$$


-- ============================================================
-- TRIGGER 7: Prevent negative stock amounts
-- ============================================================
CREATE TRIGGER trg_menu_amount_nonneg
BEFORE UPDATE ON MENU
FOR EACH ROW
BEGIN
   IF NEW.amount < 0 THEN
       SIGNAL SQLSTATE '45000'
       SET MESSAGE_TEXT = 'Menu amount cannot be negative';
   END IF;
END$$


-- ============================================================
-- TRIGGER 8: Validate ordered item quantities on insert
-- ============================================================
DELIMITER $$

DROP TRIGGER IF EXISTS trg_qty_check_insert$$

CREATE TRIGGER trg_qty_check_insert
BEFORE INSERT ON ORDERED_ITEM
FOR EACH ROW
BEGIN
    DECLARE menu_stock INT;
    DECLARE menu_status VARCHAR(25);

    -- Get the current stock and status from the MENU table
    SELECT amount, `status` INTO menu_stock, menu_status
    FROM MENU
    WHERE MENU_ID = NEW.MENU_ID;

    -- 1. Check if quantity is a positive number
    IF NEW.amount <= 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Order quantity must be positive';

    -- 2. Check if the menu item is disabled (This is what you meant to do)
    ELSEIF menu_status = 'disabled' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'This menu item is not currently available';
    
    -- 3. Check if stock is tracked AND is insufficient
    ELSEIF menu_stock IS NOT NULL AND menu_stock < NEW.amount THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Insufficient stock for this menu item';
        
    END IF; 

    IF NEW.status IS NULL THEN
        SET NEW.status = 'received';
    END IF;
    
END$$

-- __________________________trigger in sync with amount____________________
DROP TRIGGER IF EXISTS trg_menu_amount_status_ins$$
CREATE TRIGGER trg_menu_amount_status_ins
BEFORE INSERT ON MENU
FOR EACH ROW
BEGIN
    IF NEW.amount IS NULL OR NEW.amount <= 0 THEN
        SET NEW.amount = 0;
        SET NEW.status = 'disabled';
    ELSE
        SET NEW.status = 'available';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_menu_amount_status_upd$$
CREATE TRIGGER trg_menu_amount_status_upd
BEFORE UPDATE ON MENU
FOR EACH ROW
BEGIN
    IF NEW.amount <= 0 THEN
        SET NEW.amount = 0;
        SET NEW.status = 'disabled';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_table_insert$$
CREATE TRIGGER trg_table_insert
BEFORE INSERT ON TABLE_INFO
FOR EACH ROW
BEGIN
    IF NEW.table_status IS NULL THEN
        SET NEW.table_status = 'available';
    END IF;
END$$

DELIMITER ;

-- Drop the old view first
drop trigger IF EXISTS trg_price_snapshot_on_order_delete;
drop trigger IF EXISTS trg_order_status_recompute_delete;
drop trigger IF EXISTS trg_qty_check_update;
drop trigger IF EXISTS trg_reservation_sync_status;