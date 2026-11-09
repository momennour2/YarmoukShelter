-- Creates / resets the owner account (general manager).
-- Run AFTER migration_v5.sql (or schema.sql). Only a bcrypt hash is stored here – never the plain password.
INSERT INTO users(username,full_name,role,password_hash,is_active,must_change_password)
VALUES('yarmouk.owner','مالك النظام','general_manager','$2y$12$WHjXjEyxA/7eh0.836dmsuw9OT2QRsbmEY7ow85wr..F/z2ifzK5.',1,0)
ON DUPLICATE KEY UPDATE role='general_manager',password_hash=VALUES(password_hash),is_active=1,must_change_password=0;
