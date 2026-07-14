<?php
declare(strict_types = 1);

namespace Simbiat\Database\Maintainer;

use Simbiat\ArrayHelpers\Converters;
use Simbiat\Database\Query;
use Simbiat\StringHelpers\Sanitize;

/**
 * Class to analyze database tables and suggest commands to run to maintain them
 */
class Analyzer
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
     * Analyze tables to check if running a maintenance task if recommended.
     *
     * @param string       $schema Schema name
     * @param string|array $table  Optional table name(s)
     *
     * @return array
     */
    public function suggest(string $schema, string|array $table = []): array
    {
        if (\is_string($table)) {
            $table = [$table];
        }
        $this->schemaTableChecker($schema, $table);
        $where_table_in = ($table === [] ? '' : ' AND `table` IN (:table)');
        #Update tables' data
        $this->updateTables($schema, $table);
        #Suggest CHECK
        Query::query('UPDATE `'.$this->prefix.'tables`
                                SET `check`=1, `analyzed`=CURRENT_TIMESTAMP(6)
                                WHERE
                                `schema`=:schema'.$where_table_in.' AND
                                /*Exclude tables that require repairing*/
                                `repair`=0 AND
                                /*Exclude tables for which CHECK has already been suggested*/
                                `check`=0 AND
                                /*Include only engines that support CHECK*/
                                `engine` IN (\'Archive\', \'Aria\', \'CSV\', \'InnoDB\', \'MyISAM\''.($this->features['sequence_check'] ? ', \'Sequence\'' : '').') AND
                                /*Do not update anything if CHECK is not to be suggested*/
                                `check_suggest`=1 AND
                                /*Check if enough time has passed since last CHECK*/
                                (
                                    `check_days_since` IS NULL OR
                                    `check_days_since` >= `check_days_delay`
                                ) AND
                                (
                                    /*Check if we work only with changed tables*/
                                    `only_if_changed`=0 OR
                                    (
                                        /*If checksums are not NULL and different, then there was a definitive change*/
                                        (
                                            `checksum_current` IS NOT NULL AND
                                            `check_checksum` IS NOT NULL AND
                                            `checksum_current` != `check_checksum`
                                        ) OR
                                        /*Otherwise rely on row difference*/
                                        (
                                            (
                                                `check_rows_delta` IS NULL AND
                                                (
                                                    /*If we do not have the delta, check update time, if present*/
                                                    (
                                                        `update_time` IS NULL OR
                                                        `check_date` IS NULL
                                                    ) OR
                                                    (
                                                        `update_time` IS NOT NULL AND
                                                        `check_date` IS NOT NULL AND
                                                        DATE(`update_time`) > DATE(`check_date`)
                                                    )
                                                )
                                            ) OR
                                            (
                                                `check_rows_delta` IS NOT NULL AND
                                                `check_rows_delta` >= `threshold_rows_delta`
                                            )
                                        )
                                    )
                                );',
            [':schema' => $schema, ':table' => [$table, 'in', 'string']]);
        #Suggest OPTIMIZE
        Query::query('UPDATE `'.$this->prefix.'tables`
                                SET `optimize`=1, `analyzed`=CURRENT_TIMESTAMP(6)
                                WHERE
                                `schema`=:schema'.$where_table_in.' AND
                                /*Exclude tables that require repairing*/
                                `repair`=0 AND
                                /*Exclude tables for which OPTIMIZE has already been suggested*/
                                `optimize`=0 AND
                                /*Do not update anything if OPTIMIZE is not to be suggested*/
                                `optimize_suggest`=1 AND
                                /*Check if enough time has passed since last OPTIMIZE*/
                                (
                                    `optimize_days_since` IS NULL OR
                                    `optimize_days_since` >= `optimize_days_delay`
                                ) AND
                                /*Check either fragmentation or size change values are at or above respective thresholds*/
                                (
                                    `fragmentation_current` >= `threshold_fragmentation` OR
                                    `size_change` IS NULL OR
                                    `size_change` >= `threshold_size_change`
                                ) AND
                                /*Include only engines that support OPTIMIZE*/
                                (
                                    `engine` IN (\'Archive\', \'Aria\', \'MyISAM\') OR
                                    (
                                        /*InnoDB supports OPTIMIZE only with file_per_table enabled (default in modern DB build)*/
                                        `engine`=\'InnoDB\' AND
                                        (SELECT `VARIABLE_VALUE` FROM `INFORMATION_SCHEMA`.`GLOBAL_VARIABLES` WHERE `VARIABLE_NAME`=\'innodb_file_per_table\') IN (\'1\', \'ON\')
                                    )
                                );',
            [':schema' => $schema, ':table' => [$table, 'in', 'string']]);
        #Suggest ANALYZE
        Query::query('UPDATE `'.$this->prefix.'tables`
                                SET `analyze`=1, `analyzed`=CURRENT_TIMESTAMP(6)
                                WHERE
                                `schema`=:schema'.$where_table_in.' AND
                                /*Exclude tables that require repairing*/
                                `repair`=0 AND
                                /*Exclude tables for which ANALYZE has already been suggested*/
                                `analyze`=0 AND
                                /*Include only engines that support ANALYZE*/
                                `engine` IN (\'Aria\', \'InnoDB\', \'MyISAM\') AND
                                /*Do not update anything if ANALYZE is not to be suggested*/
                                `analyze_suggest`=1 AND
                                (
                                    `engine`!=\'InnoDB\' OR
                                    (
                                        /*InnoDB does ANALYZE when OPTIMIZE is triggered, so no need to run ANALYZE for tables that are already suggested for OPTIMIZE*/
                                        `engine`=\'InnoDB\' AND
                                        `optimize`=0
                                    )
                                ) AND
                                /*Check if enough time has passed since last ANALYZE*/
                                (
                                    `analyze_days_since` IS NULL OR
                                    `analyze_days_since` >= `analyze_days_delay`
                                ) AND
                                (
                                    /*Check if we work only with changed tables*/
                                    `only_if_changed`=0 OR
                                    (
                                        /*If checksums are not NULL and different, then there was a definitive change*/
                                        (
                                            `checksum_current` IS NOT NULL AND
                                            `analyze_checksum` IS NOT NULL AND
                                            `checksum_current` != `analyze_checksum`
                                        ) OR
                                        /*Otherwise rely on row difference*/
                                        (
                                            (
                                                `analyze_rows_delta` IS NULL AND
                                                (
                                                    /*If we do not have the delta, check update time, if present*/
                                                    (
                                                        `update_time` IS NULL OR
                                                        `analyze_date` IS NULL
                                                    ) OR
                                                    (
                                                        `update_time` IS NOT NULL AND
                                                        `analyze_date` IS NOT NULL AND
                                                        DATE(`update_time`) > DATE(`analyze_date`)
                                                    )
                                                )
                                            ) OR
                                            (
                                                `analyze_rows_delta` IS NOT NULL AND
                                                `analyze_rows_delta` >= `threshold_rows_delta`
                                            )
                                        )
                                    )
                                );',
            [':schema' => $schema, ':table' => [$table, 'in', 'string']]);
        #Suggest compression
        Query::query('UPDATE `'.$this->prefix.'tables`
                                SET `compress`=1, `analyzed`=CURRENT_TIMESTAMP(6)
                                WHERE
                                `schema`=:schema'.$where_table_in.' AND
                                /*Exclude tables that require repairing*/
                                `repair`=0 AND
                                /*Exclude tables for which compression has already been suggested*/
                                `compress`=0 AND
                                /*Do not update anything if compression is not to be suggested*/
                                `compress_suggest`=1 AND
                                /*Exclude tables that use page compression*/
                                `page_compressed`=0 AND
                                (
                                    (
                                        /*InnoDB can support row and page (MariaDB only) compression*/
                                        `engine`=\'InnoDB\' AND
                                        (
                                            (
                                                (SELECT `VARIABLE_VALUE` FROM `INFORMATION_SCHEMA`.`GLOBAL_VARIABLES` WHERE `VARIABLE_NAME`=\'innodb_file_per_table\') NOT IN (\'1\', \'ON\') AND
                                                `row_format`!=\'DYNAMIC\'
                                            ) OR
                                            (
                                                /*Compression requires file_per_table to be enabled (default)*/
                                                (SELECT `VARIABLE_VALUE` FROM `INFORMATION_SCHEMA`.`GLOBAL_VARIABLES` WHERE `VARIABLE_NAME`=\'innodb_file_per_table\') IN (\'1\', \'ON\') AND
                                                (
                                                    /*If page compression is supported, we can use it*/
                                                    (SELECT `VARIABLE_VALUE` FROM `INFORMATION_SCHEMA`.`GLOBAL_VARIABLES` WHERE `VARIABLE_NAME` = \'innodb_compression_level\') IS NOT NULL OR
                                                    (
                                                        (
                                                            /*If we prefer COMPRESSED, then we check that the current row format is not COMPRESSED only*/
                                                            (
                                                                (SELECT `value` FROM `'.$this->prefix.'settings` WHERE `setting`=\'prefer_compressed\')=\'1\' AND
                                                                `row_format`!=\'COMPRESSED\'
                                                            ) OR
                                                            /*If we do not prefer COMPRESSED, then we check that the current row format is neither COMPRESSED nor DYNAMIC*/
                                                            (
                                                                (SELECT `value` FROM `'.$this->prefix.'settings` WHERE `setting`=\'prefer_compressed\')=\'0\' AND
                                                                `row_format`!=\'COMPRESSED\'
                                                                AND `row_format`!=\'DYNAMIC\'
                                                            )
                                                        )
                                                    )
                                                )
                                            )
                                        )
                                     ) OR
                                    /*MyISAM can only suggest a DYNAMIC row format as an alternative*/
                                    (
                                        `engine`=\'MyISAM\' AND
                                        `row_format`!=\'DYNAMIC\'
                                    )
                                );',
            [':schema' => $schema, ':table' => [$table, 'in', 'string']]);
        #Check if fulltext rebuild is required (changes in settings detected) for any tables and update FULLTEXT settings after that
        $fulltext_rebuild = [];
        if ($this->settings['innodb_fulltext_current'] !== $this->settings['innodb_fulltext']) {
            $fulltext_rebuild[] = /** @lang SQL */
                'UPDATE `'.$this->prefix.'tables`
                SET `fulltext_rebuild`=1, `analyzed`=CURRENT_TIMESTAMP(6)
                WHERE
                    `schema`=:schema'.$where_table_in.' AND
                    `engine`=\'InnoDB\' AND
                    `has_fulltext`=1 AND
                    /* Regular OPTIMIZE will rebuild the whole index anyway */
                    `optimize`=0 AND
                    `fulltext_rebuild`=0';
        }
        if ($this->settings['myisam_fulltext_current'] !== $this->settings['myisam_fulltext']) {
            $fulltext_rebuild[] = /** @lang SQL */
                'UPDATE `'.$this->prefix.'tables`
                SET `fulltext_rebuild`=1, `analyzed`=CURRENT_TIMESTAMP(6)
                WHERE
                    `schema`=:schema'.$where_table_in.' AND
                    `engine`=\'MyISAM\' AND
                    `has_fulltext`=1 AND
                    `fulltext_rebuild`=0';
        }
        $fulltext_rebuild[] = ['UPDATE `'.$this->prefix.'settings` SET `value`=:innodb_fulltext WHERE `setting`=\'innodb_fulltext\';', [':innodb_fulltext' => $this->settings['innodb_fulltext_current']]];
        $fulltext_rebuild[] = ['UPDATE `'.$this->prefix.'settings` SET `value`=:myisam_fulltext WHERE `setting`=\'myisam_fulltext\';', [':myisam_fulltext' => $this->settings['myisam_fulltext_current']]];
        #Run the queries for FULLTEXT rebuild suggestions
        Query::query($fulltext_rebuild, [':schema' => $schema, ':table' => [$table, 'in', 'string']]);
        #Suggest FULLTEXT-only OPTIMIZE
        Query::query('UPDATE `'.$this->prefix.'tables`
                                SET `optimize_fulltext`=1, `analyzed`=CURRENT_TIMESTAMP(6)
                                WHERE
                                `schema`=:schema'.$where_table_in.' AND
                                /*Exclude tables that require repairing*/
                                `repair`=0 AND
                                /*Exclude tables for which full OPTIMIZE has already been suggested*/
                                `optimize`=0 AND
                                /*Exclude tables for which FULLTEXT-only OPTIMIZE has already been suggested*/
                                `optimize_fulltext`=0 AND
                                /*Do not update anything if OPTIMIZE is not to be suggested*/
                                `optimize_suggest`=1 AND
                                /*Include only tables with FULLTEXT indexes*/
                                `has_fulltext`=1 AND
                                /*Check if enough rows were deleted*/
                                (
                                    `optimize_fulltext_deleted` IS NOT NULL AND
                                    `optimize_fulltext_deleted` >= `optimize_fulltext_threshold`
                                ) AND
                                /*Include only InnoDB*/
                                (
                                    /*InnoDB supports OPTIMIZE only with file_per_table enabled (default in modern DB build)*/
                                    `engine`=\'InnoDB\' AND
                                    (SELECT `VARIABLE_VALUE` FROM `INFORMATION_SCHEMA`.`GLOBAL_VARIABLES` WHERE `VARIABLE_NAME`=\'innodb_file_per_table\') IN (\'1\', \'ON\')
                                );',
            [':schema' => $schema, ':table' => [$table, 'in', 'string']]);
        #If no table was provided, update date for all tables that have no action suggested
        if ($table === []) {
            Query::query('UPDATE `'.$this->prefix.'tables` SET `analyzed`=CURRENT_TIMESTAMP(6) WHERE `schema`=:schema AND
                                    (`check` + `repair` + `compress` + `analyze` + `optimize` + `optimize_fulltext` + `fulltext_rebuild`)=0;', [':schema' => $schema]);
        }
        #Get all tables for which an action was suggested
        $results = Query::query('SELECT `schema`, `table`, `check`, `check_auto_run`, `repair`, `compress`, `analyze`, `analyze_auto_run`, `optimize`, `optimize_fulltext`, `optimize_auto_run`, `fulltext_rebuild`, `fulltext_rebuild_auto_run`, `analyze_histogram`
                                    FROM `'.$this->prefix.'tables`
                                    WHERE `schema`=:schema'.$where_table_in.' AND
                                    (`check` + `repair` + `compress` + `analyze` + `optimize` + `optimize_fulltext` + `fulltext_rebuild`)>0
                                    ORDER BY `total_length_current`;',
            [':schema' => $schema, ':table' => [$table, 'in', 'string']],
            return: 'all'
        );
        #Ensure booleans are used in results
        foreach ($results as &$result) {
            foreach ($result as $column => &$value) {
                if (!\in_array($column, ['schema', 'table'], true)) {
                    $value = (bool)$value;
                }
            }
        }
        return $results;
    }
    
    /**
     * Suggest actions for table(s) and auto-process them, if applicable
     *
     * @param string       $schema Schema name
     * @param string|array $table  Optional table name(s)
     *
     * @return array
     */
    public function autoProcess(string $schema, string|array $table = []): array
    {
        if (\is_string($table)) {
            $table = [$table];
        }
        $this->schemaTableChecker($schema, $table);
        $commander = new Commander($this->dbh, $this->prefix);
        $tables = $this->suggest($schema, $table);
        #Nothing to do if no tables were returned
        if (\count($tables) === 0) {
            return [];
        }
        #Enable maintenance mode
        $results = [];
        #Not catching exception on enabling maintenance mode: if it fails, we need to stop doing anything else
        $results['maintainer_general']['maintenance_start'] = $commander->maintenance(false, true);
        #Process tables one by one if an action is both suggested for the table and auto-running of it is enabled
        foreach ($tables as $table_actions) {
            $results[$schema][$table_actions['table']] = $this->processLoop($table_actions, $commander);
        }
        #Reset innodb_optimize_fulltext_only to 0, in case we failed during FULLTEXT optimization.
        if ($this->features['set_global']) {
            try {
                $results['maintainer_general']['fulltext_settings_reset'] = Query::query([
                    /** @lang SQL */ 'SET GLOBAL innodb_optimize_fulltext_only=DEFAULT;',
                    /** @lang SQL */ 'SET GLOBAL innodb_ft_num_word_optimize=DEFAULT;',
                    /** @lang SQL */ 'SET GLOBAL innodb_ft_aux_table=NULL;'
                ]);
            } catch (\Throwable $exception) {
                $results['maintainer_general']['fulltext_settings_reset'] = $exception->getMessage();
            }
        } else {
            $results['maintainer_general']['fulltext_settings_reset'] = false;
        }
        #Use FLUSH, if enabled
        if ($this->settings['use_flush']) {
            try {
                $results['maintainer_general']['flush'] = $commander->flush(true);
            } catch (\Throwable $exception) {
                $results['maintainer_general']['flush'] = $exception->getMessage();
            }
        } else {
            $results['maintainer_general']['flush'] = false;
        }
        #Stop maintenance mode
        try {
            $results['maintainer_general']['maintenance_end'] = $commander->maintenance(false, true);
        } catch (\Throwable $exception) {
            $results['maintainer_general']['maintenance_end'] = $exception->getMessage();
        }
        #Get timings
        $results['maintainer_general']['timings'] = Query::$timings;
        #Remove all unrelated queries
        foreach ($results['maintainer_general']['timings'] as $key => $timing) {
            if (\preg_match('/^(OPTIMIZE|CHECK|ANALYZE|REPAIR|ALTER|FLUSH)/ui', $timing['query']) !== 1) {
                unset($results['maintainer_general']['timings'][$key]);
            }
        }
        return $results;
    }
    
    /**
     * Actual processing loop for `autoProcess()`.
     * Sounds funny, but the main reason for moving it out of the main function was that Psalm was timing-out when processing it within the `autoProcess()`
     *
     * @param array                                  $table_actions Array with table details and actions
     * @param \Simbiat\Database\Maintainer\Commander $commander     Commander object to avoid recreation
     *
     * @return array
     */
    private function processLoop(array $table_actions, Commander $commander): array
    {
        $results = [];
        if ($table_actions['repair'] && $this->settings['repair_auto_run']) {
            try {
                $results['repair'] = $commander->repair($table_actions['schema'], $table_actions['table'], true, true);
            } catch (\Throwable $exception) {
                $results['repair'] = $exception->getMessage();
            }
        } else {
            $results['repair'] = false;
        }
        if ($table_actions['check'] && $table_actions['check_auto_run']) {
            try {
                $results['check'] = $commander->check($table_actions['schema'], $table_actions['table'], true, true, auto_repair: $this->settings['repair_auto_run']);
            } catch (\Throwable $exception) {
                $results['check'] = $exception->getMessage();
            }
        } else {
            $results['check'] = false;
        }
        if ($table_actions['compress'] && $this->settings['compress_auto_run']) {
            try {
                $results['compress'] = $commander->compress($table_actions['schema'], $table_actions['table'], true, true);
            } catch (\Throwable $exception) {
                $results['compress'] = $exception->getMessage();
            }
        } else {
            $results['compress'] = false;
        }
        if ($table_actions['optimize'] && $table_actions['optimize_auto_run']) {
            try {
                $results['optimize'] = $commander->optimize($table_actions['schema'], $table_actions['table'], true, true);
            } catch (\Throwable $exception) {
                $results['optimize'] = $exception->getMessage();
            }
        } else {
            $results['optimize'] = false;
        }
        if ($table_actions['analyze'] && $table_actions['analyze_histogram'] && $table_actions['analyze_auto_run']) {
            try {
                $results['analyze_histogram'] = $commander->histogram($table_actions['schema'], $table_actions['table'], true, true);
            } catch (\Throwable $exception) {
                $results['analyze_histogram'] = $exception->getMessage();
            }
        } else {
            $results['analyze_histogram'] = false;
        }
        if ($table_actions['analyze'] && $table_actions['analyze_auto_run']) {
            try {
                $results['analyze'] = $commander->analyze($table_actions['schema'], $table_actions['table'], true, true);
            } catch (\Throwable $exception) {
                $results['analyze'] = $exception->getMessage();
            }
        } else {
            $results['analyze'] = false;
        }
        if ($table_actions['fulltext_rebuild'] && !$table_actions['optimize'] && $table_actions['fulltext_rebuild_auto_run']) {
            try {
                $results['fulltext_rebuild'] = $commander->fulltextRebuild($table_actions['schema'], $table_actions['table'], true, true);
            } catch (\Throwable $exception) {
                $results['fulltext_rebuild'] = $exception->getMessage();
            }
        } else {
            $results['fulltext_rebuild'] = false;
        }
        if ($table_actions['optimize_fulltext'] && !$table_actions['optimize'] && $table_actions['fulltext_rebuild'] && $table_actions['optimize_auto_run']) {
            try {
                $results['optimize_fulltext'] = $commander->fulltextOptimize($table_actions['schema'], $table_actions['table'], true, true);
            } catch (\Throwable $exception) {
                $results['optimize_fulltext'] = $exception->getMessage();
            }
        } else {
            $results['optimize_fulltext'] = false;
        }
        return $results;
    }
    
    /**
     * Suggest actions for table(s) and get commands for their manual processing as an array of phases. Will also include commands for actions that are not allowed to auto-run
     *
     * @param string       $schema    Schema name
     * @param string|array $table     Optional table name(s)
     * @param bool         $integrate Whether to include commands to update library's tables
     *
     * @return array
     */
    public function getCommands(string $schema, string|array $table = [], bool $integrate = false): array
    {
        if (\is_string($table)) {
            $table = [$table];
        }
        $this->schemaTableChecker($schema, $table);
        $commander = new Commander($this->dbh, $this->prefix);
        $commands = [
            'prepare' => [],
            'common' => [],
            'pre_optimize' => [],
            'optimize' => [],
            'pre_fulltext' => [],
            'fulltext' => [],
            'stats' => [],
            'reset' => [],
        ];
        #`prepare` phase includes only maintenance mode command if set
        $activate = $commander->maintenance();
        if (\is_string($activate)) {
            $commands['prepare'][] = $activate;
        }
        if ($this->features['set_global']) {
            #`reset` phase includes only maintenance command if any
            $commands['reset'] = [
                /** @lang SQL */
                'SET GLOBAL innodb_optimize_fulltext_only=DEFAULT;',
                /** @lang SQL */
                'SET GLOBAL innodb_ft_num_word_optimize=DEFAULT;',
                /** @lang SQL */
                'SET GLOBAL innodb_ft_aux_table=NULL;'
            ];
            $commands['pre_optimize'] = [
                /** @lang SQL */
                'SET GLOBAL innodb_optimize_fulltext_only=0;',
            ];
            $commands['pre_fulltext'] = [
                /** @lang SQL */
                'SET GLOBAL innodb_optimize_fulltext_only=1;',
                /** @lang SQL */
                'SET GLOBAL innodb_ft_num_word_optimize=10000;',
            ];
        }
        #Include `FLUSH` if available
        if ($this->settings['use_flush']) {
            $flush = $commander->flush();
            if (\is_string($flush)) {
                $commands['reset'][] = $flush;
            }
        }
        #Deactivate maintenance mode
        $deactivate = $commander->maintenance();
        if (\is_string($deactivate)) {
            $commands['reset'][] = $deactivate;
        }
        foreach ($this->suggest($schema, $table) as $table_actions) {
            if ($table_actions['repair']) {
                $this->addCommandsToPhase($commands, $table_actions['schema'], $table_actions['table'], 'common', $commander->repair($table_actions['schema'], $table_actions['table'], $integrate));
            }
            if ($table_actions['check']) {
                $this->addCommandsToPhase($commands, $table_actions['schema'], $table_actions['table'], 'common', $commander->check($table_actions['schema'], $table_actions['table'], $integrate));
            }
            if ($table_actions['compress']) {
                $this->addCommandsToPhase($commands, $table_actions['schema'], $table_actions['table'], 'common', $commander->compress($table_actions['schema'], $table_actions['table'], $integrate));
            }
            if ($table_actions['analyze'] && $table_actions['analyze_histogram']) {
                $this->addCommandsToPhase($commands, $table_actions['schema'], $table_actions['table'], 'common', $commander->histogram($table_actions['schema'], $table_actions['table'], $integrate));
            }
            if ($table_actions['analyze']) {
                $this->addCommandsToPhase($commands, $table_actions['schema'], $table_actions['table'], 'common', $commander->analyze($table_actions['schema'], $table_actions['table'], $integrate));
            }
            if ($table_actions['fulltext_rebuild']) {
                $this->addCommandsToPhase($commands, $table_actions['schema'], $table_actions['table'], 'common', $commander->fulltextRebuild($table_actions['schema'], $table_actions['table'], $integrate));
            }
            if ($table_actions['optimize']) {
                $this->addCommandsToPhase($commands, $table_actions['schema'], $table_actions['table'], 'optimize', $commander->optimize($table_actions['schema'], $table_actions['table'], $integrate));
            }
            if ($table_actions['optimize_fulltext']) {
                $this->addCommandsToPhase($commands, $table_actions['schema'], $table_actions['table'], 'fulltext', $commander->fulltextOptimize($table_actions['schema'], $table_actions['table'], $integrate));
            }
        }
        return $commands;
    }
    
    /**
     * Helper function to add commands to a phase
     * @param array  $commands Array to update
     * @param string $schema   Schema to add
     * @param string $table    Table to add
     * @param string $phase    Phase to add to
     * @param array  $to_add   Commands to add
     *
     * @return void
     */
    private function addCommandsToPhase(array &$commands, string $schema, string $table, string $phase, array $to_add): void
    {
        #Add keys if missing
        if (!\array_key_exists($schema, $commands[$phase])) {
            $commands[$phase][$schema] = [];
        }
        if (!\array_key_exists($table, $commands[$phase][$schema])) {
            $commands[$phase][$schema][$table] = [];
        }
        if ($phase === 'optimize' || $phase === 'fulltext') {
            foreach ($to_add as $key => $command) {
                #Setting of innodb_ft_aux_table and respective updates cannot be parallelized, so need to go to a separate phase
                if (\str_starts_with($command, 'SET GLOBAL innodb_ft_aux_table') || \str_starts_with($command, 'UPDATE `'.$this->prefix.'tables` SET `optimize_fulltext_deleted`')) {
                    $commands['stats'][] = $command;
                    unset($to_add[$key]);
                    continue;
                }
                #Remove `SET GLOBAL` commands, since not required in phased build
                if (\str_starts_with($command, 'SET GLOBAL')) {
                    unset($to_add[$key]);
                }
            }
        }
        $commands[$phase][$schema][$table] = \array_merge($commands[$phase][$schema][$table], $to_add);
    }
    
    /**
     * Suggest actions for table(s) and get commands for their manual processing as a flat array. Will also include commands for actions that are not allowed to auto-run.
     *
     * @param string       $schema    Schema name
     * @param string|array $table     Optional table name(s)
     * @param bool         $integrate Whether to include commands to update library's tables
     *
     * @return array
     */
    public function getCommandsFlat(string $schema, string|array $table = [], bool $integrate = false): array
    {
        $commands = $this->getCommands($schema, $table, $integrate);
        return Converters::flatten($commands);
    }
    
    /**
     * Suggest actions for table(s) and write commands into files sorted by phases. Will also include commands for actions that are not allowed to auto-run.
     *
     * @param string       $path      Path to save the files. Recommended to use a dedicated folder.
     * @param string       $schema    Schema name
     * @param string|array $table     Optional table name(s)
     * @param bool         $integrate Whether to include commands to update library's tables
     *
     * @return bool
     */
    public function writeCommandsToFiles(string $path, string $schema, string|array $table = [], bool $integrate = false): bool
    {
        if (Sanitize::whiteString($path)) {
            throw new \UnexpectedValueException('Empty path provided');
        }
        #Trim trailing slash
        $path = mb_rtrim($path, '/', 'UTF-8');
        $path = mb_rtrim($path, '\\', 'UTF-8');
        if (\is_file($path)) {
            throw new \UnexpectedValueException('Path provided is a file');
        }
        if (!\is_dir($path) && !\mkdir($path, 0777, true) && !\is_dir($path)) {
            throw new \UnexpectedValueException('Failed to create directory `'.$path.'`');
        }
        $commands = $this->getCommands($schema, $table, $integrate);
        #Check if there are any optimization phases. If they are empty, the rest is not needed
        /*$commands = [
            'prepare' => [],
            'common' => [],
            'pre_optimize' => [],
            'optimize' => [],
            'pre_fulltext' => [],
            'fulltext' => [],
            'stats' => [],
            'reset' => [],
        ];
        */
        if ($commands['common'] === [] && $commands['optimize'] === [] && $commands['fulltext'] === []) {
            return true;
        }
        #Write flat files
        if ($commands['prepare'] !== []) {
            \file_put_contents($path.'/01-prepare.sql', \implode(\PHP_EOL, $commands['prepare']));
        }
        if ($commands['pre_optimize'] !== []) {
            \file_put_contents($path.'/03-pre_optimize.sql', \implode(\PHP_EOL, $commands['pre_optimize']));
        }
        if ($commands['pre_fulltext'] !== []) {
            \file_put_contents($path.'/05-pre_fulltext.sql', \implode(\PHP_EOL, $commands['pre_fulltext']));
        }
        if ($commands['stats'] !== []) {
            \file_put_contents($path.'/07-stats.sql', \implode(\PHP_EOL, $commands['stats']));
        }
        if ($commands['reset'] !== []) {
            \file_put_contents($path.'/08-reset.sql', \implode(\PHP_EOL, $commands['reset']));
        }
        #Process phases
        foreach (['common', 'optimize', 'fulltext'] as $phase) {
            if ($commands[$phase] === []) {
                continue;
            }
            $directory = match ($phase) {
                'common' => $path.'/02-common',
                'optimize' => $path.'/04-optimize',
                'fulltext' => $path.'/06-fulltext',
            };
            if (!\is_dir($directory) && !\mkdir($directory, 0777, true) && !\is_dir($directory)) {
                throw new \UnexpectedValueException('Failed to create directory `'.$directory.'`');
            }
            foreach ($commands[$phase] as $for_schema => $tables) {
                foreach ($tables as $for_table => $table_actions) {
                    \file_put_contents($directory.'/'.$for_schema.'.'.$for_table.'.sql', \implode(\PHP_EOL, $table_actions));
                }
            }
        }
        return true;
    }
    
    /**
     * Get tables' information for the schema
     *
     * @param string       $schema Schema name
     * @param string|array $table  Optional table name(s)
     *
     * @return void
     */
    public function updateTables(string $schema, string|array $table = []): void
    {
        if (\is_string($table)) {
            $table = [$table];
        }
        $this->schemaTableChecker($schema, $table);
        $where_table_in = ($table === [] ? '' : ' AND `table` IN (:table)');
        #We need to check that the `TEMPORARY` column is available in the `TABLES` table because there are cases when it's not available (MySQL or older version of MariaDB)
        $temp_table_check = Query::query('SELECT `COLUMN_NAME` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = \'information_schema\' AND `TABLE_NAME` = \'TABLES\' AND `COLUMN_NAME` = \'TEMPORARY\';', return: 'all');
        #Delete non-existent old tables
        Query::query([
            'DELETE FROM `'.$this->prefix.'tables` WHERE `schema`=:schema AND (`schema`, `table`) NOT IN (SELECT `TABLE_SCHEMA`, `TABLE_NAME` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA`=:schema);',
            'DELETE FROM `'.$this->prefix.'columns_include` WHERE `schema`=:schema AND (`schema`, `table`, `column`) NOT IN (SELECT `TABLE_SCHEMA`, `TABLE_NAME`, `COLUMN_NAME` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA`=:schema);',
            'DELETE FROM `'.$this->prefix.'columns_exclude` WHERE `schema`=:schema AND (`schema`, `table`, `column`) NOT IN (SELECT `TABLE_SCHEMA`, `TABLE_NAME`, `COLUMN_NAME` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA`=:schema);'
        ],
            [':schema' => $schema]);
        #Insert current basic information about tables
        Query::query('INSERT INTO `'.$this->prefix.'tables` (`schema`, `table`, `analyzed`, `engine`, `row_format`, `has_fulltext`, `page_compressed`, `rows_current`, `update_time`, `data_length_current`, `index_length_current`, `data_free_current`, `check_date`)
            SELECT `TABLE_SCHEMA`,
                   `TABLE_NAME`,
                   CURRENT_TIMESTAMP(6) AS `analyzed`,
                   `ENGINE`,
                   `ROW_FORMAT`,
                   IF(EXISTS(SELECT `INDEX_TYPE`
                             FROM `information_schema`.`STATISTICS`
                             WHERE `information_schema`.`STATISTICS`.`TABLE_SCHEMA` = `information_schema`.`TABLES`.`TABLE_SCHEMA`
                               AND `information_schema`.`STATISTICS`.`TABLE_NAME` = `information_schema`.`TABLES`.`TABLE_NAME`
                               AND `INDEX_TYPE` LIKE \'%FULLTEXT%\'), TRUE, FALSE)                       AS `has_fulltext`,
                   IF(`CREATE_OPTIONS` REGEXP \'`?PAGE_COMPRESSED`?\\\\s*=\\\\s*\\\'?(ON|1)\\\'?\', TRUE, FALSE) AS `page_compressed`,
                   `TABLE_ROWS`,
                   GREATEST(COALESCE(`CREATE_TIME`, 0), COALESCE(`UPDATE_TIME`, 0)) AS `UPDATED_AT`,
                   `DATA_LENGTH`,
                   `INDEX_LENGTH`,
                   `DATA_FREE`,
                   `CHECK_TIME`
            FROM `information_schema`.`TABLES`
            WHERE `TABLE_SCHEMA` = :schema'.($table === [] ? '' : ' AND `TABLE_NAME` IN (:table)').' AND `TABLE_TYPE`=\'BASE TABLE\''.($temp_table_check ? 'AND `TEMPORARY`!=\'Y\'' : '').'
            ON DUPLICATE KEY UPDATE `analyzed`=CURRENT_TIMESTAMP(6),
                                    `engine`=values(`engine`),
                                    `row_format`=values(`row_format`),
                                    `has_fulltext`=values(`has_fulltext`),
                                    `page_compressed`=values(`page_compressed`),
                                    `rows_current`=values(`rows_current`),
                                    `update_time`=values(`update_time`),
                                    `data_length_current`=values(`data_length_current`),
                                    `index_length_current`=values(`index_length_current`),
                                    `data_free_current`=values(`data_free_current`),
                                    /*We may have our own `check_date`, so we need to avoid overwriting it with NULL from information_schema*/
                                    `check_date`=IF(values(`check_date`) IS NOT NULL, GREATEST(`check_date`, values(`check_date`)), `check_date`);',
            [':schema' => $schema, ':table' => [$table, 'in', 'string']]);
        #Try to get tables data from InnoDB persistent statistics
        try {
            Query::query('UPDATE `'.$this->prefix.'tables`
                                LEFT JOIN `mysql`.`innodb_table_stats` AS `stats` ON `'.$this->prefix.'tables`.`schema`=`stats`.`database_name` AND `'.$this->prefix.'tables`.`table`=`stats`.`table_name`
                                SET `'.$this->prefix.'tables`.`rows_current` = IF(`'.$this->prefix.'tables`.`update_time` IS NULL OR `stats`.`last_update`>=`'.$this->prefix.'tables`.`update_time`, `stats`.`n_rows`, `'.$this->prefix.'tables`.`rows_current`),
                                    `'.$this->prefix.'tables`.`update_time` = IF(`'.$this->prefix.'tables`.`update_time` IS NULL OR `stats`.`last_update`>=`'.$this->prefix.'tables`.`update_time`, `stats`.`last_update`, `'.$this->prefix.'tables`.`update_time`)
                                WHERE `'.$this->prefix.'tables`.`schema`=:schema'.($table === [] ? '' : ' AND `'.$this->prefix.'tables`.`table` IN (:table)').';',
                [':schema' => $schema, ':table' => [$table, 'in', 'string']]);
        } catch (\Throwable) {
            #Do nothing, not critical, most likely means lack of permissions to `mysql` schema
        }
        #Update number of deleted rows for InnoDB tables with FULLTEXT indexes if `SET GLOBAL` permission is present
        if ($this->features['set_global']) {
            foreach (
                Query::query('SELECT `schema`, `table` FROM `'.$this->prefix.'tables`
                                        WHERE `schema`=:schema'.$where_table_in.' AND `engine`=\'InnoDB\' AND `has_fulltext`=1;',
                    [':schema' => $schema, ':table' => [$table, 'in', 'string']], return: 'all')
                as $data
            ) {
                /* @noinspection UnusedFunctionResultInspection Not needed in this case */
                $this->updateFulltextDeleted($schema, $data['table'], true);
            }
        }
        #Get the exact number of rows if we use them. Limit only to tables that have not been counted since before today, to help with overall performance in case of multiple runs
        foreach (
            Query::query('SELECT `schema`, `table` FROM `'.$this->prefix.'tables`
                                    WHERE `schema`=:schema'.$where_table_in.' AND `only_if_changed`=1 AND `exact_rows`=1 AND (`rows_date` IS NULL OR DATE(`rows_date`) < CURRENT_DATE()) AND `threshold_rows_delta`>0 ORDER BY `data_length_current`;',
                [':schema' => $schema, ':table' => [$table, 'in', 'string']], return: 'all')
            as $data
        ) {
            $count = (string)Query::query('SELECT COUNT(*) AS `count` FROM `'.$schema.'`.`'.$data['table'].'`;', return: 'value');
            try {
                Query::query('UPDATE `'.$this->prefix.'tables`
                                SET `'.$this->prefix.'tables`.`rows_current`=:count, `rows_date`=CURRENT_TIMESTAMP(6)
                                WHERE `'.$this->prefix.'tables`.`schema`=:schema AND `'.$this->prefix.'tables`.`table`=:table;',
                    [':schema' => $schema, ':table' => $data['table'], ':count' => $count]);
            } catch (\Throwable) {
                #Do nothing, not critical.
            }
        }
        #Get the checksums if we use them. Limit only to tables that have not had CHECKSUM taken since before today, to help with overall performance in case of multiple runs.
        #We also exclude tables with no rows, since the checksum will always be 0. But it may be useful to use this along with the `exact_rows` setting, since transactional engines may not return accurate value.
        foreach (Query::query('SELECT `TABLE_SCHEMA`, `TABLE_NAME`, `CHECKSUM` FROM `'.$this->prefix.'tables`
                                    LEFT JOIN `information_schema`.`TABLES` ON `'.$this->prefix.'tables`.`schema`=`TABLE_SCHEMA` AND `'.$this->prefix.'tables`.`table`=`TABLE_NAME`
                                    WHERE `TABLE_SCHEMA`=:schema'.$where_table_in.' AND `only_if_changed`=1 AND `use_checksum`=1 AND `rows_current`>0 AND (`checksum_date` IS NULL OR DATE(`checksum_date`) < CURRENT_DATE()) ORDER BY `TABLE_ROWS`;',
            [':schema' => $schema, ':table' => [$table, 'in', 'string']], return: 'all') as $data) {
            if (empty($data['CHECKSUM'])) {
                $checksum = (string)Query::query('CHECKSUM TABLE `'.$schema.'`.`'.$data['TABLE_NAME'].'` EXTENDED;', fetch_argument: 1, return: 'value');
            } else {
                $checksum = $data['CHECKSUM'];
            }
            if (!Sanitize::whiteString($checksum)) {
                try {
                    Query::query('UPDATE `'.$this->prefix.'tables`
                                    SET `'.$this->prefix.'tables`.`checksum_current`=:checksum, `checksum_date`=CURRENT_TIMESTAMP(6)
                                    WHERE `'.$this->prefix.'tables`.`schema`=:schema AND `'.$this->prefix.'tables`.`table`=:table;',
                        [':schema' => $schema, ':table' => $data['TABLE_NAME'], ':checksum' => $checksum]);
                } catch (\Throwable) {
                    #Do nothing, not critical.
                }
            }
        }
    }
}