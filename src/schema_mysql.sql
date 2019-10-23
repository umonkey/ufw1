-- Background task queue.
CREATE TABLE IF NOT EXISTS `taskq` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
	`added` DATETIME NOT NULL,
    `priority` INTEGER NOT NULL,
    `payload` MEDIUMBLOB NOT NULL,
	PRIMARY KEY(`id`),
	KEY(`added`),
	KEY(`priority`)
);
