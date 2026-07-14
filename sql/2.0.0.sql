ALTER TABLE `maintainer__tables`
    ADD `optimize_fulltext` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Whether FULLTEXT only OPTIMIZE was suggested to be run during last analysis' AFTER `optimize`;

ALTER TABLE `maintainer__tables`
    CHANGE `optimize` `optimize` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag indicating whether OPTIMIZE was suggested to be run during last analysis';

ALTER TABLE `maintainer__tables`
    ADD INDEX (`optimize_fulltext`);

ALTER TABLE `maintainer__tables`
    ADD `optimize_fulltext_date` DATETIME(6) NULL DEFAULT NULL COMMENT 'Date when FULLTEXT-only OPTIMIZE was run last time. Will be updated if regular OPTIMIZE is run as well.' AFTER `optimize_date`;

ALTER TABLE `maintainer__tables`
    CHANGE `optimize_days_since` `optimize_days_since` INT(10) AS (TO_DAYS(CURDATE()) - TO_DAYS(`optimize_date`)) VIRTUAL COMMENT 'Days since last time full OPTIMIZE was run';

ALTER TABLE `maintainer__tables`
    CHANGE `optimize_date` `optimize_date` DATETIME(6) NULL DEFAULT NULL COMMENT 'Date when full OPTIMIZE was run last time';

ALTER TABLE `maintainer__tables`
    ADD `optimize_fulltext_deleted` BIGINT(21) UNSIGNED NULL DEFAULT NULL COMMENT 'Count of rows for the table from INNODB_FT_DELETED. If not calculated yet or table does not have FULLTEXT indexes, this will be NULL.' AFTER `optimize_days_since`;

ALTER TABLE `maintainer__tables`
    ADD `optimize_fulltext_threshold` BIGINT(21) UNSIGNED NOT NULL DEFAULT '10000' COMMENT 'Minimum number of rows in INNODB_FT_DELETED table to suggest FULLTEXT-only OPTIMIZE' AFTER `optimize_days_delay`;

ALTER TABLE `maintainer__tables`
    CHANGE `optimize` `optimize` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag indicating whether full OPTIMIZE was suggested to be run during last analysis',
    CHANGE `optimize_suggest` `optimize_suggest` TINYINT(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT 'Whether OPTIMIZE (either full or FULLTEXT-only) can be suggested',
    CHANGE `optimize_auto_run` `optimize_auto_run` TINYINT(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT 'Whether OPTIMIZE can be run automatically (both full and FULLTEXT-only)',
    CHANGE `optimize_days_delay` `optimize_days_delay` SMALLINT(5) UNSIGNED NOT NULL DEFAULT '30' COMMENT 'Days to wait between runs of full OPTIMIZE';

ALTER TABLE `maintainer__tables`
    CHANGE `fragmentation_current` `fragmentation_current` DECIMAL(5, 2) UNSIGNED GENERATED ALWAYS AS (ROUND(
        `data_free_current` / NULLIF(`data_length_current` + `index_length_current` + `data_free_current`, 0) * 100,
        2)) VIRTUAL COMMENT 'Current table fragmentation',
    CHANGE `fragmentation_before` `fragmentation_before` DECIMAL(5, 2) UNSIGNED GENERATED ALWAYS AS (ROUND(
        `data_free_before` / NULLIF(`data_length_before` + `index_length_before` + `data_free_before`, 0) * 100,
        2)) VIRTUAL COMMENT 'Fragmentation value before last OPTIMIZE',
    CHANGE `fragmentation_after` `fragmentation_after` DECIMAL(5, 2) UNSIGNED GENERATED ALWAYS AS (ROUND(
        `data_free_after` / NULLIF(`data_length_after` + `index_length_after` + `data_free_after`, 0) * 100,
        2)) VIRTUAL COMMENT 'Fragmentation value after last OPTIMIZE',
    CHANGE `size_change` `size_change` DECIMAL(65, 2) UNSIGNED GENERATED ALWAYS AS (ROUND(
        ABS(CAST(`data_length_after` + `index_length_after` + `data_free_after` AS DECIMAL(25, 0)) -
            CAST(`data_length_current` + `index_length_current` + `data_free_current` AS DECIMAL(25, 0))) /
        NULLIF(`data_length_after` + `index_length_after` + `data_free_after`, 0) * 100,
        2)) VIRTUAL COMMENT 'Size change since last OPTIMIZE';

UPDATE `maintainer__settings`
SET `value` = '2.0.0'
WHERE `maintainer__settings`.`setting` = 'version';