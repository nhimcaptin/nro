INSERT INTO `item_shop`
    (`tab_id`, `temp_id`, `is_new`, `is_sell`, `type_sell`, `cost`, `icon_spec`, `create_time`)
SELECT 18, 935, 1, 1, 0, 500000000, 0, NOW()
WHERE NOT EXISTS (
    SELECT 1
    FROM `item_shop`
    WHERE `tab_id` = 18 AND `temp_id` = 935
);

UPDATE `item_shop`
SET `is_new` = 1,
    `is_sell` = 1,
    `type_sell` = 0,
    `cost` = 500000000,
    `icon_spec` = 0
WHERE `tab_id` = 18 AND `temp_id` = 935;


INSERT INTO `item_shop_option` (`item_shop_id`, `option_id`, `param`)
SELECT `i`.`id`, 31, 1
FROM `item_shop` AS `i`
WHERE `i`.`tab_id` = 18
  AND `i`.`temp_id` = 935
  AND NOT EXISTS (
      SELECT 1
      FROM `item_shop_option` AS `o`
      WHERE `o`.`item_shop_id` = `i`.`id`
        AND `o`.`option_id` = 31
  );

UPDATE `item_shop_option` AS `o`
JOIN `item_shop` AS `i` ON `i`.`id` = `o`.`item_shop_id`
SET `o`.`param` = 1
WHERE `i`.`tab_id` = 18
  AND `i`.`temp_id` = 935
  AND `o`.`option_id` = 31;
