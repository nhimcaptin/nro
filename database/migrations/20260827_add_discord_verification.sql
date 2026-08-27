-- Run once on an existing database before deploying this feature.
ALTER TABLE `account`
  ADD COLUMN `discord_id` varchar(32) DEFAULT NULL AFTER `active`,
  ADD KEY `idx_account_discord_id` (`discord_id`);

CREATE TABLE `discord_identity` (
  `discord_id` varchar(32) NOT NULL,
  `create_time` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`discord_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
