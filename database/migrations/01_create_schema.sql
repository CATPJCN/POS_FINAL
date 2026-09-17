USE pos;

-- 1. EMPLOYEE
CREATE TABLE EMPLOYEE (
    EMPLOYEE_ID INT(5) PRIMARY KEY AUTO_INCREMENT,
    -- Variables
    First_Name VARCHAR(255) NOT NULL,
    Last_Name VARCHAR(255) NOT NULL,
    password VARBINARY(255) NOT NULL,
    role VARCHAR(15) NOT NULL
) ENGINE=InnoDB;

-- 2. CUSTOMER
CREATE TABLE CUSTOMER (
    CUSTOMER_ID INT(10) PRIMARY KEY AUTO_INCREMENT,
    -- Variables
    First_Name VARBINARY(255) NOT NULL,
    Last_Name VARBINARY(255) NOT NULL,
    allergies VARBINARY(255),
    phone VARBINARY(255) NOT NULL UNIQUE,
    email VARBINARY(255)
) ENGINE=InnoDB;

-- 3. TABLE_INFO
CREATE TABLE TABLE_INFO (
    TABLE_ID INT(5) PRIMARY KEY AUTO_INCREMENT,
    -- Variables
    number_of_seat INT(5) NOT NULL,
    table_status ENUM('available', 'reserved', 'occupied') NOT NULL
) ENGINE=InnoDB;

-- 4. MENU
CREATE TABLE MENU (
    MENU_ID INT(5) PRIMARY KEY AUTO_INCREMENT,
    -- Variables
    name VARCHAR(50) NOT NULL UNIQUE,
    price DECIMAL(6, 2) NOT NULL,
    amount INT,
    status ENUM('available','disabled') NOT NULL,
    type ENUM('beverages', 'main courses', 'appetizers'),
    ingredient VARCHAR(255)
) ENGINE=InnoDB;

-- 5. ORDER_BASKET
CREATE TABLE ORDER_BASKET (
    BASKET_ID INT(5) PRIMARY KEY AUTO_INCREMENT,
    -- Foreign key column
    TABLE_ID INT(5) NOT NULL,  
    -- Variables
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP(),
    comment VARCHAR(255),
    total_price DECIMAL(6, 2),
    status ENUM('incomplete', 'complete') NOT NULL
) ENGINE=InnoDB;

-- 6. ORDERED_ITEM
CREATE TABLE ORDERED_ITEM (
	-- Foreign key column
    BASKET_ID INT(5) NOT NULL,
    MENU_ID INT(5) NOT NULL,
    PRIMARY KEY (BASKET_ID, MENU_ID),
    -- Variables
    amount INT(5) NOT NULL,
    status ENUM('received', 'preparing', 'finished') NOT NULL
) ENGINE=InnoDB; 

-- 7. RECEIPT
CREATE TABLE RECEIPT (
    RECEIPT_ID INT(5) PRIMARY KEY AUTO_INCREMENT,
    -- Foreign key column
    TABLE_ID INT(5) NOT NULL,
	-- Variables
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP(),
    paid_amount DECIMAL(8, 2) NOT NULL,
    payment_method ENUM('cash', 'card', 'debit', 'credit') NOT NULL
) ENGINE=InnoDB;

-- 8. RESERVATION
CREATE TABLE RESERVATION (
    RESERVATION_ID INT(5) PRIMARY KEY AUTO_INCREMENT,
    -- Foreign key column
    TABLE_ID INT(5) NOT NULL,
    CUSTOMER_ID INT(10) NOT NULL,
    -- Variables
    number_of_customer INT(5) NOT NULL,
    reservation_time DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP()
) ENGINE=InnoDB;

-- 9. ORDER_ASSIGNMENT (Associates an employee with a specific basket/order)
CREATE TABLE ORDER_ASSIGNMENT (
    BASKET_ID INT(5) NOT NULL,
    EMPLOYEE_ID INT(5) NOT NULL,
    PRIMARY KEY (BASKET_ID, EMPLOYEE_ID)
) ENGINE=InnoDB;

-- Add Foreign key_________________________________________

-- Add Foreign of TABLE ORDER_BASKET
ALTER TABLE ORDER_BASKET
ADD CONSTRAINT fk_ORDER_BASKET_table
	FOREIGN KEY (TABLE_ID)
	REFERENCES TABLE_INFO(TABLE_ID)
    ON DELETE NO ACTION 
    ON UPDATE CASCADE;

-- Add Foreign of TABLE ORDERED_ITEM
ALTER TABLE ORDERED_ITEM 
ADD CONSTRAINT fk_ORDERED_ITEM_basket
	FOREIGN KEY (BASKET_ID)
	REFERENCES ORDER_BASKET(BASKET_ID)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
    
ADD CONSTRAINT fk_ORDERED_ITEM_menu
	FOREIGN KEY (MENU_ID)
	REFERENCES MENU(MENU_ID)
    ON DELETE RESTRICT
    ON UPDATE CASCADE;

-- Add Foreign of TABLE RECEIPT
ALTER TABLE RECEIPT
ADD CONSTRAINT fk_RECEIPT_table
	FOREIGN KEY (TABLE_ID)
	REFERENCES TABLE_INFO(TABLE_ID)
	ON DELETE NO ACTION 
    ON UPDATE CASCADE;

-- Add Foreign of TABLE RESERVATION
ALTER TABLE RESERVATION
ADD CONSTRAINT fk_RESERVATION_table
	FOREIGN KEY (TABLE_ID)
	REFERENCES TABLE_INFO(TABLE_ID)
	ON DELETE NO ACTION 
    ON UPDATE CASCADE,
	
ADD CONSTRAINT fk_RESERVATION_customer	    
    FOREIGN KEY (CUSTOMER_ID) 
    REFERENCES CUSTOMER(CUSTOMER_ID)
    ON DELETE RESTRICT
    ON UPDATE CASCADE;

-- Add Foreign of TABLE ORDER_ASSIGNMENT
ALTER TABLE ORDER_ASSIGNMENT
ADD CONSTRAINT fk_ORDER_ASSIGNMENT_basket
	FOREIGN KEY (BASKET_ID) 
	REFERENCES ORDER_BASKET(BASKET_ID)
	ON DELETE RESTRICT
	ON UPDATE CASCADE,
	
ADD CONSTRAINT fk_ORDER_ASSIGNMENT_employee
    FOREIGN KEY (EMPLOYEE_ID) 
    REFERENCES EMPLOYEE(EMPLOYEE_ID)
    ON DELETE NO ACTION
    ON UPDATE CASCADE;


-- 2) Initialize existing rows: derive status from amount
UPDATE MENU
SET status = CASE
    WHEN amount IS NULL OR amount <= 0 THEN 'disabled'
    ELSE 'available'
END;

-- _____________________________For report/ sales____________________________
-- This table creates a permanent, historical record of every item sold.
CREATE TABLE SALES (
    RECEIPT_ITEM_ID INT(10) PRIMARY KEY AUTO_INCREMENT,
    RECEIPT_ID INT(5) NOT NULL,
    MENU_ID INT(5) NOT NULL,
    amount INT(5) NOT NULL,
    price_at_sale DECIMAL(6, 2) NOT NULL, -- The price when it was sold
    item_total DECIMAL(8, 2) NOT NULL,  -- (amount * price_at_sale)
    created_at DATETIME,

    -- Create foreign key constraints
    CONSTRAINT fk_RECEIPT_ITEM_receipt
        FOREIGN KEY (RECEIPT_ID)
        REFERENCES RECEIPT(RECEIPT_ID)
        ON DELETE RESTRICT, -- Prevent deleting a receipt if it has items
    
    CONSTRAINT fk_RECEIPT_ITEM_menu
        FOREIGN KEY (MENU_ID)
        REFERENCES MENU(MENU_ID)
        ON DELETE NO ACTION -- Allow menu item to be deleted (or set to INACTIVE)
) ENGINE=InnoDB;
