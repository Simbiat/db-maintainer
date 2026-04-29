ALTER TABLE `maintainer__tables`
    CHANGE `analyze_days_since` `analyze_days_since` INT(10) AS (to_days(curdate()) - to_days(`analyze_date`)) VIRTUAL COMMENT 'Days since last time ANALYZE was run',
    CHANGE `optimize_days_since` `optimize_days_since` INT(10) AS (to_days(curdate()) - to_days(`optimize_date`)) VIRTUAL COMMENT 'Days since last time OPTIMIZE was run';

ALTER TABLE `maintainer__tables`
    CHANGE `threshold_fragmentation` `threshold_fragmentation` float(4, 2) UNSIGNED NOT NULL DEFAULT 10.00 COMMENT 'Minimum fragmentation ratio to suggest a table for OPTIMIZE' AFTER `only_if_changed`;

ALTER TABLE `maintainer__tables`
    ADD `threshold_size_change` FLOAT(5, 2) UNSIGNED NOT NULL DEFAULT '25.00' COMMENT 'Minimum table size change to suggest a table for OPTIMIZE' AFTER `threshold_rows_delta`;

ALTER TABLE `maintainer__tables`
    ADD `total_length_after` BIGINT(21) UNSIGNED GENERATED ALWAYS AS (`data_length_after` + `index_length_after` + `data_free_after`) VIRTUAL COMMENT 'Total size of the table after last OPTIMIZE' AFTER `data_free_after`;

ALTER TABLE `maintainer__tables`
    CHANGE `threshold_fragmentation` `threshold_fragmentation` DECIMAL(4, 2) UNSIGNED NOT NULL DEFAULT '10.00' COMMENT 'Minimum fragmentation ratio to suggest a table for OPTIMIZE',
    CHANGE `threshold_size_change` `threshold_size_change` DECIMAL(5, 2) UNSIGNED NOT NULL DEFAULT '25.00' COMMENT 'Minimum table size change to suggest a table for OPTIMIZE',
    CHANGE `fragmentation_current` `fragmentation_current` DECIMAL(4, 2) UNSIGNED AS (`data_free_current` / NULLIF(
        `data_length_current` + `index_length_current` + `data_free_current`, 0) *
                                                                                      100) VIRTUAL COMMENT 'Current table fragmentation',
    CHANGE `fragmentation_before` `fragmentation_before` DECIMAL(4, 2) UNSIGNED AS (`data_free_before` / NULLIF(
        `data_length_before` + `index_length_before` + `data_free_before`, 0) *
                                                                                    100) VIRTUAL COMMENT 'Fragmentation value before last OPTIMIZE',
    CHANGE `fragmentation_after` `fragmentation_after` DECIMAL(4, 2) UNSIGNED AS (`data_free_after` / NULLIF(
        `data_length_after` + `index_length_after` + `data_free_after`, 0) *
                                                                                  100) VIRTUAL COMMENT 'Fragmentation value after last OPTIMIZE';

ALTER TABLE `maintainer__tables`
    ADD `size_change` DECIMAL(65, 2) UNSIGNED GENERATED ALWAYS AS (ABS(CAST(`data_length_after` + `index_length_after` + `data_free_after` AS SIGNED) -
                                                                       CAST(`data_length_current` + `index_length_current` + `data_free_current` AS SIGNED)) /
                                                                   NULLIF(`data_length_after` + `index_length_after` +
                                                                          `data_free_after`, 0) *
                                                                   100) VIRTUAL COMMENT 'Size change since last OPTIMIZE' AFTER `fragmentation_current`;

ALTER TABLE `maintainer__tables`
    ADD KEY `size_change` (`size_change`);

ALTER TABLE `maintainer__tables`
    CHANGE `analyze_rows_delta` `analyze_rows_delta` BIGINT(21) UNSIGNED GENERATED ALWAYS AS (ABS(CAST(`rows_current` AS SIGNED) - CAST(`analyze_rows` AS SIGNED))) VIRTUAL COMMENT 'Current delta of rows in table compared to the time when last ANALYZE was run',
    CHANGE `check_rows_delta` `check_rows_delta` BIGINT(21) UNSIGNED GENERATED ALWAYS AS (ABS(CAST(`rows_current` AS SIGNED) - CAST(`check_rows` AS SIGNED))) VIRTUAL COMMENT 'Current delta of rows in table compared to the time when last CHECK was run';

UPDATE `maintainer__settings`
SET `value` = '1.1.0'
WHERE `maintainer__settings`.`setting` = 'version';