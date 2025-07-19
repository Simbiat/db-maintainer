<?php
declare(strict_types = 1);

namespace Simbiat\Database\Maintainer;

use Simbiat\Database\Query;

/**
 * Collection of methods shared by classes in Maintainer namespace
 */
trait TraitForMaintainer
{
    /**
     * PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @var \PDO|null
     */
    private(set) \PDO|null $dbh = null;
    
    /**
     * PDO Cron database prefix. Only Latin characters, underscores, dashes and numbers are allowed. Maximum 53 symbols.
     * @var string
     */
    private(set) string $prefix = 'maintainer__' {
        set {
            if (\preg_match('/^[\w\-]{0,49}$/u', $value) === 1) {
                $this->prefix = $value;
            } else {
                throw new \UnexpectedValueException('Invalid database prefix');
            }
        }
    }
    
    /**
     * Class constructor
     * @param \PDO|null $dbh    PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @param string    $prefix Maintainer database prefix.
     */
    private function init(\PDO|null $dbh = null, string $prefix = 'maintainer__'): void
    {
        #Check that a database connection is established
        if ($dbh !== null) {
            $this->dbh = $dbh;
        }
        $this->prefix = $prefix;
        #Establish it, if possible
        new Query($dbh);
    }
    
    /**
     * Sanitize schema and optional table name to prevent injection
     *
     * @param string       $schema Schema name
     * @param string|array $table  Optional table name(s)
     *
     * @return void
     */
    private function schemaTableChecker(string $schema, string|array $table = []): void
    {
        if (\preg_match('/^[\w\-]{1,64}$/u', $schema) !== 1) {
            throw new \UnexpectedValueException('Invalid database schema `'.$schema.'`');
        }
        if (\preg_match('/^information_schema|performance_schema|mysql|sys$/ui', $schema) === 1) {
            throw new \UnexpectedValueException('System schema `'.$schema.'` is not supported');
        }
        if (!empty($table)) {
            if (\is_string($table)) {
                $table = [$table];
            }
            foreach ($table as $table_name) {
                if (\preg_match('/^[\w\-]{1,64}$/u', $table_name) !== 1) {
                    throw new \UnexpectedValueException('Invalid table name `'.$table_name.'`');
                }
            }
        }
    }
    
    /**
     * Get library settings
     *
     * @return array
     */
    public function getSettings(): array
    {
        $settings = Query::query('SELECT `setting`, `value` FROM `'.$this->prefix.'settings` WHERE `setting` NOT IN (\'version\')', return: 'pair');
        #Convert to booleans
        foreach (['compress_auto_run', 'prefer_compressed', 'prefer_extended', 'repair_auto_run', 'use_flush'] as $setting) {
            $settings[$setting] = (bool)$settings[$setting];
        }
        #Get FULLTEXT settings
        $innodb_fulltext = Query::query('SELECT GROUP_CONCAT(`VARIABLE_VALUE`) AS `settings` FROM `INFORMATION_SCHEMA`.`GLOBAL_VARIABLES` WHERE `VARIABLE_NAME` IN (\'innodb_ft_min_token_size\', \'innodb_ft_max_token_size\', \'innodb_ft_server_stopword_table\', \'innodb_ft_user_stopword_table\', \'innodb_ft_enable_stopword\', \'ngram_token_size\') ORDER BY `VARIABLE_NAME`;', return: 'value');
        $myisam_fulltext = Query::query('SELECT GROUP_CONCAT(`VARIABLE_VALUE`) AS `settings` FROM `INFORMATION_SCHEMA`.`GLOBAL_VARIABLES` WHERE `VARIABLE_NAME` IN (\'ft_min_word_len\', \'ft_max_word_len\', \'ft_stopword_file\') ORDER BY `VARIABLE_NAME`;', return: 'value');
        if (empty($settings['innodb_fulltext'])) {
            $settings['innodb_fulltext'] = $innodb_fulltext;
        }
        if (empty($settings['myisam_fulltext'])) {
            $settings['myisam_fulltext'] = $myisam_fulltext;
        }
        $settings['innodb_fulltext_current'] = $innodb_fulltext;
        $settings['myisam_fulltext_current'] = $myisam_fulltext;
        return $settings;
    }
    
    /**
     * Get supported features.
     * @return array
     */
    public function getFeatures(): array
    {
        $features = [];
        #Get database version
        $version = Query::query('SELECT VERSION();', return: 'column')[0];
        if (mb_stripos($version, 'MariaDB', 0, 'UTF-8') !== false) {
            $features['mariadb'] = true;
        } else {
            $features['mariadb'] = false;
        }
        $analyze_persistent = Query::query(/** @lang SQL */ 'SHOW GLOBAL VARIABLES WHERE `variable_name`=\'use_stat_tables\';', fetch_argument: 1, return: 'value');
        #If the value is `never`, it means MariaDB does not use persistent statistics at all.
        if (\is_string($analyze_persistent) && \strcasecmp($analyze_persistent, 'never') !== 0) {
            $features['analyze_persistent'] = true;
            #If it's `complementary` or `preferably`, then statistics are already included in regular ANALYZE.
            if (\strcasecmp($analyze_persistent, 'complementary') === 0 || \strcasecmp($analyze_persistent, 'preferably') === 0) {
                $features['skip_persistent'] = true;
            } else {
                $features['skip_persistent'] = false;
            }
        } else {
            $features['analyze_persistent'] = false;
            $features['skip_persistent'] = true;
        }
        #Check if histograms are supported. MySQL 8+.
        if (!$features['mariadb'] && \version_compare(mb_strtolower($version, 'UTF-8'), '8.0.0', 'ge')) {
            $features['histogram'] = true;
            if (\version_compare(mb_strtolower($version, 'UTF-8'), '8.4.0', 'ge')) {
                $features['auto_histogram'] = true;
            } else {
                $features['auto_histogram'] = false;
            }
        } else {
            $features['histogram'] = false;
            $features['auto_histogram'] = false;
        }
        #Checking if we are using 'file per table' for INNODB tables. This means we can use COMPRESSED and DYNAMIC as ROW FORMAT
        $innodb_file_per_table = Query::query(/** @lang SQL */ 'SHOW GLOBAL VARIABLES WHERE `variable_name`=\'innodb_file_per_table\';', fetch_argument: 1, return: 'value') ?? '';
        if (\strcasecmp($innodb_file_per_table, 'ON') === 0) {
            $features['file_per_table'] = true;
        } else {
            $features['file_per_table'] = false;
        }
        #Check if INNODB Compression is supported. MariaDB 10.6+ only.
        if ($features['mariadb'] && \version_compare(mb_strtolower($version, 'UTF-8'), '10.6.0', 'ge')) {
            $features['page_compression'] = true;
        } else {
            $features['page_compression'] = false;
        }
        #Check if SET GLOBAL is possible
        if (Query::query('SELECT COUNT(*) as `count` FROM `information_schema`.`USER_PRIVILEGES` WHERE GRANTEE=CONCAT(\'\\\'\', SUBSTRING_INDEX(CURRENT_USER(), \'@\', 1), \'\\\'@\\\'\', SUBSTRING_INDEX(CURRENT_USER(), \'@\', -1), \'\\\'\') AND `PRIVILEGE_TYPE` IN (\'SUPER\', \'SYSTEM_VARIABLES_ADMIN\');', return: 'count') > 0) {
            $features['set_global'] = true;
        } else {
            $features['set_global'] = false;
        }
        #Check if FLUSH is possible
        if (Query::query('SELECT COUNT(*) as `count` FROM `information_schema`.`USER_PRIVILEGES` WHERE GRANTEE=CONCAT(\'\\\'\', SUBSTRING_INDEX(CURRENT_USER(), \'@\', 1), \'\\\'@\\\'\', SUBSTRING_INDEX(CURRENT_USER(), \'@\', -1), \'\\\'\') AND `PRIVILEGE_TYPE`=\'RELOAD\';', return: 'count') > 0) {
            $features['can_flush'] = true;
        } else {
            $features['can_flush'] = false;
        }
        if (!$features['mariadb'] && \version_compare(mb_strtolower($version, 'UTF-8'), '8.0.0', 'ge')) {
            #We have MySQL 8 or newer
            if ($features['can_flush']) {
                $features['can_flush_optimizer'] = true;
            } elseif (Query::query('SELECT COUNT(*) AS `count` FROM `information_schema`.`USER_PRIVILEGES` WHERE `GRANTEE`=CONCAT(\'\\\'\', SUBSTRING_INDEX(CURRENT_USER(), \'@\', 1), \'\\\'@\\\'\', SUBSTRING_INDEX(CURRENT_USER(), \'@\', -1), \'\\\'\') AND `PRIVILEGE_TYPE` IN (\'FLUSH_OPTIMIZER_COSTS\', \'FLUSH OPTIMIZER COSTS\');', return: 'count') > 0) {
                $features['can_flush_optimizer'] = true;
            } else {
                $features['can_flush_optimizer'] = false;
            }
        } else {
            $features['can_flush_optimizer'] = false;
        }
        return $features;
    }
}