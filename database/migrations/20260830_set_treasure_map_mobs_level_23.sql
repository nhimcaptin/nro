-- Đặt level quái trong Bản đồ kho báu (map 135-138) thành 23.
-- Cấu trúc mỗi quái trong map_template.mobs: [temp_id, level, hp, x, y].

UPDATE `map_template`
SET `mobs` = REPLACE(`mobs`, '[34,8,', '[34,23,')
WHERE `id` IN (135, 136, 137, 138);

UPDATE `map_template`
SET `mobs` = REPLACE(`mobs`, '[35,8,', '[35,23,')
WHERE `id` IN (135, 136, 137, 138);

UPDATE `map_template`
SET `mobs` = REPLACE(`mobs`, '[36,8,', '[36,23,')
WHERE `id` IN (135, 136, 137, 138);

UPDATE `map_template`
SET `mobs` = REPLACE(`mobs`, '[37,8,', '[37,23,')
WHERE `id` IN (135, 136, 137, 138);

UPDATE `map_template`
SET `mobs` = REPLACE(`mobs`, '[38,8,', '[38,23,')
WHERE `id` IN (135, 136, 137, 138);

UPDATE `map_template`
SET `mobs` = REPLACE(`mobs`, '[71,0,', '[71,23,')
WHERE `id` IN (135, 136, 137, 138);

UPDATE `map_template`
SET `mobs` = REPLACE(`mobs`, '[72,0,', '[72,23,')
WHERE `id` IN (135, 136, 137, 138);

