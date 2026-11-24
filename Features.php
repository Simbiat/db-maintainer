<?php

declare(strict_types=1);

namespace Simbiat\Database\Maintainer;

use Simbiat\Database\Query;

/**
 * Features detected in current table.
 */
final class Features
{
    use MaintainerTrait;

    /**
     * Database version.
     *
     * @var bool
     */
    public private(set) bool $mariadb = false;

    /**
     * Whether persistent statistics are used.
     *
     * @var bool
     */
    public private(set) bool $analyze_persistent = false;

    /**
     * If `true`, then statistics are already included in regular ANALYZE.
     *
     * @var bool
     */
    public private(set) bool $skip_persistent = true;

    /**
     * Whether histograms are supported.
     *
     * @var bool
     */
    public private(set) bool $histogram = false;

    /**
     * Whether automatic histograms are supported.
     *
     * @var bool
     */
    public private(set) bool $auto_histogram = false;

    /**
     * Whether `innodb_file_per_table` is enabled.
     *
     * @var bool
     */
    public private(set) bool $file_per_table = false;

    /**
     * Whether InnoDB compression is supported.
     *
     * @var bool
     */
    public private(set) bool $page_compression = false;

    /**
     * Whether current user has permissions to do `SET GLOBAL`.
     *
     * @var bool
     */
    public private(set) bool $set_global = false;

    /**
     * Whether current user has permissions to do `FLUSH`.
     *
     * @var bool
     */
    public private(set) bool $can_flush = false;

    /**
     * Whether current user has permissions to do `FLUSH` optimizer.
     *
     * @var bool
     */
    public private(set) bool $can_flush_optimizer = false;

    /**
     * Whether `CHECK` is possible for `SEQUENCE` engine.
     *
     * @var bool
     */
    public private(set) bool $sequence_check = false;

    /**
     * Whether `CHECK` is possible for `MEMORY` engine.
     *
     * @var bool
     */
    public private(set) bool $memory_check = false;

    /**
     * Class constructor.
     *
     * @param \PDO|null $dbh PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     */
    public function __construct(?\PDO $dbh = null)
    {
        $this->init($dbh);
        // Get database version
        /** @var string $version */
        $version = Query::query('SELECT VERSION();', return: 'value');
        $this->mariadb = \mb_stripos($version, 'MariaDB', 0, 'UTF-8') !== false;
        $analyze_persistent = Query::query(/* @lang SQL */ "SHOW GLOBAL VARIABLES WHERE `variable_name`='use_stat_tables';", fetch_argument: 1, return: 'value');
        // If the value is `never`, it means MariaDB does not use persistent statistics at all.
        if (
            \is_string($analyze_persistent)
            && \strcasecmp($analyze_persistent, 'never') !== 0
        ) {
            $this->analyze_persistent = true;
            // If it's `complementary` or `preferably`, then statistics are already included in regular ANALYZE.
            $this->skip_persistent
                = \strcasecmp($analyze_persistent, 'complementary') === 0
                || \strcasecmp($analyze_persistent, 'preferably') === 0;
        } else {
            $this->analyze_persistent = false;
            $this->skip_persistent = true;
        }

        // Check if histograms are supported. MySQL 8+.
        if (
            !$this->mariadb
            && \version_compare(\mb_strtolower($version, 'UTF-8'), '8.0.0', 'ge')
        ) {
            $this->histogram = true;
            $this->auto_histogram = \version_compare(\mb_strtolower($version, 'UTF-8'), '8.4.0', 'ge');
        } else {
            $this->histogram = false;
            $this->auto_histogram = false;
        }

        // Checking if we are using 'file per table' for InnoDB tables. This means we can use COMPRESSED and DYNAMIC as ROW FORMAT
        $innodb_file_per_table = Query::query(/* @lang SQL */ "SHOW GLOBAL VARIABLES WHERE `variable_name`='innodb_file_per_table';", fetch_argument: 1, return: 'value') ?? '';
        $this->file_per_table = \strcasecmp($innodb_file_per_table, 'ON') === 0;
        // Check if InnoDB Compression is supported. MariaDB 10.6+ only.
        $this->page_compression
            = $this->mariadb
            && \version_compare(\mb_strtolower($version, 'UTF-8'), '10.6.0', 'ge');
        // Check if SET GLOBAL is possible
        $this->set_global = Query::query('SELECT COUNT(*) as `count` FROM `information_schema`.`USER_PRIVILEGES` WHERE GRANTEE=CONCAT(\'\\\'\', SUBSTRING_INDEX(CURRENT_USER(), \'@\', 1), \'\\\'@\\\'\', SUBSTRING_INDEX(CURRENT_USER(), \'@\', -1), \'\\\'\') AND `PRIVILEGE_TYPE` IN (\'SUPER\', \'SYSTEM_VARIABLES_ADMIN\');', return: 'count') > 0;
        // Check if FLUSH is possible
        $this->can_flush = Query::query('SELECT COUNT(*) as `count` FROM `information_schema`.`USER_PRIVILEGES` WHERE GRANTEE=CONCAT(\'\\\'\', SUBSTRING_INDEX(CURRENT_USER(), \'@\', 1), \'\\\'@\\\'\', SUBSTRING_INDEX(CURRENT_USER(), \'@\', -1), \'\\\'\') AND `PRIVILEGE_TYPE`=\'RELOAD\';', return: 'count') > 0;
        if (
            !$this->mariadb
            && \version_compare(\mb_strtolower($version, 'UTF-8'), '8.0.0', 'ge')
        ) {
            // We have MySQL 8 or newer
            if ($this->can_flush) {
                $this->can_flush_optimizer = true;
            } elseif (Query::query('SELECT COUNT(*) AS `count` FROM `information_schema`.`USER_PRIVILEGES` WHERE `GRANTEE`=CONCAT(\'\\\'\', SUBSTRING_INDEX(CURRENT_USER(), \'@\', 1), \'\\\'@\\\'\', SUBSTRING_INDEX(CURRENT_USER(), \'@\', -1), \'\\\'\') AND `PRIVILEGE_TYPE` IN (\'FLUSH_OPTIMIZER_COSTS\', \'FLUSH OPTIMIZER COSTS\');', return: 'count') > 0) {
                $this->can_flush_optimizer = true;
            } else {
                $this->can_flush_optimizer = false;
            }
        } else {
            $this->can_flush_optimizer = false;
        }

        // SEQUENCE engine supports CHECK in MariaDB since version 12
        $this->sequence_check
            = $this->mariadb
            && \version_compare(\mb_strtolower($version, 'UTF-8'), '12.0.0', 'ge');

        // MEMORY engine supports CHECK in MariaDB since version 12
        $this->memory_check
            = $this->mariadb
            && \version_compare(\mb_strtolower($version, 'UTF-8'), '13.1.0', 'ge');
    }
}
