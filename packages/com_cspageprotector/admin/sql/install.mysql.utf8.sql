CREATE TABLE IF NOT EXISTS `#__cspageprotector_log` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `created`      DATETIME      NOT NULL,
    `event`        VARCHAR(20)   NOT NULL DEFAULT '',
    `ip`           VARCHAR(45)   NOT NULL DEFAULT '',
    `user_agent`   VARCHAR(512)  NOT NULL DEFAULT '',
    `url`          VARCHAR(2048) NOT NULL DEFAULT '',
    `menu_item_id` INT UNSIGNED  NOT NULL DEFAULT 0,
    `captcha`      VARCHAR(50)   NOT NULL DEFAULT '',
    `details`      VARCHAR(1024) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created`),
    KEY `idx_event_created` (`event`, `created`),
    KEY `idx_ip` (`ip`),
    KEY `idx_menu_item` (`menu_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
