CREATE TABLE IF NOT EXISTS `accounts` (
    `id` INTEGER PRIMARY KEY,
    `login` TEXT NOT NULL,
    `password` TEXT NOT NULL,
    `enabled` INTEGER NOT NULL,
    `last_login` TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS IDX_accounts_login ON accounts (login);
CREATE INDEX IF NOT EXISTS IDX_accounts_last_login ON accounts (last_login);

CREATE TABLE IF NOT EXISTS `sessions` (
    `id` TEXT NOT NULL,
    `updated` TEXT NOT NULL,
    `data` BLOB NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS IDX_sessions_id ON sessions (id);

CREATE TABLE IF NOT EXISTS `pages` (
    `id` INTEGER PRIMARY KEY,
    `name` TEXT,
    `source` TEXT,
    `html` TEXT,
    `created` INTEGER,
    `updated` INTEGER
);

CREATE UNIQUE INDEX IF NOT EXISTS `IDX_pages_name` ON `pages` (`name`);


-- Search index table.
CREATE VIRTUAL TABLE IF NOT EXISTS `search` USING fts5 (key UNINDEXED, meta UNINDEXED, title, body);


-- A table to store edit history.
-- Managed by \Wiki\Database::updatePage, see src/Wiki/Database.php
CREATE TABLE IF NOT EXISTS `history` (
    `id` INTEGER PRIMARY KEY,
    `name` TEXT,
    `source` TEXT,
    `created` INTEGER
);
CREATE INDEX IF NOT EXISTS `IDX_history_name` ON `history` (`name`);
CREATE INDEX IF NOT EXISTS `IDX_history_created` ON `history` (`created`);


-- Backlinks.
CREATE TABLE IF NOT EXISTS `backlinks` (
    `page_id` INTEGER,
    `link` TEXT
);
CREATE INDEX IF NOT EXISTS `IDX_backlinks_page_id` ON `backlinks` (`page_id`);
CREATE INDEX IF NOT EXISTS `IDX_backlinks_link` ON `backlinks` (`link`);


-- Files are stored in a table.
-- They have a generated file name, which conists from server fingerprint,
-- file fingerprint and upload time, kind of like UUID.
CREATE TABLE IF NOT EXISTS `files` (
    `id` INTEGER NOT NULL PRIMARY KEY,      -- local file id, for maintenance
    `name` TEXT,                            -- generated file name, for accessing
    `mime_type` TEXT,                       -- mime type
    `kind` TEXT,                            -- video, photo, other
    `length` INTEGER,                       -- byte size
    `created` DATETIME,                     -- file creation date, e.g. from exif
    `uploaded` DATETIME,                    -- file upload date
    `body` BLOB,                            -- file contents
    `hash` TEXT                             -- file contents hash, for ETag etc.
);

CREATE INDEX IF NOT EXISTS `IDX_files_name` ON `files` (`name`);
CREATE INDEX IF NOT EXISTS `IDX_files_uploaded` ON `files` (`created`);
CREATE INDEX IF NOT EXISTS `IDX_files_hash` ON `files` (`hash`);


-- Background task queue.
-- Tasks are scheduled here, usually as urls like /task/foo-bar,
-- and executed directly by a queue manager or by cron, all at once.
CREATE TABLE IF NOT EXISTS `tasks` (
    `id` INTEGER NOT NULL PRIMARY KEY,
    `url` TEXT,
    `priority` INTEGER NOT NULL,
    `created` INTEGER NOT NULL,
    `attempts` INTEGER NOT NULL,
    `run_after` INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS `IDX_tasks_priority` ON `tasks` (`priority`);
CREATE INDEX IF NOT EXISTS `IDX_tasks_run_after` ON `tasks` (`run_after`);


CREATE TABLE IF NOT EXISTS `cache` (
    `key` TEXT NOT NULL,
    `added` INTEGER NOT NULL,
    `value` BLOB
);
CREATE UNIQUE INDEX IF NOT EXISTS `IDX_cache_key` ON `cache` (`key`);


CREATE TABLE IF NOT EXISTS `nodes` (
    `id` INTEGER PRIMARY KEY,      -- Unique node id.
    `parent` INTEGER NULL,         -- Parent node id, if any.
    `lb` INTEGER NOT NULL,         -- COMMENT 'Nested set, left boundary.
    `rb` INTEGER NOT NULL,         -- COMMENT 'Nested set, right boundary.
    `type` TEXT NOT NULL,          -- Some value to distinguish nodes, e.g.: wiki, article, user.
    `created` TEXT NOT NULL,       -- Date when the document was created, probably editable.
    `updated` TEXT NOT NULL,       -- Date when the document was last saved, autoupdated.
    `key` TEXT NULL,               -- Additional string key for things like wiki.
    `published` INTEGER NOT NULL,  -- Set to 1 to publish the document.
    `more` BLOB                    -- Additional data, serialize()d.
);
CREATE INDEX IF NOT EXISTS IDX_nodes_parent ON nodes (parent);
CREATE INDEX IF NOT EXISTS IDX_nodes_lb ON nodes (lb);
CREATE INDEX IF NOT EXISTS IDX_nodes_rb ON nodes (rb);
CREATE INDEX IF NOT EXISTS IDX_nodes_type ON nodes (type);
CREATE INDEX IF NOT EXISTS IDX_nodes_created ON nodes (created);
CREATE UNIQUE INDEX IF NOT EXISTS IDX_nodes_key ON nodes (`key`);
CREATE INDEX IF NOT EXISTS IDX_nodes_published ON nodes (published);


CREATE TABLE IF NOT EXISTS `nodes_rel` (
    `tid` INTEGER,
    `nid` INTEGER
);
CREATE INDEX IF NOT EXISTS IDX_nodes_rel_nid ON nodes_rel (nid);
CREATE INDEX IF NOT EXISTS IDX_nodes_rel_tid ON nodes_rel (tid);


CREATE TABLE IF NOT EXISTS `nodes_file_idx` (
    `id` INTEGER,
    `kind` TEXT
);
CREATE INDEX IF NOT EXISTS IDX_nodes_file_idx_id ON nodes_file_idx (id);
CREATE INDEX IF NOT EXISTS IDX_nodes_file_idx_kind ON nodes_file_idx (kind);
