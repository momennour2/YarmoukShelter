-- Yarmouk Shelter: allow repeated identity/transaction rows without deleting any data.
-- Select the existing database in phpMyAdmin before running this migration.
SET @db_name = DATABASE();

-- Remove the unique identity index from profiles, if it exists.
SET @idx_profiles = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='profiles' AND INDEX_NAME='uq_profiles_national_id'
);
SET @ddl_profiles = IF(@idx_profiles > 0, 'ALTER TABLE profiles DROP INDEX uq_profiles_national_id', 'SELECT 1');
PREPARE stmt_profiles FROM @ddl_profiles;
EXECUTE stmt_profiles;
DEALLOCATE PREPARE stmt_profiles;

-- Remove unique item-code restriction so repeated stock rows can be retained too.
SET @idx_stock = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='warehouse_items' AND INDEX_NAME='item_code'
);
SET @ddl_stock = IF(@idx_stock > 0, 'ALTER TABLE warehouse_items DROP INDEX item_code', 'SELECT 1');
PREPARE stmt_stock FROM @ddl_stock;
EXECUTE stmt_stock;
DEALLOCATE PREPARE stmt_stock;
