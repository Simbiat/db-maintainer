ALTER TABLE `maintainer__tables`
    ADD `total_length_before` BIGINT(21) UNSIGNED GENERATED ALWAYS AS (`data_length_before` + `index_length_before` + `data_free_before`) VIRTUAL COMMENT 'Total size of the table before last OPTIMIZE' AFTER `data_free_before`;

UPDATE `maintainer__settings`
SET `value` = '1.1.1'
WHERE `maintainer__settings`.`setting` = 'version';