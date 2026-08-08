-- 小薇 AI 助理 · DISCERN 教练文化宪法 seed（123456789 完整体系）
-- 来源：教练技术九大工具体系（四特质·五原则·六信念 为核心，1/2/3/7/8/9 为完整底座）
-- 对应需求：2.3 教练文化宪法 DISCERN（四特质·五原则·六信念，全平台共享）
-- 信念5「言行一致言出必行」= 六信念第5条；原则3「承诺加行动引发支持」= 五原则第3条

SET NAMES utf8mb4;

ALTER TABLE `ch_discern_config`
  ADD COLUMN `extra` json DEFAULT NULL COMMENT '完整123456789体系（团队/态度/支柱/目的/范畴/领导力）' AFTER `six_beliefs`;

INSERT INTO `ch_discern_config`
  (`tenant_id`, `brand_name`, `voice_style`, `four_traits`, `five_principles`, `six_beliefs`, `extra`, `push_time`, `evening_time`, `streak_threshold`, `status`, `add_time`, `update_time`)
VALUES
  (1, '小薇', '知性温柔、精简、有温度、带点可爱灵气',
   JSON_ARRAY('热切渴望','绝对信心','有效行动','钢铁意志'),
   JSON_ARRAY(
     '基于理想，宣告一个团队，以目的行动和结果来验证团队',
     '团队赢，个人赢',
     '承诺加行动引发支持',
     '授权等于信任和支持，接受授权等于为结果负责',
     '任何怀疑顾虑，在第一时间与第一人沟通'
   ),
   JSON_ARRAY(
     '以我的愿景、承诺主宰我的心态及行动，而不是我的感觉、评估或批评',
     '100%时间，100%可能性',
     '若要如何，全凭自己',
     '领袖活出感召力，生命是一场感召的游戏',
     '言行一致，言出必行',
     '团队-团队参与者承诺一个共同理想'
   ),
   JSON_OBJECT(
     'one_team', '基于理想，宣告一个团队，以目的行动和结果来验证团队',
     'two_attitudes', JSON_ARRAY('成就','支持'),
     'three_pillars', JSON_ARRAY('Having（工具·技术）','Doing（能力·状态）','Being（教练心态）'),
     'seven_purposes', JSON_ARRAY(
       '挑战、突破、活出约誓中的自己',
       '创造生命中想要的成果',
       '想走好自己以后的人生',
       '挑战、冒险以前不做的事情',
       '重新做自己、自信有新的选择',
       '有勇气付出，提升身边的人',
       '有个强烈的欲望——锻炼'
     ),
     'eight_scope', JSON_ARRAY(
       '有清晰的理想和目的',
       '与成果的关系是：透过成果确定有效性',
       '愿意基于对承诺的要求所衍生的答案重新设计自己',
       '对承诺是坦诚和愿意冒险',
       '是有效感召者，带领和感召等于为他人开放可能性',
       '为他人创造卓越的标准',
       '介入环境于限制之中，将事情由不可能转化为可能性',
       '创造信任和尊重'
     ),
     'nine_leadership', JSON_ARRAY('激情','承诺','负责任','欣赏','感召','信任','共赢','付出','可能性')
   ),
   '09:00', '21:00', 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
  )
ON DUPLICATE KEY UPDATE
  `four_traits` = VALUES(`four_traits`),
  `five_principles` = VALUES(`five_principles`),
  `six_beliefs` = VALUES(`six_beliefs`),
  `extra` = VALUES(`extra`),
  `update_time` = UNIX_TIMESTAMP();
