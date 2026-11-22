<?php

declare(strict_types=1);

namespace Simbiat\Database\Maintainer;

use Simbiat\ArrayHelpers\Converters;
use Simbiat\StringHelpers\Sanitize;

/**
 * Class handling generation of commands resulting from analysis by Analyzer
 *
 * @api
 */
final class CommandsList
{
    use MaintainerTrait;

    /**
     * Phase to enable maintenance mode if the feature is set up.
     *
     * @var array<int, string>
     */
    public private(set) array $prepare = [];

    /**
     * All commands that are *not* `OPTIMIZE` split by schema and table. These can be run in parallel, if required.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    public private(set) array $common = [];

    /**
     * Just 1 command to set `innodb_optimize_fulltext_only` to `0`. Empty if lacking `SET GLOBAL` permission.
     *
     * @var array<int, string>
     */
    public private(set) array $pre_optimize = [];

    /**
     * All full `OPTIMIZE` commands. split by schema and table. These can be run in parallel, if required.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    public private(set) array $optimize = [];

    /**
     * Setting `innodb_optimize_fulltext_only` to `1` and `innodb_ft_num_word_optimize` to `10000` to enable FULLTEXT-only optimization. Empty if lacking `SET GLOBAL` permission.
     *
     * @var array<int, string>
     */
    public private(set) array $pre_fulltext = [];

    /**
     * All FULLTEXT-only `OPTIMIZE` commands. split by schema and table. These can be run in parallel, if required. Empty if lacking `SET GLOBAL` permission.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    public private(set) array $fulltext = [];

    /**
     * Updating FULLTEXT statistics. Should be run *SEQUENTIALLY*! Empty if lacking `SET GLOBAL` permission.
     *
     * @var array<int, string>
     */
    public private(set) array $stats = [];

    /**
     * Resetting all `GLOBAL` settings and, if `SET GLOBAL` permission is available, `FLUSH` command, if respective permission is available and disabling maintenance if the feature is set up.
     *
     * @var array<int, string>
     */
    public private(set) array $reset = [];

    /**
     * Class constructor.
     *
     * @param \PDO|null $dbh    PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @param string    $prefix Maintainer database prefix.
     *
     * @throws \RuntimeException|\UnexpectedValueException
     */
    public function __construct(?\PDO $dbh = null, string $prefix = 'maintainer__')
    {
        $this->init($dbh, $prefix);
        // Get settings
        $this->settings = new Settings($dbh, $prefix);
        // Get supported features
        $this->features = new Features($dbh);
    }

    /**
     * Suggest actions for table(s) and get commands for their manual processing as an array of phases. Will also include commands for actions that are not allowed to auto-run.
     *
     * @param string       $schema    Schema name
     * @param array|string $table     Optional table name(s)
     * @param bool         $integrate Whether to include commands to update library's tables
     *
     * @return self
     *
     * @throws \RuntimeException|\UnexpectedValueException
     */
    public function getCommands(string $schema, array|string $table = [], bool $integrate = false): self
    {
        if (\is_string($table)) {
            $table = [$table];
        }

        $this->schemaTableChecker($schema, $table);
        $commander = new Commander($this->dbh, $this->prefix);
        // `prepare` phase includes only maintenance mode command if set
        $activate = $commander->maintenance();
        if (\is_string($activate)) {
            $this->prepare[] = $activate;
        }

        if ($this->features->set_global) {
            // `reset` phase includes only maintenance command if any
            $this->reset = [
                /* @lang SQL */
                'SET GLOBAL innodb_optimize_fulltext_only=DEFAULT;',
                /* @lang SQL */
                'SET GLOBAL innodb_ft_num_word_optimize=DEFAULT;',
                /* @lang SQL */
                'SET GLOBAL innodb_ft_aux_table=NULL;',
            ];
        }

        // Include FLUSH if available
        if ($this->settings->use_flush) {
            $flush = $commander->flush();
            if (\is_string($flush)) {
                $this->reset[] = $flush;
            }
        }

        // Deactivate maintenance mode
        $deactivate = $commander->maintenance();
        if (\is_string($deactivate)) {
            $this->reset[] = $deactivate;
        }

        foreach (new Analyzer($this->dbh, $this->prefix)->suggest($schema, $table) as $table_actions) {
            if ($table_actions['repair']) {
                $this->addCommandsToPhase($table_actions['schema'], $table_actions['table'], 'common', $commander->repair($table_actions['schema'], $table_actions['table'], $integrate));
            }

            if ($table_actions['check']) {
                $this->addCommandsToPhase($table_actions['schema'], $table_actions['table'], 'common', $commander->check($table_actions['schema'], $table_actions['table'], $integrate));
            }

            if ($table_actions['compress']) {
                $this->addCommandsToPhase($table_actions['schema'], $table_actions['table'], 'common', $commander->compress($table_actions['schema'], $table_actions['table'], $integrate));
            }

            if (
                $table_actions['analyze']
                && $table_actions['analyze_histogram']
            ) {
                $this->addCommandsToPhase($table_actions['schema'], $table_actions['table'], 'common', $commander->histogram($table_actions['schema'], $table_actions['table'], $integrate));
            }

            if ($table_actions['analyze']) {
                $this->addCommandsToPhase($table_actions['schema'], $table_actions['table'], 'common', $commander->analyze($table_actions['schema'], $table_actions['table'], $integrate));
            }

            if ($table_actions['fulltext_rebuild']) {
                $this->addCommandsToPhase($table_actions['schema'], $table_actions['table'], 'common', $commander->fulltextRebuild($table_actions['schema'], $table_actions['table'], $integrate));
            }

            if ($table_actions['optimize']) {
                $this->addCommandsToPhase($table_actions['schema'], $table_actions['table'], 'optimize', $commander->optimize($table_actions['schema'], $table_actions['table'], $integrate));
            }

            if ($table_actions['optimize_fulltext']) {
                $this->addCommandsToPhase($table_actions['schema'], $table_actions['table'], 'fulltext', $commander->fulltextOptimize($table_actions['schema'], $table_actions['table'], $integrate));
            }
        }

        if ($this->features->set_global) {
            // Populate `pre_optimize` and `pre_fulltext` phases only if `optimize` and `fulltext` have commands in them
            if ($this->optimize !== []) {
                $this->pre_optimize = [
                    /* @lang SQL */
                    'SET GLOBAL innodb_optimize_fulltext_only=0;',
                ];
            }

            if ($this->fulltext !== []) {
                $this->pre_fulltext = [
                    /* @lang SQL */
                    'SET GLOBAL innodb_optimize_fulltext_only=1;',
                    /* @lang SQL */
                    'SET GLOBAL innodb_ft_num_word_optimize=10000;',
                ];
            }
        }

        return $this;
    }

    /**
     * Get commands as array of phases.
     *
     * @return array<string, array<string, array<string, array<int, string>>>|array<int, string>>
     */
    public function toArray(): array
    {
        return [
            'prepare' => $this->prepare,
            'common' => $this->common,
            'pre_optimize' => $this->pre_optimize,
            'optimize' => $this->optimize,
            'pre_fulltext' => $this->pre_fulltext,
            'fulltext' => $this->fulltext,
            'stats' => $this->stats,
            'reset' => $this->reset,
        ];
    }

    /**
     * Suggest actions for table(s) and get commands for their manual processing as a flat array. Will also include commands for actions that are not allowed to auto-run.
     *
     * @param string       $schema    Schema name
     * @param array|string $table     Optional table name(s)
     * @param bool         $integrate Whether to include commands to update library's tables
     *
     * @return array<int, string>
     *
     * @noinspection PhpUnused
     *
     * @throws \RuntimeException|\UnexpectedValueException
     */
    public function getCommandsFlat(string $schema, array|string $table = [], bool $integrate = false): array
    {
        return Converters::flatten($this->getCommands($schema, $table, $integrate)->toArray());
    }

    /**
     * Suggest actions for table(s) and write commands into files sorted by phases. Will also include commands for actions that are not allowed to auto-run.
     *
     * @param string       $path      Path to save the files. Recommended to use a dedicated folder.
     * @param string       $schema    Schema name
     * @param array|string $table     Optional table name(s)
     * @param bool         $integrate Whether to include commands to update library's tables
     *
     * @return bool
     *
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function writeCommandsToFiles(string $path, string $schema, array|string $table = [], bool $integrate = false): bool
    {
        if (Sanitize::whiteString($path)) {
            throw new \UnexpectedValueException('Empty path provided');
        }

        // Trim trailing slash
        $path = \mb_rtrim($path, '/', 'UTF-8');
        $path = \mb_rtrim($path, '\\', 'UTF-8');
        if (\is_file($path)) {
            throw new \UnexpectedValueException('Path provided is a file');
        }

        if (
            !\is_dir($path)
            && !\mkdir($path, recursive: true)
            && !\is_dir($path)
        ) {
            throw new \UnexpectedValueException('Failed to create directory `'.$path.'`');
        }

        // Populate attributes
        (void) $this->getCommands($schema, $table, $integrate);
        // Check if there are any optimization phases. If they are empty, the rest is not needed
        if (
            $this->common === []
            && $this->optimize === []
            && $this->fulltext === []
        ) {
            return true;
        }

        // Write flat files
        if ($this->prepare !== []) {
            \file_put_contents($path.'/01-prepare.sql', \implode(\PHP_EOL, $this->prepare));
        }

        if ($this->pre_optimize !== []) {
            \file_put_contents($path.'/03-pre_optimize.sql', \implode(\PHP_EOL, $this->pre_optimize));
        }

        if ($this->pre_fulltext !== []) {
            \file_put_contents($path.'/05-pre_fulltext.sql', \implode(\PHP_EOL, $this->pre_fulltext));
        }

        if ($this->stats !== []) {
            \file_put_contents($path.'/07-stats.sql', \implode(\PHP_EOL, $this->stats));
        }

        if ($this->reset !== []) {
            \file_put_contents($path.'/08-reset.sql', \implode(\PHP_EOL, $this->reset));
        }

        // Process phases
        foreach (['common', 'optimize', 'fulltext'] as $phase) {
            if ($this->{$phase} === []) {
                continue;
            }

            $directory = match ($phase) {
                'common' => $path.'/02-common',
                'optimize' => $path.'/04-optimize',
                'fulltext' => $path.'/06-fulltext',
            };
            if (
                !\is_dir($directory)
                && !\mkdir($directory, recursive: true)
                && !\is_dir($directory)
            ) {
                throw new \UnexpectedValueException('Failed to create directory `'.$directory.'`');
            }

            foreach ($this->{$phase} as $for_schema => $tables) {
                foreach ($tables as $for_table => $table_actions) {
                    \file_put_contents($directory.'/'.$for_schema.'.'.$for_table.'.sql', \implode(\PHP_EOL, $table_actions));
                }
            }
        }

        return true;
    }

    /**
     * Helper function to add commands to a phase.
     *
     * @param string $schema Schema to add
     * @param string $table  Table to add
     * @param string $phase  Phase to add to
     * @param array  $to_add Commands to add
     *
     * @return void
     */
    private function addCommandsToPhase(string $schema, string $table, string $phase, array $to_add): void
    {
        // Add keys if missing
        if (!\array_key_exists($schema, $this->{$phase})) {
            $this->{$phase}[$schema] = [];
        }

        if (!\array_key_exists($table, $this->{$phase}[$schema])) {
            $this->{$phase}[$schema][$table] = [];
        }

        if (
            $phase === 'optimize'
            || $phase === 'fulltext'
        ) {
            foreach ($to_add as $key => $command) {
                // Setting of innodb_ft_aux_table and respective updates cannot be parallelized, so need to go to a separate phase
                if (
                    \str_starts_with($command, 'SET GLOBAL innodb_ft_aux_table')
                    || \preg_match('/^UPDATE `[^`]+`.`'.$this->prefix.'tables` SET `optimize_fulltext_deleted`/ui', $command) === 1
                ) {
                    $this->stats[] = $command;
                    unset($to_add[$key]);

                    continue;
                }

                // Remove `SET GLOBAL` commands, since not required in phased build
                if (\str_starts_with($command, 'SET GLOBAL')) {
                    unset($to_add[$key]);
                }
            }
        }

        if (\array_key_exists($table, $this->{$phase}[$schema])) {
            $this->{$phase}[$schema][$table] = \array_merge($this->{$phase}[$schema][$table], $to_add);
        }
    }
}
