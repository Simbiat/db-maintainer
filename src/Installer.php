<?php
declare(strict_types = 1);

namespace Simbiat\Database\Maintainer;

use Simbiat\Database\Manage;
use Simbiat\Database\Query;

/**
 * Installer class for Maintainer library.
 */
class Installer
{
    use TraitForMaintainer;
    
    /**
     * Class constructor
     * @param \PDO|null $dbh    PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @param string    $prefix Maintainer database prefix.
     */
    public function __construct(\PDO|null $dbh = null, string $prefix = 'maintainer__')
    {
        $this->init($dbh, $prefix);
    }
    
    /**
     * Install the necessary tables
     * @return bool|string
     */
    public function install(): bool|string
    {
        $version = $this->getVersion();
        #Generate SQL to run
        $sql = '';
        if (version_compare(basename('1.0.0', '.sql'), $version, 'gt')) {
            $sql .= /** @lang SQL */
                'CREATE TABLE `'.$this->prefix.'settings`
                (
                    `setting`     VARCHAR(32) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs`  NOT NULL COMMENT \'Name of the setting\',
                    `value`       VARCHAR(64) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs`  NULL COMMENT \'Value of the setting\',
                    `description` VARCHAR(255) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_ai_ci` NULL COMMENT \'Description of the setting \'
                ) ENGINE = InnoDB
                  CHARSET = `utf8mb4`
                  COLLATE `utf8mb4_0900_as_cs` COMMENT = \'Settings for database maintainer library\';
                ALTER TABLE `'.$this->prefix.'settings` ADD PRIMARY KEY (`setting`);
                INSERT INTO `'.$this->prefix.'settings` (`setting`, `value`, `description`)
                VALUES (\'maintenance_table_name\', NULL, \'Table name to use to enable database maintenance for the service\'),
                       (\'maintenance_schema_name\', NULL, \'Schema name to use to enable database maintenance for the service\'),
                       (\'maintenance_setting_column\', NULL, \'Setting\\\'s column name to use to enable database maintenance for the service\'),
                       (\'maintenance_setting_name\', NULL, \'Setting\\\'s name to use to enable database maintenance for the service\'),
                       (\'maintenance_value_column\', NULL, \'Value\\\'s column name to use to enable database maintenance for the service\'),
                       (\'prefer_compressed\', \'0\', \'Flag indicating whether COMPRESSED row format is to be suggested over DYNAMIC when both are available\'),
                       (\'prefer_extended\', \'0\', \'Flag indicating whether EXTENDED should be used with CHECK and REPAIR\'),
                       (\'compress_auto_run\', \'0\', \'Whether compression can be applied automatically\'),
                       (\'repair_auto_run\', \'0\', \'Whether REPAIR can be run automatically if an issue is detected by CHECK\'),
                       (\'use_flush\', \'0\', \'Use FLUSH statement(s) at the end of processing, if have necessary permission and applicable\'),
                       (\'myisam_fulltext\', NULL, \'MyISAM FULLTEXT settings, used to track changes of server settings\'),
                       (\'innodb_fulltext\', NULL, \'InnoDB FULLTEXT settings, used to track changes of server settings\'),
                       (\'version\', \'1.0.0\', \'Version of the library\');
                CREATE TABLE `'.$this->prefix.'columns_include`
                (
                    `schema` VARCHAR(64) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_ci` NOT NULL COMMENT \'Schema name\',
                    `table`  VARCHAR(64) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_ci` NOT NULL COMMENT \'Table name\',
                    `column` VARCHAR(64) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_ci` NOT NULL COMMENT \'Column name\'
                ) ENGINE = InnoDB
                  CHARSET = `utf8mb4`
                  COLLATE `utf8mb4_0900_as_cs` COMMENT = \'Additional columns to include from histogram generation\';
                ALTER TABLE `'.$this->prefix.'columns_include` ADD UNIQUE (`schema`, `table`, `column`);
                CREATE TABLE `'.$this->prefix.'columns_exclude`
                (
                    `schema` VARCHAR(64) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_ci` NOT NULL COMMENT \'Schema name\',
                    `table`  VARCHAR(64) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_ci` NOT NULL COMMENT \'Table name\',
                    `column` VARCHAR(64) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_ci` NOT NULL COMMENT \'Column name\'
                ) ENGINE = InnoDB
                  CHARSET = `utf8mb4`
                  COLLATE `utf8mb4_0900_as_cs` COMMENT = \'Additional columns to exclude from histogram generation\';
                ALTER TABLE `'.$this->prefix.'columns_exclude` ADD UNIQUE (`schema`, `table`, `column`);
                CREATE TABLE `'.$this->prefix.'tables`
                (
                    `schema`                    VARCHAR(64) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_ci` NOT NULL COMMENT \'Name of the table\'\'s schema\',
                    `table`                     VARCHAR(64) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_ci` NOT NULL COMMENT \'Name of the table\',
                    `analyzed`                  DATETIME                                                                  DEFAULT NULL COMMENT \'Date and time of the last analysis\',
                    `engine`                    VARCHAR(64)                                                               DEFAULT NULL COMMENT \'Table\'\'s engine\',
                    `row_format`                VARCHAR(10)                                                               DEFAULT NULL COMMENT \'Table\'\'s row format\',
                    `has_fulltext`              TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Flag indicating if table has FULLTEXT index\',
                    `page_compressed`           TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Flag indicating that table uses InnoDB page compression\',
                    `rows_current`              BIGINT(21) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Current number of rows in table\',
                    `exact_rows`                TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Whether to get exact row count for the table\',
                    `rows_date`                 DATETIME                                                                  DEFAULT NULL COMMENT \'Date when exact row count was taken\',
                    `update_time`               DATETIME                                                                  DEFAULT NULL COMMENT \'Last update time to the table at the time of analysis\',
                    `only_if_changed`           TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 1 COMMENT \'Whether so suggest action only if the table has changed since last time the action was run\',
                    `threshold_rows_delta`      BIGINT(21) UNSIGNED                                              NOT NULL DEFAULT 10000 COMMENT \'Minimum delta for number of rows in the table compared to last run of a command to consider a table significantly changed\',
                    `use_checksum`              TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Whether to get CHECKSUM use it to determine if there was a change\',
                    `checksum_current`          BIGINT(21) UNSIGNED                                                       DEFAULT NULL COMMENT \'Optional current checksum of the table to evaluate if there was a change of data\',
                    `checksum_date`             DATETIME                                                                  DEFAULT NULL COMMENT \'Time the last checksum was taken\',
                    `data_length_current`       BIGINT(21) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Current size of the data only\',
                    `index_length_current`      BIGINT(21) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Current size of the indexes only\',
                    `data_free_current`         BIGINT(21) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Current free space\',
                    `total_length_current`      BIGINT(21) UNSIGNED GENERATED ALWAYS AS (`data_length_current` + `index_length_current` + `data_free_current`) VIRTUAL COMMENT \'Current table size\',
                    `fragmentation_current`     FLOAT(4, 2) UNSIGNED GENERATED ALWAYS AS (`data_free_current` /
                                                                                          (`data_length_current` + `index_length_current` + `data_free_current`) *
                                                                                          100) VIRTUAL COMMENT \'Current table fragmentation\',
                    `threshold_fragmentation`   FLOAT(4, 2) UNSIGNED                                             NOT NULL DEFAULT 10.00 COMMENT \'Minimum fragmentation ratio to suggest a table for OPTIMIZE\',
                    `analyze`                   TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Flag indicating whether ANALYZE was suggested to be run during last analysis\',
                    `analyze_suggest`           TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 1 COMMENT \'Whether ANALYZE can be suggested\',
                    `analyze_auto_run`          TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 1 COMMENT \'Whether ANALYZE can be run automatically\',
                    `analyze_days_delay`        SMALLINT(5) UNSIGNED                                             NOT NULL DEFAULT 14 COMMENT \'Days to wait between runs of ANALYZE\',
                    `analyze_histogram`         TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Flag indicating whether HISTOGRAM optimization should be ran when ANALYZE is suggested\',
                    `analyze_date`              DATETIME                                                                  DEFAULT NULL COMMENT \'Date when ANALYZE was run last time\',
                    `analyze_days_since`        INT(10) GENERATED ALWAYS AS (TO_DAYS(CURDATE()) - TO_DAYS(`analyze_date`)) VIRTUAL COMMENT \'Days since last time CHECK was run\',
                    `analyze_rows`              BIGINT(21) UNSIGNED                                                       DEFAULT NULL COMMENT \'Number of rows in the table at the time when last ANALYZE was run\',
                    `analyze_checksum`          BIGINT(21) UNSIGNED                                                       DEFAULT NULL COMMENT \'Optional checksum of the table at the time when last ANALYZE was run\',
                    `analyze_rows_delta`        BIGINT(21) UNSIGNED GENERATED ALWAYS AS (GREATEST(`rows_current`, `analyze_rows`) -
                                                                                         LEAST(`rows_current`, `analyze_rows`)) VIRTUAL COMMENT \'Current delta of rows in table compared to the time when last ANALYZE was run\',
                    `check`                     TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Flag indicating whether CHECK was suggested to be run during last analysis\',
                    `check_suggest`             TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 1 COMMENT \'Whether CHECK can be suggested\',
                    `check_auto_run`            TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Whether CHECK can be run automatically\',
                    `check_days_delay`          SMALLINT(5) UNSIGNED                                             NOT NULL DEFAULT 30 COMMENT \'Days to wait between runs of CHECK\',
                    `check_date`                DATETIME                                                                  DEFAULT NULL COMMENT \'Date when CHECK was run last time\',
                    `check_days_since`          INT(10) GENERATED ALWAYS AS (TO_DAYS(CURDATE()) - TO_DAYS(`check_date`)) VIRTUAL COMMENT \'Days since last time CHECK was run\',
                    `check_rows`                BIGINT(21) UNSIGNED                                                       DEFAULT NULL COMMENT \'Number of rows in the table at the time when last CHECK was run\',
                    `check_checksum`            BIGINT(21) UNSIGNED                                                       DEFAULT NULL COMMENT \'Optional checksum of the table at the time when last CHECK was run\',
                    `check_rows_delta`          BIGINT(21) UNSIGNED GENERATED ALWAYS AS (GREATEST(`rows_current`, `check_rows`) -
                                                                                         LEAST(`rows_current`, `check_rows`)) VIRTUAL COMMENT \'Current delta of rows in table compared to the time when last CHECK was run\',
                    `optimize`                  TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Flag indicating whether OPTIMIZATION was suggested to be run during last analysis\',
                    `optimize_suggest`          TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 1 COMMENT \'Whether OPTIMIZE can be suggested\',
                    `optimize_auto_run`         TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 1 COMMENT \'Whether OPTIMIZE can be run automatically\',
                    `optimize_days_delay`       SMALLINT(5) UNSIGNED                                             NOT NULL DEFAULT 30 COMMENT \'Days to wait between runs of OPTIMIZE\',
                    `optimize_date`             DATETIME                                                                  DEFAULT NULL COMMENT \'Date when OPTIMIZE was run last time\',
                    `optimize_days_since`       INT(10) GENERATED ALWAYS AS (TO_DAYS(CURDATE()) - TO_DAYS(`optimize_date`)) VIRTUAL COMMENT \'Days since last time CHECK was run\',
                    `data_length_before`        BIGINT(21) UNSIGNED                                                       DEFAULT NULL COMMENT \'Size of data before last OPTIMIZE\',
                    `index_length_before`       BIGINT(21) UNSIGNED                                                       DEFAULT NULL COMMENT \'Size of index before last OPTIMIZE\',
                    `data_free_before`          BIGINT(21) UNSIGNED                                                       DEFAULT NULL COMMENT \'Size of free space before last OPTIMIZE\',
                    `fragmentation_before`      FLOAT(4, 2) UNSIGNED GENERATED ALWAYS AS (`data_free_before` /
                                                                                          (`data_length_before` + `index_length_before` + `data_free_before`) *
                                                                                          100) VIRTUAL COMMENT \'Fragmentation value before last OPTIMIZE\',
                    `data_length_after`         BIGINT(21) UNSIGNED                                                       DEFAULT NULL COMMENT \'Size of data after last OPTIMIZE\',
                    `index_length_after`        BIGINT(21) UNSIGNED                                                       DEFAULT NULL COMMENT \'Size of index after last OPTIMIZE\',
                    `data_free_after`           BIGINT(21) UNSIGNED                                                       DEFAULT NULL COMMENT \'Size of free space after last OPTIMIZE\',
                    `fragmentation_after`       FLOAT(4, 2) UNSIGNED GENERATED ALWAYS AS (`data_free_after` /
                                                                                          (`data_length_after` + `index_length_after` + `data_free_after`) *
                                                                                          100) VIRTUAL COMMENT \'Fragmentation value after last OPTIMIZE\',
                    `fulltext_rebuild`          TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Flag indicating whether FULLTEXT rebuild was suggested\',
                    `fulltext_rebuild_auto_run` TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Whether FULLTEXT rebuild can be run automatically\',
                    `fulltext_rebuild_date`     DATETIME                                                                  DEFAULT NULL COMMENT \'Date when FULLTEXT rebuild was run last time\',
                    `repair`                    TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Flag indicating whether REPAIR was suggested to be run during last analysis\',
                    `repair_date`               DATETIME                                                                  DEFAULT NULL COMMENT \'Date when REPAIR was run last time\',
                    `compress`                  TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT \'Flag indicating whether compression was suggested during last analysis\',
                    `compress_suggest`          TINYINT(1) UNSIGNED                                              NOT NULL DEFAULT 1 COMMENT \'Whether compression can be suggested\'
                ) ENGINE = InnoDB
                  DEFAULT CHARSET = `utf8mb4`
                  COLLATE = `utf8mb4_0900_as_cs` COMMENT =\'Tables\'\' statistics and suggestions\';
                ALTER TABLE `'.$this->prefix.'tables`
                    ADD PRIMARY KEY (`schema`, `table`) USING BTREE,
                    ADD KEY `analyze` (`analyze`),
                    ADD KEY `check` (`check`),
                    ADD KEY `compress` (`compress`),
                    ADD KEY `optimize` (`optimize`),
                    ADD KEY `repair` (`repair`),
                    ADD KEY `analyzed` (`analyzed` DESC),
                    ADD KEY `total_length_current` (`total_length_current`),
                    ADD KEY `fulltext_rebuild` (`fulltext_rebuild`),
                    ADD KEY `has_fulltext` (`has_fulltext`),
                    ADD KEY `engine` (`engine`),
                    ADD KEY `row_format` (`row_format`),
                    ADD KEY `analyze_suggest` (`analyze_suggest`),
                    ADD KEY `check_suggest` (`check_suggest`),
                    ADD KEY `optimize_suggest` (`optimize_suggest`),
                    ADD KEY `compress_suggest` (`compress_suggest`),
                    ADD KEY `use_checksum` (`use_checksum`),
                    ADD KEY `exact_rows` (`exact_rows`),
                    ADD KEY `rows_current` (`rows_current`);
                ';
        }
        #If empty - we are up to date
        if (empty($sql)) {
            return true;
        }
        try {
            return Query::query($sql);
        } catch (\Throwable $e) {
            return $e->getMessage()."\r\n".$e->getTraceAsString();
        }
    }
    
    /**
     * Get the current version of the Maintainer from the database perspective (can be different from the library version)
     * @return string
     */
    public function getVersion(): string
    {
        #Check if the settings table exists
        if (Manage::checkTable($this->prefix.'settings') === 1) {
            return Query::query('SELECT `value` FROM `'.$this->prefix.'settings` WHERE `setting`=\'version\'', return: 'value');
        }
        return '0.0.0';
    }
}