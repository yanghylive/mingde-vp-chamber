-- 分账下线（单方收款）：移除分账结算子系统 5 张表。
-- 决策见 docs/分账下线实施方案.md。生产执行前请先 mysqldump 归档 5 张表。
DROP TABLE IF EXISTS `ch_settlement_rule`;
DROP TABLE IF EXISTS `ch_settlement`;
DROP TABLE IF EXISTS `ch_settlement_detail`;
DROP TABLE IF EXISTS `ch_payout_record`;
DROP TABLE IF EXISTS `ch_settlement_balance`;
-- 清理已失效的分账授权行（对应控制器已删除，不再有任何 assertPermission 引用它们）
DELETE FROM `ch_admin_permission` WHERE `permission` LIKE 'chamber.settlement.%';
