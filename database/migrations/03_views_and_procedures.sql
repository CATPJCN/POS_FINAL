USE pos;

/* ===========================================================================================
                                    VIEW
   ===========================================================================================
*/
-- ___________________look at table that is occupied or still has basket_____________
DROP VIEW IF EXISTS v_table_ordered_items;
CREATE VIEW v_table_ordered_items AS
SELECT
    t.TABLE_ID as tabID,
    m.name as Ordered_item,
    item.status as item_Status
FROM TABLE_INFO t
JOIN ORDER_BASKET b ON b.TABLE_ID = t.TABLE_ID
LEFT JOIN ordered_item item ON item.BASKET_ID = b.BASKET_ID
LEFT JOIN menu m ON m.MENU_ID = item.MENU_ID
ORDER BY t.TABLE_ID;

-- _______________________View readable customer detail____________________
DROP VIEW IF EXISTS v_decrypted_customers;
CREATE VIEW v_decrypted_customers AS
SELECT
    CUSTOMER_ID,
    -- We CAST the decrypted data to CHAR
    -- We also ALIAS the columns to lowercase to match what PHP expects
    CAST(AES_DECRYPT(First_Name, SHA1('FNamekey')) AS CHAR) AS First_Name,
    CAST(AES_DECRYPT(Last_Name, SHA1('LNamekey')) AS CHAR) AS Last_Name,
    CAST(AES_DECRYPT(allergies, SHA1('allergieskey')) AS CHAR) AS allergies,
    CAST(AES_DECRYPT(phone, SHA1('phonekey')) AS CHAR) AS Phone,
    CAST(AES_DECRYPT(email, SHA1('emailkey')) AS CHAR) AS email
FROM CUSTOMER;

-- __________________________View Every Menu that is not yet done____________________
DROP VIEW IF EXISTS v_kitchen_orders;

CREATE VIEW v_kitchen_orders AS
SELECT 
    ob.TABLE_ID as table_id, 
    SUM(m.price * oi.amount) as total, 
    GROUP_CONCAT(m.name SEPARATOR ', ') as items_summary 
FROM ORDERED_ITEM oi
JOIN MENU m ON oi.MENU_ID = m.MENU_ID
JOIN ORDER_BASKET ob ON oi.BASKET_ID = ob.BASKET_ID
WHERE oi.status IN ('received', 'preparing')
GROUP BY ob.TABLE_ID;

-- ____________View give table detail, current bill price, staff, reservation with customer name____________________
DROP VIEW IF EXISTS v_alltable_need_detail;
CREATE VIEW v_alltable_need_detail AS
SELECT
	t.TABLE_ID AS id,
	t.number_of_seat AS seats,
	t.table_status AS status,
	od.total AS total,
	od.server AS server,
	c.First_Name as customer,
	r.number_of_customer AS party,
	r.reservation_time AS reservationAt
    
FROM TABLE_INFO t

-- 1. Join for OCCUPIED data (pre-aggregated subquery)
LEFT JOIN (
    SELECT
        ob.TABLE_ID,
        SUM(ob.total_price) as total,
        GROUP_CONCAT(DISTINCT e.First_Name SEPARATOR ', ') as server
    FROM ORDER_BASKET ob
    LEFT JOIN ORDER_ASSIGNMENT oa ON ob.BASKET_ID = oa.BASKET_ID
    LEFT JOIN EMPLOYEE e ON oa.EMPLOYEE_ID = e.EMPLOYEE_ID
    -- Sum all active/unpaid baskets
    WHERE ob.status IN ('incomplete', 'complete')
    GROUP BY ob.TABLE_ID
) AS od ON t.TABLE_ID = od.TABLE_ID

-- 2. Joins for RESERVED data This prevents duplicate rows.
LEFT JOIN RESERVATION r ON t.TABLE_ID = r.TABLE_ID AND t.table_status = 'reserved' 
            AND CURRENT_TIMESTAMP BETWEEN DATE_SUB(reservation_time, INTERVAL 10 MINUTE)
                AND DATE_ADD(reservation_time, INTERVAL 10 MINUTE)
LEFT JOIN v_decrypted_customers c ON r.CUSTOMER_ID = c.CUSTOMER_ID
ORDER BY t.TABLE_ID;

-- ________ VIEW upcoming reservation______________________
DROP VIEW IF EXISTS v_get_all_future_reservations;
CREATE VIEW v_get_all_future_reservations AS
SELECT 
    R.RESERVATION_ID, 
    R.TABLE_ID, 
    R.number_of_customer, 
    R.reservation_time,
    C.First_Name,
    C.Last_Name,
    C.phone,
    T.number_of_seat
FROM RESERVATION R
JOIN v_decrypted_customers C ON R.CUSTOMER_ID = C.CUSTOMER_ID
JOIN TABLE_INFO T ON R.TABLE_ID = T.TABLE_ID
WHERE R.reservation_time >= NOW()
ORDER BY R.reservation_time ASC;

/* ===========================================================================================
                                    PROCEDURE
   ===========================================================================================
*/


-- _________________Procedure insert into customer_____________
-- In 00_pos_procedure.sql
DELIMITER $$

DROP PROCEDURE IF EXISTS sp_insert_customers$$
CREATE PROCEDURE sp_insert_customers(
    IN First_Name VARBINARY(255),
    IN Last_Name VARBINARY(255),
    IN allergies VARBINARY(255),
    IN phone VARBINARY(255),
    IN email VARBINARY(255),
    OUT new_id_out INT  -- <-- 1. ADD THIS LINE
)
BEGIN
INSERT INTO CUSTOMER (First_Name, Last_Name, allergies, phone, email) VALUES
    (AES_ENCRYPT(First_Name, SHA1('FNamekey')),
    AES_ENCRYPT(Last_Name, SHA1('LNamekey')),
    AES_ENCRYPT(allergies, SHA1('allergieskey')),
    AES_ENCRYPT(phone, SHA1('phonekey')),
    AES_ENCRYPT(email, SHA1('emailkey')));

    SET new_id_out = LAST_INSERT_ID(); -- <-- 2. ADD THIS LINE
END$$

DELIMITER ;

-- _________________Procedure check table status_____________
-- table_status 'available', 'reserved', 'occupied' DEFAULT 'available' 
DELIMITER $$
DROP PROCEDURE IF EXISTS sp_update_table_status$$
CREATE PROCEDURE `sp_update_table_status`()
BEGIN
    DELETE FROM RESERVATION
    WHERE NOW() > DATE_ADD(reservation_time, INTERVAL 3 MINUTE);

    UPDATE TABLE_INFO TI
    JOIN (
        SELECT DISTINCT TABLE_ID
        FROM RESERVATION
        WHERE
            NOW() BETWEEN DATE_SUB(reservation_time, INTERVAL 3 MINUTE) 
                        AND DATE_ADD(reservation_time, INTERVAL 3 MINUTE)
    ) AS R_NOW ON TI.TABLE_ID = R_NOW.TABLE_ID
    SET TI.table_status = 'reserved'
    WHERE TI.table_status = 'available';

    UPDATE TABLE_INFO TI
    LEFT JOIN (
        SELECT DISTINCT TABLE_ID
        FROM RESERVATION
        WHERE
            NOW() BETWEEN DATE_SUB(reservation_time, INTERVAL 3 MINUTE) 
                        AND DATE_ADD(reservation_time, INTERVAL 3 MINUTE)
    ) AS R_ACTIVE ON TI.TABLE_ID = R_ACTIVE.TABLE_ID
    
    SET TI.table_status = 'available'
    WHERE TI.table_status = 'reserved'
      AND R_ACTIVE.TABLE_ID IS NULL;

END$$
DELIMITER ;
