-- 修复 ch_appointment.mode 类型：控制器/前端传字符串 'online'/'offline'，
-- 但列此前误设为 tinyint，导致预约写入报 SQLSTATE 1366 Incorrect integer value: 'online'
ALTER TABLE ch_appointment MODIFY COLUMN mode varchar(20) NOT NULL DEFAULT 'online' COMMENT '预约方式 online/offline';
