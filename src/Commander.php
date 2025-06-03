<?php
declare(strict_types = 1);

namespace Simbiat\Database\Maintainer;

use Simbiat\Database\Manage;
use Simbiat\Database\Query;

use function is_string;

/**
 * Class to analyze database tables and suggest commands to run to maintain them
 */
class Commander
{
    use TraitForMaintainer;
    
    /**
     * Library settings
     * @var array
     */
    private array $settings;
    
    /**
     * List of supported features
     * @var array
     */
    private array $features;
    
    /**
     * Class constructor
     * @param \PDO|null $dbh    PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @param string    $prefix Maintainer database prefix.
     */
    public function __construct(\PDO|null $dbh = null, string $prefix = 'maintainer__')
    {
        $this->init($dbh, $prefix);
        #Get settings
        $this->settings = $this->getSettings();
        #Get supported features
        $this->features = $this->getFeatures();
    }
    
    /**
     * Compress the table
     *
     * @param string $schema           Schema name
     * @param string $table            Table name
     * @param bool   $integrate        Whether to generate commands to update library's tables
     * @param bool   $run              Whether to run the commands or just return them
     * @param bool   $preferCompressed Prefer `COMPRESSED` row format over `DYNAMIC` if both are available
     *
     * @return array|bool
     */
    public function compress(string $schema, string $table, bool $integrate = false, bool $run = false, bool $preferCompressed = false): array|bool
    {
        $details = $this->getTableDetails($schema, $table);
        if (preg_match('/^(InnoDB|MyISAM)$/ui', $details['ENGINE']) !== 1) {
            throw new \UnexpectedValueException('Table `'.$schema.'`.`'.$table.'` with engine `'.$details['ENGINE'].'` does not support compression');
        }
        $commands = [];
        if (strcasecmp($details['ENGINE'], 'MyISAM') === 0) {
            if (strcasecmp($details['ROW_FORMAT'], 'Dynamic') === 0) {
                throw new \UnexpectedValueException('MyISAM table `'.$schema.'`.`'.$table.'` already uses `Dynamic` row format');
            }
            $commands[] = 'ALTER TABLE `'.$schema.'`.`'.$table.'` ROW_FORMAT=DYNAMIC;';
            if ($integrate) {
                $commands[] = /** @lang SQL */
                    'UPDATE `'.$this->prefix.'tables` SET `row_format`=\'Dynamic\', `compress`=0 WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';';
            }
        } elseif (strcasecmp($details['ENGINE'], 'InnoDB') === 0) {
            if (preg_match('/`?PAGE_COMPRESSED`?\s*=\s*\'?(ON|1)\'?/ui', $details['CREATE_OPTIONS']) === 1) {
                throw new \UnexpectedValueException('InnoDB table `'.$schema.'`.`'.$table.'` already uses page compression');
            }
            if ($this->features['page_compression']) {
                $commands[] = 'ALTER TABLE `'.$schema.'`.`'.$table.'` ROW_FORMAT=DYNAMIC PAGE_COMPRESSED=1;';
                if ($integrate) {
                    $commands[] = /** @lang SQL */
                        'UPDATE `'.$this->prefix.'tables` SET `page_compressed`=1, `row_format`=\'Dynamic\', `compress`=0 WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';';
                }
            } elseif ($this->features['file_per_table'] && ($this->settings['prefer_compressed'] || $preferCompressed)) {
                if (strcasecmp($details['ROW_FORMAT'], 'COMPRESSED') === 1) {
                    throw new \UnexpectedValueException('InnoDB table `'.$schema.'`.`'.$table.'` already uses `Compressed` row format');
                }
                $commands[] = 'ALTER TABLE `'.$schema.'`.`'.$table.'` ROW_FORMAT=COMPRESSED;';
                if ($integrate) {
                    $commands[] = /** @lang SQL */
                        'UPDATE `'.$this->prefix.'tables` SET `row_format`=\'Compressed\', `compress`=0 WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';';
                }
            } elseif (preg_match('/^(Compressed|Dynamic)$/ui', $details['ROW_FORMAT']) !== 1) {
                $commands[] = 'ALTER TABLE `'.$schema.'`.`'.$table.'` ROW_FORMAT=DYNAMIC;';
                if ($integrate) {
                    $commands[] = /** @lang SQL */
                        'UPDATE `'.$this->prefix.'tables` SET `row_format`=\'Dynamic\', `compress`=0 WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';';
                }
            } else {
                throw new \UnexpectedValueException('InnoDB table `'.$schema.'`.`'.$table.'` already uses `'.$details['ROW_FORMAT'].'` row format');
            }
        }
        if ($run) {
            return Query::query($commands);
        }
        return $commands;
    }
    
    /**
     * `CHECK` the table
     *
     * @param string $schema         Schema name
     * @param string $table          Table name
     * @param bool   $integrate      Whether to generate commands to update library's tables
     * @param bool   $run            Whether to run the commands or just return them
     * @param bool   $preferExtended Use `EXTENDED` option
     * @param bool   $autoRepair     Run `REPAIR` automatically
     *
     * @return array|bool
     */
    public function check(string $schema, string $table, bool $integrate = false, bool $run = false, bool $preferExtended = false, bool $autoRepair = false): array|bool
    {
        $details = $this->getTableDetails($schema, $table);
        if (preg_match('/^(InnoDB|MyISAM|Aria|Archive|CSV)$/ui', $details['ENGINE']) !== 1) {
            throw new \UnexpectedValueException('Table `'.$schema.'`.`'.$table.'` with engine `'.$details['ENGINE'].'` does not support CHECK');
        }
        $commands = ['CHECK TABLE `'.$schema.'`.`'.$table.'` '.($this->settings['prefer_extended'] || $preferExtended ? 'EXTENDED' : 'MEDIUM').';'];
        if ($integrate) {
            $commands[] = /** @lang SQL */
                'UPDATE `'.$this->prefix.'tables` SET `check_date`=CURRENT_TIMESTAMP(), `check_rows`=`rows_current`, `check_checksum`=`checksum_current`, `check`=0 WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';';
        }
        if ($run) {
            $result = $this->checkResults(Query::query($commands[0], return: 'all'));
            if (is_string($result)) {
                #InnoDB does not support REPAIR
                if (preg_match('/^(MyISAM|Aria|Archive|CSV)$/ui', $details['ENGINE']) === 1) {
                    #Set repair flag
                    Query::query('UPDATE `'.$this->prefix.'tables` SET `repair`=1 WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';');
                    if ($autoRepair) {
                        if ($this->repair($schema, $table, $integrate, $run, $preferExtended)) {
                            if ($integrate) {
                                #Need to wrap the UPDATE in array due to how `Query` works
                                return Query::query([$commands[1]]);
                            }
                            return true;
                        }
                        return false;
                    }
                }
                throw new \RuntimeException('Failed to `CHECK` `'.$schema.'`.`'.$table.'` with following error: '.$result);
            }
            if ($integrate) {
                #Need to wrap the UPDATE in array due to how `Query` works
                return Query::query([$commands[1]]);
            }
            return true;
        }
        return $commands;
    }
    
    /**
     * `REPAIR` the table
     *
     * @param string $schema         Schema name
     * @param string $table          Table name
     * @param bool   $integrate      Whether to generate commands to update library's tables
     * @param bool   $run            Whether to run the commands or just return them
     * @param bool   $preferExtended Use `EXTENDED` option
     *
     * @return array|bool
     */
    public function repair(string $schema, string $table, bool $integrate = false, bool $run = false, bool $preferExtended = false): array|bool
    {
        $details = $this->getTableDetails($schema, $table);
        if (preg_match('/^(MyISAM|Aria|Archive|CSV)$/ui', $details['ENGINE']) !== 1) {
            throw new \UnexpectedValueException('Table `'.$schema.'`.`'.$table.'` with engine `'.$details['ENGINE'].'` does not support REPAIR');
        }
        $commands = ['REPAIR TABLE `'.$schema.'`.`'.$table.'`'.($this->settings['prefer_extended'] || $preferExtended ? ' EXTENDED' : '').';'];
        if ($integrate) {
            $commands[] = /** @lang SQL */
                'UPDATE `'.$this->prefix.'tables` SET `repair_date`=CURRENT_TIMESTAMP(), `repair`=0 WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';';
        }
        if ($run) {
            $result = $this->checkResults(Query::query($commands[0], return: 'all'));
            if (is_string($result)) {
                throw new \RuntimeException('Failed to `REPAIR` `'.$schema.'`.`'.$table.'` with following error: '.$result);
            }
            if ($integrate) {
                #Need to wrap the UPDATE in array due to how `Query` works
                return Query::query([$commands[1]]);
            }
            return true;
        }
        return $commands;
    }
    
    /**
     * `ANALYZE` the table
     *
     * @param string $schema    Schema name
     * @param string $table     Table name
     * @param bool   $integrate Whether to generate commands to update library's tables
     * @param bool   $run       Whether to run the commands or just return them
     *
     * @return array|bool
     */
    public function analyze(string $schema, string $table, bool $integrate = false, bool $run = false): array|bool
    {
        $details = $this->getTableDetails($schema, $table);
        if (preg_match('/^(InnoDB|MyISAM|Aria)$/ui', $details['ENGINE']) !== 1) {
            throw new \UnexpectedValueException('Table `'.$schema.'`.`'.$table.'` with engine `'.$details['ENGINE'].'` does not support ANALYZE');
        }
        $commands = ['ANALYZE TABLE `'.$schema.'`.`'.$table.'`;'];
        if ($integrate) {
            $commands[] = /** @lang SQL */
                'UPDATE `'.$this->prefix.'tables` SET `analyze_date`=CURRENT_TIMESTAMP(), `analyze_rows`=`rows_current`, `analyze_checksum`=`checksum_current`, `analyze`=0 WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';';
        }
        if ($run) {
            $result = $this->checkResults(Query::query($commands[0], return: 'all'));
            if (is_string($result)) {
                throw new \RuntimeException('Failed to `ANALYZE` `'.$schema.'`.`'.$table.'` with following error: '.$result);
            }
            if ($integrate) {
                #Need to wrap the UPDATE in array due to how `Query` works
                return Query::query([$commands[1]]);
            }
            return true;
        }
        return $commands;
    }
    
    /**
     * `ANALYZE` the table to generate histogram statistics
     *
     * @param string $schema    Schema name
     * @param string $table     Table name
     * @param bool   $integrate Whether to generate commands to update library's tables
     * @param bool   $run       Whether to run the commands or just return them
     * @param bool   $noSkip    Whether to enforce statistics generation even if it's covered by regular `ANALYZE`
     *
     * @return array|bool
     */
    public function histogram(string $schema, string $table, bool $integrate = false, bool $run = false, bool $noSkip = false): array|bool
    {
        $details = $this->getTableDetails($schema, $table);
        if (preg_match('/^(InnoDB|MyISAM|Aria)$/ui', $details['ENGINE']) !== 1) {
            throw new \UnexpectedValueException('Table `'.$schema.'`.`'.$table.'` with engine `'.$details['ENGINE'].'` does not support ANALYZE');
        }
        #Don't do anything if neither histograms nor persistent statistics are supported or if persistent statistics are supported, but already covered by regular ANALYZE (unless we enforce them)
        if (!$this->features['histogram'] && (!$this->features['analyze_persistent'] || ($this->features['skip_persistent'] && !$noSkip))) {
            if ($run) {
                return true;
            }
            return [];
        }
        $settingFromLibrary = $this->getHistogramSettings($schema, $table);
        $columns = Query::query(
            'SELECT `COLUMN_NAME`
                    FROM `information_schema`.`COLUMNS` AS `c`
                    WHERE `TABLE_SCHEMA` = :schema
                      AND `TABLE_NAME` = :table
                      AND `DATA_TYPE` NOT IN (
                                                /*Geometry is not supported*/
                                                \'GEOMETRY\', \'POINT\', \'LINESTRING\', \'POLYGON\',
                                                \'MULTIPOINT\', \'MULTILINESTRING\', \'MULTIPOLYGON\', \'GEOMETRYCOLLECTION\',
                                                /*JSON is not supported*/
                                                \'JSON\',
                                                /*TEXT, BLOB and BINARY are technically supported but are generally not used in WHERE/GROUP/JOIN so do not benefit from histograms*/
                                                \'TINYTEXT\', \'TEXT\', \'MEDIUMTEXT\', \'LONGTEXT\',
                                                \'TINYBLOB\', \'BLOB\', \'MEDIUMBLOB\', \'LONGBLOB\',
                                                \'BINARY\', \'VARBINARY\',
                                                /*BIT is usually used for flags or bitmasks and has low cardinality, but it is also stored as binary, so little to no benefit from histograms*/
                                                \'BIT\',
                                                /*YEAR has low cardinality, minimal benefit from histograms*/
                                                \'YEAR\',
                                                /*While the other date and time types may benefit from histograms in some cases, they are niche, due to the types usually used in range comparisons, which work better with indexes*/
                                                \'DATE\', \'TIME\', \'DATETIME\', \'TIMESTAMP\',
                                                /*UUID is MariaDB specific and generally implies sequential and somewhat uniform values, thus do not benefit from histograms*/
                                                \'UUID\'
                        )
                        /*AUTO_INCREMENT means sequential and uniform data, no benefit from histograms*/
                        AND `EXTRA` NOT LIKE \'%AUTO_INCREMENT%\'
                        /*CURRENT_TIMESTAMP (either as default or on update) implies sequential and likely uniform data or data changing frequently, little to no benefit from histograms*/
                        AND `COLUMN_DEFAULT` NOT LIKE \'%CURRENT_TIMESTAMP%\' AND `EXTRA` NOT LIKE \'%CURRENT_TIMESTAMP%\'
                        /*Generated columns are, technically, supported, but they may be changing frequently depending on what expression they use, so most likely not good candidates. GENERATION_EXPRESSION as a more universal way to determine virtuality.*/
                        AND (`GENERATION_EXPRESSION` = \'\' OR `GENERATION_EXPRESSION` IS NULL)
                        /*If the maximum length is too big, we are likely dealing with text (like description columns) or otherwise non-uniform data (like JSON in VARCHAR) or generally data with too much variance. Either of these cases is unlikely to benefit from histograms.*/
                        AND (`CHARACTER_MAXIMUM_LENGTH` IS NULL OR `CHARACTER_MAXIMUM_LENGTH` < 64)
                        /*Columns using TINYINT(1) or CHAR(1) to CHAR(4) or VARCHAR(1) to VARCHAR(4) are often used as flags, including but not limited to boolean values. This implies low cardinality, which benefits little from histograms*/
                        AND `COLUMN_TYPE` NOT LIKE \'%TINYINT(1)%\' AND `COLUMN_TYPE` NOT LIKE \'%CHAR(1)%\' AND `COLUMN_TYPE` NOT LIKE \'%CHAR(2)%\' AND `COLUMN_TYPE` NOT LIKE \'%CHAR(3)%\' AND `COLUMN_TYPE` NOT LIKE \'%CHAR(4)%\'
                        /*Columns that are part of an index usually do not benefit from histograms*/
                        AND NOT EXISTS (
                                SELECT 1 AS `flag`
                                FROM `information_schema`.`STATISTICS` AS `s`
                                WHERE `s`.`TABLE_SCHEMA` = `c`.`TABLE_SCHEMA`
                                  AND `s`.`TABLE_NAME` = `c`.`TABLE_NAME`
                                  AND `s`.`COLUMN_NAME` = `c`.`COLUMN_NAME`
                        )'.($this->features['auto_histogram'] && $settingFromLibrary['analyze_histogram_auto'] && !$noSkip ? '
                        /*Exclude columns that have auto-update enabled already*/
                        AND NOT EXISTS (
                            SELECT 1 AS `flag`
                                FROM `information_schema`.`column_statistics` AS `cs`
                                WHERE `cs`.`SCHEMA_NAME` = `c`.`TABLE_SCHEMA`
                                  AND `cs`.`TABLE_NAME` = `c`.`TABLE_NAME`
                                  AND `cs`.`COLUMN_NAME` = `c`.`COLUMN_NAME`
                                  AND JSON_EXTRACT(`cs`.`HISTOGRAM`, "$.auto-update")=\'true\'
                        )
                        ' : '').';',
            [':schema' => $schema, ':table' => $table],
            return: 'column');
        #Merge with columns that are explicitly included. Need to do this in a separate query, because otherwise table's schema needs to be provided, and that would require getting is somehow, that would complicate things even more
        $columns = array_unique(
            array_merge(
                $columns,
                Query::query(
                    'SELECT `column` AS `flag` FROM `'.$this->prefix.'columns_include` WHERE `schema` = :schema AND `table` = :table;',
                    [':schema' => $schema, ':table' => $table],
                    return: 'column'
                )
            )
        );
        #Remove explicitly excluded columns as well. Doing this as a separate query for the same reason as including columns
        $columns = array_diff(
            $columns,
            Query::query(
                'SELECT `column` AS `flag` FROM `'.$this->prefix.'columns_exclude` WHERE `schema` = :schema AND `table` = :table;',
                [':schema' => $schema, ':table' => $table],
                return: 'column'
            )
        );
        #Don't do anything if there are no columns to ANALYZE
        if (empty($columns)) {
            if ($run) {
                return true;
            }
            return [];
        }
        #Validate all column names
        foreach ($columns as $column) {
            if (preg_match('/^[\w\-]{1,64}$/u', $column) !== 1) {
                throw new \UnexpectedValueException('Invalid table name `'.$column.'`;');
            }
        }
        $commands = [$this->getHistogramCommand($schema, $table, $columns, $settingFromLibrary)];
        if ($integrate) {
            #We do *not* update the `analyze` flag, since regular ANALYZE may need to be run still
            $commands[] = /** @lang SQL */
                'UPDATE `'.$this->prefix.'tables` SET `analyze_date`=CURRENT_TIMESTAMP(), `analyze_rows`=`rows_current`, `analyze_checksum`=`checksum_current` WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';';
        }
        if ($run) {
            $result = $this->checkResults(Query::query($commands[0], return: 'all'));
            if (is_string($result)) {
                throw new \RuntimeException('Failed to `ANALYZE` for histograms `'.$schema.'`.`'.$table.'` with following error: '.$result);
            }
            if ($integrate) {
                #Need to wrap the UPDATE in array due to how `Query` works
                return Query::query([$commands[1]]);
            }
            return true;
        }
        return $commands;
    }
    
    /**
     * Helper function to generate `ANALYZE` command for histogram generation
     * @param string $schema             Schema name
     * @param string $table              Table name
     * @param array  $columns            Columns to process
     * @param array  $settingFromLibrary Histogram settings
     *
     * @return string
     */
    private function getHistogramCommand(string $schema, string $table, array $columns, array $settingFromLibrary): string
    {
        if ($this->features['histogram']) {
            #MySQL format
            if ($this->features['auto_histogram']) {
                $command = 'ANALYZE TABLE `'.$schema.'`.`'.$table.'` UPDATE HISTOGRAM ON `'.implode('`, `', $columns).'` WITH '.$settingFromLibrary['analyze_histogram_buckets'].' BUCKETS '.($settingFromLibrary['analyze_histogram_auto'] ? 'AUTO' : 'MANUAL').' UPDATE;';
            } else {
                $command = 'ANALYZE TABLE `'.$schema.'`.`'.$table.'` UPDATE HISTOGRAM ON `'.implode('`, `', $columns).'` WITH '.$settingFromLibrary['analyze_histogram_buckets'].' BUCKETS;';
            }
        } else {
            #MariaDB format
            $command = 'ANALYZE TABLE `'.$schema.'`.`'.$table.'` PERSISTENT FOR COLUMNS (`'.implode('`, `', $columns).'`) INDEXES ();';
        }
        return $command;
    }
    
    /**
     * Helper function to get histogram settings for a table, if any
     * @param string $schema
     * @param string $table
     *
     * @return array
     */
    private function getHistogramSettings(string $schema, string $table): array
    {
        $settingFromLibrary = [];
        if ($this->features['histogram']) {
            #Get table settings for histograms, if available
            $settingFromLibrary = Query::query('SELECT `analyze_histogram_auto`, `analyze_histogram_buckets`` FROM `'.$this->prefix.'tables` WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';', return: 'row');
        }
        if (empty($settingFromLibrary['analyze_histogram_buckets'])) {
            $settingFromLibrary['analyze_histogram_buckets'] = 100;
        }
        if (empty($settingFromLibrary['analyze_histogram_auto'])) {
            $settingFromLibrary['analyze_histogram_auto'] = false;
        }
        return $settingFromLibrary;
    }
    
    /**
     * `OPTIMIZE` the table
     *
     * @param string $schema    Schema name
     * @param string $table     Table name
     * @param bool   $integrate Whether to generate commands to update library's tables
     * @param bool   $run       Whether to run the commands or just return them
     * @param bool   $noSkip    Whether to enforce statistics generation even if it's covered by regular `ANALYZE`. Used only for InnoDB tables.
     *
     * @return array|bool
     */
    public function optimize(string $schema, string $table, bool $integrate = false, bool $run = false, bool $noSkip = false): array|bool
    {
        $details = $this->getTableDetails($schema, $table);
        if (preg_match('/^(InnoDB|MyISAM|Aria|Archive)$/ui', $details['ENGINE']) !== 1) {
            throw new \UnexpectedValueException('Table `'.$schema.'`.`'.$table.'` with engine `'.$details['ENGINE'].'` does not support OPTIMIZE');
        }
        $commands = [];
        if ($integrate) {
            #Update statistics before optimization
            $commands[] = /** @lang SQL */
                'UPDATE `'.$this->prefix.'tables`
                LEFT JOIN `information_schema`.`TABLES` ON `schema`=`TABLE_SCHEMA` AND `table`=`TABLE_NAME`
                SET `data_length_before`=`DATA_LENGTH`, `index_length_before`=`INDEX_LENGTH`, `data_free_before`=`DATA_FREE` WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';';
        }
        $commands[] = /** @lang SQL */
            'OPTIMIZE TABLE `'.$schema.'`.`'.$table.'`;';
        #If we have permissions to change FULLTEXT variables, and this is an InnoDB table with FULLTEXT indexes, optimize them as well
        $fulltext = $this->features['set_global'] && $details['has_fulltext'] && preg_match('/^InnoDB$/ui', $details['ENGINE']) === 1;
        if ($fulltext) {
            array_push($commands, 'SET @@GLOBAL.innodb_optimize_fulltext_only=1;', 'SET @@GLOBAL.innodb_ft_num_word_optimize=10000;', 'OPTIMIZE TABLE `'.$schema.'`.`'.$table.'`;', 'SET @@GLOBAL.innodb_optimize_fulltext_only=0;', 'SET @@GLOBAL.innodb_ft_num_word_optimize=DEFAULT;');
        }
        if ($integrate) {
            $commands[] = /** @lang SQL */
                'UPDATE `'.$this->prefix.'tables`
                LEFT JOIN `information_schema`.`TABLES` ON `schema`=`TABLE_SCHEMA` AND `table`=`TABLE_NAME`
                SET `data_length_after`=`DATA_LENGTH`, `index_length_after`=`INDEX_LENGTH`, `data_free_after`=`DATA_FREE`, `optimize_date`=CURRENT_TIMESTAMP(), `optimize`=0 WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';';
            #OPTIMIZE also implies ANALYZE for InnoDB tables
            if (preg_match('/^(InnoDB)$/ui', $details['ENGINE']) === 1) {
                $commands[] = /** @lang SQL */
                    'UPDATE `'.$this->prefix.'tables` SET `analyze_date`=CURRENT_TIMESTAMP(), `analyze_rows`=`rows_current`, `analyze_checksum`=`checksum_current`, `analyze`=0 WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';';
            }
        }
        if ($run) {
            $this->runOptimize($schema, $table, $integrate, $fulltext, $details, $commands);
        }
        #InnoDB recreates table and then does ANALYZE, which does not include histograms by default
        if (($this->features['histogram'] || ($this->features['analyze_persistent'] && !$this->features['skip_persistent'] && !$noSkip)) && preg_match('/^(InnoDB)$/ui', $details['ENGINE']) === 1 &&
            #We also need to check that `analyze_histogram` is enabled for the table in settings
            Query::query('SELECT `analyze_histogram` FROM `maintainer__tables` WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\' AND `analyze_histogram`=1;', return: 'check')
        ) {
            $histogram = $this->histogram($schema, $table, $integrate, $run, $noSkip);
            if ($run) {
                return $histogram;
            }
            $commands = array_merge($commands, $histogram);
        } elseif ($run) {
            return true;
        }
        return $commands;
    }
    
    /**
     * Helper function to run OPTIMIZE commands
     * @param string $schema    Schema name
     * @param string $table     Table name
     * @param bool   $integrate Whether to generate commands to update library's tables
     * @param bool   $fulltext  Whether FULLTEXT optimization is required
     * @param array  $details   Table details
     * @param array  $commands  List of commands
     *
     * @return void
     */
    private function runOptimize(string $schema, string $table, bool $integrate, bool $fulltext, array $details, array $commands): void
    {
        if ($integrate) {
            Query::query($commands[0]);
        }
        $result = $this->checkResults(Query::query($commands[$integrate ? 1 : 0], return: 'all'));
        if (is_string($result)) {
            throw new \RuntimeException('Failed to `OPTIMIZE` `'.$schema.'`.`'.$table.'` with following error: '.$result);
        }
        #Optimize FULLTEXT only if we were able to change the innodb_optimize_fulltext_only
        if ($fulltext && Query::query($commands[$integrate ? 2 : 1])) {
            $this->optimizeFulltext($schema, $table, $commands, $integrate);
        }
        if ($integrate) {
            #Need to wrap the UPDATE in array due to how `Query` works
            Query::query([$commands[$fulltext ? 7 : 2]]);
            if (preg_match('/^(InnoDB)$/ui', $details['ENGINE']) === 1) {
                #Need to wrap the UPDATE in array due to how `Query` works
                Query::query([$commands[$fulltext ? 8 : 3]]);
            }
        }
    }
    
    /**
     * Helper function for running FULLTEXT optimization
     * @param string $schema    Schema name
     * @param string $table     Table name
     * @param array  $commands  List of commands
     * @param bool   $integrate Whether commands for library tables are present in the array
     *
     * @return void
     */
    private function optimizeFulltext(string $schema, string $table, array $commands, bool $integrate): void
    {
        try {
            Query::query($commands[$integrate ? 3 : 2]);
        } catch (\Throwable) {
            #Do nothing. Change of innodb_ft_num_word_optimize is not critical
        }
        $result = $this->checkResults(Query::query($commands[$integrate ? 4 : 3], return: 'all'));
        if (is_string($result)) {
            throw new \RuntimeException('Failed to `OPTIMIZE` FULLTEXT indexes for `'.$schema.'`.`'.$table.'` with following error: '.$result);
        }
        #Restore innodb_optimize_fulltext_only
        Query::query($commands[$integrate ? 5 : 4]);
        #Restore innodb_ft_num_word_optimize
        try {
            Query::query($commands[$integrate ? 6 : 5]);
        } catch (\Throwable) {
            #Do nothing. Change of innodb_ft_num_word_optimize is not critical
        }
    }
    
    /**
     * Run FLUSH command respective privileges are present
     *
     * @param bool $run Whether to run the command or just return it
     *
     * @return bool|string
     */
    public function flush(bool $run = false): bool|string
    {
        $command = '';
        if ($this->features['mariadb']) {
            if ($this->features['can_flush']) {
                $command = /** @lang SQL */
                    'FLUSH LOCAL HOSTS, QUERY CACHE, TABLE_STATISTICS, INDEX_STATISTICS, USER_STATISTICS;';
            }
        } elseif ($this->features['can_flush_optimizer']) {
            $command = /** @lang SQL */
                'FLUSH OPTIMIZER_COSTS;';
        }
        if (empty($command)) {
            return false;
        }
        if ($run) {
            return Query::query($command);
        }
        return $command;
    }
    
    /**
     * Rebuild FULLTEXT indexes in a table
     *
     * @param string $schema    Schema name
     * @param string $table     Table name
     * @param bool   $integrate Whether to generate commands to update library's tables
     * @param bool   $run       Whether to run the commands or just return them
     *
     * @return array|bool
     */
    public function fulltextRebuild(string $schema, string $table, bool $integrate = false, bool $run = false): bool|array
    {
        $details = $this->getTableDetails($schema, $table);
        $commands = [];
        if (preg_match('/^(InnoDB|MyISAM|Aria|Mroonga)$/ui', $details['ENGINE']) !== 1) {
            throw new \UnexpectedValueException('Table `'.$schema.'`.`'.$table.'` with engine `'.$details['ENGINE'].'` does not support OPTIMIZE');
        }
        #Get FULLTEXT indexes names
        $indexes = Query::query('SELECT DISTINCT(`INDEX_NAME`) AS `INDEX_NAME` FROM `INFORMATION_SCHEMA`.`STATISTICS` WHERE `TABLE_SCHEMA` = :schema AND `TABLE_NAME` = :table AND `INDEX_TYPE`=\'FULLTEXT\';', [':schema' => $schema, ':table' => $table], return: 'column');
        foreach ($indexes as $index) {
            $commands[] = Manage::rebuildIndexQuery($schema, $table, $index, $run);
        }
        if ($integrate) {
            $commands[] = /** @lang SQL */
                'UPDATE `'.$this->prefix.'tables` SET `fulltext_rebuild_date`=CURRENT_TIMESTAMP(), `fulltext_rebuild`=0 WHERE `schema`=\''.$schema.'\' AND `table`=\''.$table.'\';';
            if ($run) {
                return Query::query($commands[array_key_last($commands)]);
            }
        }
        return $commands;
    }
    
    /**
     * Activate or deactivate maintenance mode
     * @param bool $activate Activate or deactivate maintenance mode
     * @param bool $run      Whether to run the command or just return it
     *
     * @return bool|string
     */
    public function maintenance(bool $activate = true, bool $run = false): bool|string
    {
        if (empty($this->settings['maintenance_schema_name']) || empty($this->settings['maintenance_table_name']) || empty($this->settings['maintenance_setting_column']) || empty($this->settings['maintenance_setting_name']) || empty($this->settings['maintenance_value_column'])) {
            #Consider success, since the feature is not setup
            return false;
        }
        foreach ([$this->settings['maintenance_schema_name'], $this->settings['maintenance_table_name'], $this->settings['maintenance_setting_column'], $this->settings['maintenance_setting_name'], $this->settings['maintenance_value_column']] as $argument) {
            if (preg_match('/^[\w\-]{1,64}$/u', $argument) !== 1) {
                throw new \UnexpectedValueException('Invalid maintenance parameter detected');
            }
        }
        $command = /** @lang SQL */
            'UPDATE `'.$this->settings['maintenance_schema_name'].'`.`'.$this->settings['maintenance_table_name'].'` SET `'.$this->settings['maintenance_value_column'].'` = '.($activate ? '1' : '0').' WHERE `'.$this->settings['maintenance_setting_column'].'` = \''.$this->settings['maintenance_setting_name'].'\';';
        if ($run) {
            if (!Query::query($command)) {
                throw new \RuntimeException('Failed to enable maintenance mode');
            }
            return true;
        }
        return $command;
    }
    
    /**
     * Helper function to check for errors in results from CHECK, REPAIR, ANALYZE and OPTIMIZE commands
     * @param array $result
     *
     * @return bool|string
     */
    private function checkResults(array $result): bool|string
    {
        foreach ($result as $row) {
            if (preg_match('/^(OK|Table is already up to date|Table does not support optimize, doing recreate \+ analyze instead|Engine-independent statistics collected|Histogram statistics created.*)$/ui', $row['Msg_text']) !== 1) {
                return $row['Msg_text'];
            }
        }
        return true;
    }
    
    /**
     * Helper function to get the table details, if a table even exists
     *
     * @param string $schema Schema name
     * @param string $table  Table name
     *
     * @return array
     */
    private function getTableDetails(string $schema, string $table): array
    {
        $this->schemaTableChecker($schema, $table);
        $details = Query::query('SELECT
                                            *,
                                            IF(EXISTS(SELECT `INDEX_TYPE`
                                                    FROM `information_schema`.`STATISTICS`
                                                    WHERE `information_schema`.`STATISTICS`.`TABLE_SCHEMA` = `information_schema`.`TABLES`.`TABLE_SCHEMA`
                                                        AND `information_schema`.`STATISTICS`.`TABLE_NAME` = `information_schema`.`TABLES`.`TABLE_NAME`
                                                        AND `INDEX_TYPE` LIKE \'%FULLTEXT%\'), TRUE, FALSE
                                            ) AS `has_fulltext`
                                        FROM `information_schema`.`TABLES`
                                        WHERE `TABLE_SCHEMA`=:schema AND `TABLE_NAME`=:table;',
            [':schema' => $schema, ':table' => $table], return: 'row');
        if (empty($details)) {
            throw new \RuntimeException('Table `'.$schema.'`.`'.$table.'` does not exist');
        }
        return $details;
    }
}