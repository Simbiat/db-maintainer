ALTER TABLE `maintainer__tables`
    CHANGE `analyzed` `analyzed` DATETIME(6) NULL DEFAULT NULL COMMENT 'Date and time of the last analysis',
    CHANGE `rows_date` `rows_date` DATETIME(6) NULL DEFAULT NULL COMMENT 'Date when exact row count was taken',
    CHANGE `update_time` `update_time` DATETIME(6) NULL DEFAULT NULL COMMENT 'Last update time to the table at the time of analysis',
    CHANGE `checksum_date` `checksum_date` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time the last checksum was taken',
    CHANGE `analyze_date` `analyze_date` DATETIME(6) NULL DEFAULT NULL COMMENT 'Date when ANALYZE was run last time',
    CHANGE `check_date` `check_date` DATETIME(6) NULL DEFAULT NULL COMMENT 'Date when CHECK was run last time',
    CHANGE `optimize_date` `optimize_date` DATETIME(6) NULL DEFAULT NULL COMMENT 'Date when OPTIMIZE was run last time',
    CHANGE `fulltext_rebuild_date` `fulltext_rebuild_date` DATETIME(6) NULL DEFAULT NULL COMMENT 'Date when FULLTEXT rebuild was run last time',
    CHANGE `repair_date` `repair_date` DATETIME(6) NULL DEFAULT NULL COMMENT 'Date when REPAIR was run last time';
UPDATE `maintainer__settings` SET `value` = '1.0.1' WHERE `maintainer__settings`.`setting` = 'version';