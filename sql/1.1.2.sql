ALTER TABLE `maintainer__tables`
    CHANGE `threshold_fragmentation` `threshold_fragmentation` DECIMAL(5, 2) UNSIGNED NOT NULL DEFAULT '10.00' COMMENT 'Minimum fragmentation ratio to suggest a table for OPTIMIZE',
    CHANGE `fragmentation_current` `fragmentation_current` DECIMAL(5, 2) UNSIGNED GENERATED ALWAYS AS (`data_free_current` /
                                                                                                       NULLIF(
                                                                                                           `data_length_current` +
                                                                                                           `index_length_current` +
                                                                                                           `data_free_current`,
                                                                                                           0) *
                                                                                                       100) VIRTUAL COMMENT 'Current table fragmentation',
    CHANGE `fragmentation_before` `fragmentation_before` DECIMAL(5, 2) UNSIGNED GENERATED ALWAYS AS (`data_free_before` /
                                                                                                     NULLIF(
                                                                                                         `data_length_before` +
                                                                                                         `index_length_before` +
                                                                                                         `data_free_before`,
                                                                                                         0) *
                                                                                                     100) VIRTUAL COMMENT 'Fragmentation value before last OPTIMIZE',
    CHANGE `fragmentation_after` `fragmentation_after` DECIMAL(5, 2) UNSIGNED GENERATED ALWAYS AS (`data_free_after` /
                                                                                                   NULLIF(
                                                                                                       `data_length_after` +
                                                                                                       `index_length_after` +
                                                                                                       `data_free_after`,
                                                                                                       0) *
                                                                                                   100) VIRTUAL COMMENT 'Fragmentation value after last OPTIMIZE',
    CHANGE `size_change` `size_change` DECIMAL(65, 2) UNSIGNED GENERATED ALWAYS AS (ABS(CAST(`data_length_after` + `index_length_after` + `data_free_after` AS DECIMAL(25, 0)) -
                                                                                        CAST(`data_length_current` + `index_length_current` + `data_free_current` AS DECIMAL(25, 0))) /
                                                                                    NULLIF(`data_length_after` +
                                                                                           `index_length_after` +
                                                                                           `data_free_after`, 0) *
                                                                                    100) VIRTUAL COMMENT 'Size change since last OPTIMIZE',
    CHANGE `analyze_rows_delta` `analyze_rows_delta` BIGINT(21) UNSIGNED GENERATED ALWAYS AS (ABS(
        CAST(`rows_current` AS SIGNED) -
        CAST(`analyze_rows` AS DECIMAL(25, 0)))) VIRTUAL COMMENT 'Current delta of rows in table compared to the time when last ANALYZE was run',
    CHANGE `check_rows_delta` `check_rows_delta` BIGINT(21) UNSIGNED GENERATED ALWAYS AS (ABS(CAST(`rows_current` AS SIGNED) - CAST(`check_rows` AS DECIMAL(25, 0)))) VIRTUAL COMMENT 'Current delta of rows in table compared to the time when last CHECK was run';

UPDATE `maintainer__settings`
SET `value` = '1.1.2'
WHERE `maintainer__settings`.`setting` = 'version';