USE pos;

-- (Not NULL, Not NULL, NULL ok, Not NULL unique, NULL ok)
CALL sp_insert_customers('John', 'Doe', 'Peanuts', '555-0101', 'john.doe@example.com', @new_id);
CALL sp_insert_customers('Jane', 'Smith', NULL, '555-0102', 'jane.smith@example.com', @new_id);
CALL sp_insert_customers('Robert', 'Brown', 'Shellfish', '555-0103', NULL, @new_id);
CALL sp_insert_customers('Emily', 'Davis', 'Gluten', '555-0104', 'emily.d@example.net', @new_id);
CALL sp_insert_customers('Michael', 'Wilson', NULL, '555-0105', 'm.wilson@example.org', @new_id);
CALL sp_insert_customers('Sarah', 'Johnson', 'Pollen', '555-0106', 's.johnson@example.com', @new_id);
CALL sp_insert_customers('David', 'Lee', NULL, '555-0107', NULL, @new_id);
CALL sp_insert_customers('Laura', 'White', 'Dairy', '555-0108', 'laura.white@example.net', @new_id);
CALL sp_insert_customers('James', 'Harris', 'Peanuts, Shellfis', '555-0109', NULL, @new_id);
CALL sp_insert_customers('Linda', 'Clark', NULL, '555-0110', 'linda.clark@example.org', @new_id);
-- 1. Populate tables with no dependencies

-- Insert 10 tables
INSERT INTO TABLE_INFO (number_of_seat,table_status) VALUES
(4, 'occupied'),   -- Assumes TABLE_ID 1
(4, 'occupied'),   -- Assumes TABLE_ID 2
(2, 'occupied'),   -- Assumes TABLE_ID 3
(2, 'occupied'),    -- Assumes TABLE_ID 4
(6, 'occupied'),    -- Assumes TABLE_ID 5
(6, 'occupied'),    -- Assumes TABLE_ID 6
(8, 'occupied'),   -- Assumes TABLE_ID 7
(8, 'occupied'),   -- Assumes TABLE_ID 8
(4, 'occupied'),    -- Assumes TABLE_ID 9
(6, 'occupied');   -- Assumes TABLE_ID 10

-- Insert 10 menu items status ENUM('available','disabled') NULL default variable
INSERT INTO MENU (name, price, amount, status, type, ingredient) VALUES
('Classic Burger', 15.99, 100, 'available',  'main courses', 'Beef patty, lettuce, tomato, cheese, bun'),  -- Assumes MENU_ID 1
('Caesar Salad', 12.50, 50, 'available', 'appetizers', 'Romaine lettuce, croutons, parmesan'),         -- Assumes MENU_ID 2
('Spaghetti Carbonara', 18.00, 75, 'available', 'main courses', 'Spaghetti, eggs, pancetta, pecorino'), -- Assumes MENU_ID 3
('Margherita Pizza', 14.00, 60, 'available', 'main courses', 'Dough, tomato, mozzarella, basil'),      -- Assumes MENU_ID 4
('Chicken Wings', 13.50, 80, 'available', 'appetizers', 'Chicken, hot sauce, blue cheese'),          -- Assumes MENU_ID 5
('Bruschetta', 8.00, 60, 'available', 'appetizers', 'Toasted bread, tomatoes, garlic, basil'),       -- Assumes MENU_ID 6
('Coke', 3.50, 200, 'disabled', 'beverages', 'Carbonated water, sugar, caffeine'),                -- Assumes MENU_ID 7
('Iced Tea', 3.00, 150,'available', 'beverages', 'Tea, water, sugar, lemon'),                       -- Assumes MENU_ID 8
('Mineral Water', 2.50, 200,'disabled', 'beverages', 'Water'),                                     -- Assumes MENU_ID 9
('Filet Mignon', 29.50, 30, 'disabled', 'main courses', 'Beef tenderloin, potato, asparagus');     -- Assumes MENU_ID 10

-- ___________________Add employee via 00_0register.php first!___________
INSERT INTO `employee` (`EMPLOYEE_ID`, `First_Name`, `Last_Name`, `password`, `role`) VALUES
(1, 'Sarah', 'Miller', 0x2432792431302454664c716b4f536f5a68473151557a7248457653424f77692e795a496e496877394a5476347438594152555a784e49543130345057, 'manager'),
(2, 'Jane', 'Doe', 0x24327924313024756d6d6c704633762f7961693768475a67684d462e752f4268784836324b2f38677230544a734d66546e35474263562e756b636357, 'manager'),
(3, 'John', 'Pace', 0x243279243130246356486d326b5674616946644e725648496a6757594f4b6d452e39574a715846384f64736879624d78573070783469756a635a4161, 'staff'),
(4, 'Alice', 'Johnson', 0x2432792431302473495a75675968456e367a4b374c46376574636a502e386867764d336d794675676946504351515a527a5773412f6f4a30644a5557, 'staff'),
(5, 'Robert', 'Brown', 0x243279243130246e384a376e3367343755427974524376395a386154756a6a4d546d664446696c655639753771416e6c786e544774352f3859773171, 'staff'),
(6, 'Emily', 'Davis', 0x24327924313024754d557534333563544a626742706d4878497362442e556f4a626532785a3549304874486a61747046673262772e755951596b4f43, 'staff'),
(7, 'Michael', 'Wilson', 0x2432792431302462416a6232394842414241664d596777445853415a2e663437524c324b6a566a37493772626d65434b68797068784c6d57316f6465, 'staff'),
(8, 'David', 'Lee', 0x24327924313024316e2f6b614c486d6e42716839716a4d54453365682e3571796b552e377462307757494e38577174664d70442e497a724452526a65, 'staff'),
(9, 'Laura', 'Garcia', 0x243279243130244a597a346c6e61786634472e6b54504463426339304f615853647a464944506366436b7635655267524c6245745632433662437457, 'staff'),
(10, 'James', 'Rodriguez', 0x243279243130247846354f52626f4254756d594859396a466b4b4b684f56545632344f4c7450656142577866313063594a39345854566239704c6447, 'staff');

-- Insert 10 reservations
INSERT INTO RESERVATION (TABLE_ID, CUSTOMER_ID, number_of_customer, reservation_time) VALUES
(6, 1, 6, '2025-12-22 19:00:00' ),
(9, 2, 4, '2025-12-20 20:00:00' ),
(1, 3, 4, '2025-12-13 18:00:00' ),
(2, 4, 3, '2025-12-13 18:30:00' ),
(3, 5, 2, '2025-12-13 19:00:00' ),
(7, 6, 8, '2025-12-13 20:00:00' ),
(8, 7, 7, '2025-12-14 17:30:00' ),
(10, 8, 6, '2025-12-14 18:00:00' ),
(1, 9, 2, '2025-12-14 19:00:00' );

-- ============To get a receipt, every item of every basket of that table must be complete===
-- ========== DEMO =============================================

-- Insert 12 Order Baskets
-- Baskets for "Completed" tables (1-5)
INSERT INTO ORDER_BASKET (TABLE_ID, comment, total_price, status) VALUES
(1, 'Paid - first round', 0, 'complete'),  -- Assumes BASKET_ID 1
(1, 'Paid - dessert round', 0, 'complete'), -- Assumes BASKET_ID 2 (Multi-basket table)
(2, 'Paid - for receipt 2', 0, 'complete'), -- Assumes BASKET_ID 3
(3, 'Paid - for receipt 3', 0, 'complete'), -- Assumes BASKET_ID 4
(4, 'Paid - for receipt 4', 0, 'complete'), -- Assumes BASKET_ID 5
(5, 'Paid - for receipt 5', 0, 'complete'), -- Assumes BASKET_ID 6
-- Baskets for "Incomplete" tables (6-10)
(6, 'Table 6 order', 0, 'incomplete'),           -- Assumes BASKET_ID 7
(7, 'Drinks order', 0, 'incomplete'),            -- Assumes BASKET_ID 8
(7, 'Food order, nut allergy', 0, 'incomplete'), -- Assumes BASKET_ID 9 (Multi-basket table)
(8, 'Table 8 order', 0, 'incomplete'),           -- Assumes BASKET_ID 10
(9, 'For takeout', 0, 'incomplete'),             -- Assumes BASKET_ID 11
(10, 'Requesting extra spicy', 0, 'incomplete'); -- Assumes BASKET_ID 12

-- =========== Part A: Items for Completed Baskets (Tables 1-5)
-- For Basket 1 (Table 1)
INSERT INTO ORDERED_ITEM (BASKET_ID, MENU_ID, amount, status) VALUES
(1, 1, 2, 'finished'); -- 2x Burger
-- For Basket 2 (Table 1)
INSERT INTO ORDERED_ITEM (BASKET_ID, MENU_ID, amount, status) VALUES
(2, 7, 1, 'finished'); -- 1x Cheesecake

-- For Basket 3 (Table 2)
INSERT INTO ORDERED_ITEM (BASKET_ID, MENU_ID, amount, status) VALUES
(3, 4, 1, 'finished'), -- 1x Pizza
(3, 5, 1, 'finished'); -- 1x Chicken Wings

-- For Basket 4 (Table 3)
INSERT INTO ORDERED_ITEM (BASKET_ID, MENU_ID, amount, status) VALUES
(4, 2, 1, 'finished'); -- 1x Caesar Salad

-- For Basket 5 (Table 4)
INSERT INTO ORDERED_ITEM (BASKET_ID, MENU_ID, amount, status) VALUES
(5, 10, 1, 'finished'), -- 1x Filet Mignon
(5, 9, 1, 'finished'); -- 1x Wine

-- For Basket 6 (Table 5)
INSERT INTO ORDERED_ITEM (BASKET_ID, MENU_ID, amount, status) VALUES
(6, 3, 1, 'finished'); -- 1x Carbonara

-- =========== Items for Incomplete Baskets (Tables 6-10)
-- ==============================================================

-- For Basket 7 (Table 6)
INSERT INTO ORDERED_ITEM (BASKET_ID, MENU_ID, amount, status) VALUES
(7, 1, 1, 'preparing'); -- Not finished

-- For Basket 8 (Table 7)
INSERT INTO ORDERED_ITEM (BASKET_ID, MENU_ID, amount, status) VALUES
(8, 8, 2, 'finished'); -- 2x Iced Tea (This basket is done)
-- For Basket 9 (Table 7)
INSERT INTO ORDERED_ITEM (BASKET_ID, MENU_ID, amount, status) VALUES
(9, 3, 1, 'received'); -- 1x Carbonara (This item makes all of Table 7 incomplete)

-- For Basket 10 (Table 8)
INSERT INTO ORDERED_ITEM (BASKET_ID, MENU_ID, amount, status) VALUES
(10, 5, 2, 'preparing'); -- Not finished

-- For Basket 11 (Table 9)
INSERT INTO ORDERED_ITEM (BASKET_ID, MENU_ID, amount, status) VALUES
(11, 4, 1, 'finished'), 
(11, 8, 1, 'received'); -- This one makes the basket incomplete

-- For Basket 12 (Table 10)
INSERT INTO ORDERED_ITEM (BASKET_ID, MENU_ID, amount, status) VALUES
(12, 1, 1, 'received'); -- Not finished


-- =========== Insert 5 Receipts with specific date requirements
-- ==============================================================

-- Insert 5 Receipts (Edited with WEEK and MONTH intervals)
-- (Assuming CURRENT_TIMESTAMP is 2025-11-16)

-- Receipt 1 (Table 1): Today (Nov 16, 2025)
INSERT INTO RECEIPT (TABLE_ID, created_at, paid_amount, payment_method) VALUES
(1, CURRENT_TIMESTAMP, NULL, 'credit'); 

-- Receipt 2 (Table 2): Yesterday (Nov 15, 2025)
INSERT INTO RECEIPT (TABLE_ID, created_at, paid_amount, payment_method) VALUES
(2, DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY), NULL, 'cash');

-- Receipt 3 (Table 3): This Week (not today/yesterday) -> (e.g., Nov 13)
INSERT INTO RECEIPT (TABLE_ID, created_at, paid_amount, payment_method) VALUES
(3, DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 3 DAY), NULL, 'card');

-- Receipt 4 (Table 4): This Month (not this week) -> (e.g., Nov 2)
INSERT INTO RECEIPT (TABLE_ID, created_at, paid_amount, payment_method) VALUES
(4, DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 WEEK), NULL, 'cash');

-- Receipt 5 (Table 5): Not This Month -> (e.g., Oct 16)
INSERT INTO RECEIPT (TABLE_ID, created_at, paid_amount, payment_method) VALUES
(5, DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MONTH), NULL, 'debit');

