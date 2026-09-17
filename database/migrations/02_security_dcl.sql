-- database name = pos

CREATE USER IF NOT EXISTS Webemployees IDENTIFIED BY '<Your password>';
CREATE USER IF NOT EXISTS posadmin IDENTIFIED BY '<Your password>';

-- Give database level privileges to admin
GRANT ALL PRIVILEGES ON pos.* TO 'posadmin';

/*Give table level privileges to employee, 
 Noted: No ALL PRIVILEGES as No ALTER, INDEX, create VIEW privileges to any Employees
 */
GRANT SELECT				         ON pos.`EMPLOYEE` TO Webemployees;
GRANT SELECT, INSERT, UPDATE, DELETE ON pos.`CUSTOMER` TO Webemployees;
GRANT SELECT, INSERT, UPDATE, DELETE ON pos.`TABLE_INFO` TO Webemployees;
GRANT SELECT, INSERT, UPDATE		 ON pos.`MENU` TO Webemployees;
GRANT SELECT, INSERT 				 ON pos.`RECEIPT` TO Webemployees;
GRANT SELECT, INSERT, UPDATE, DELETE ON pos.`RESERVATION` TO Webemployees;
GRANT SELECT, INSERT, UPDATE(status) ON pos.`ORDER_BASKET` TO Webemployees;
GRANT SELECT, INSERT, UPDATE(status) ON pos.`ORDERED_ITEM` TO Webemployees;
GRANT SELECT, INSERT, DELETE 		 ON pos.`ORDER_ASSIGNMENT` TO Webemployees;
GRANT SELECT, INSERT 				 ON pos.`sales` TO Webemployees;

-- GRANT SELECT ON pos.`TABLE_INFO` TO 'Webcustomer';
-- GRANT SELECT ON pos.`MENU` TO 'Webcustomer';


-- _______________Grant permission for  view________________________
GRANT SELECT ON pos.v_kitchen_orders TO Webemployees;
GRANT SELECT ON pos.v_alltable_need_detail TO Webemployees;
GRANT SELECT ON pos.v_decrypted_customers TO Webemployees;
GRANT SELECT ON pos.v_get_all_future_reservations TO Webemployees;
GRANT SELECT ON pos.v_table_ordered_items TO Webemployees;

-- _______________Grant permission for procedure________________________
GRANT EXECUTE ON PROCEDURE pos.sp_insert_customers TO Webemployees;
GRANT EXECUTE ON PROCEDURE pos.sp_update_table_status TO Webemployees;


FLUSH PRIVILEGES;