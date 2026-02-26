ALTER TABLE `maintainer__tables`
    CHANGE `analyze_days_since` `analyze_days_since` INT(10) AS (to_days(curdate()) - to_days(`analyze_date`)) VIRTUAL COMMENT 'Days since last time ANALYZE was run',
    CHANGE `optimize_days_since` `optimize_days_since` INT(10) AS (to_days(curdate()) - to_days(`optimize_date`)) VIRTUAL COMMENT 'Days since last time OPTIMIZE was run';
UPDATE `maintainer__settings`
SET `value` = '1.0.2'
WHERE `maintainer__settings`.`setting` = 'version';