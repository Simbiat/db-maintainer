<?php

declare(strict_types=1);

namespace Simbiat\Database\Maintainer;

use JetBrains\PhpStorm\ExpectedValues;
use Simbiat\Database\Query;
use Simbiat\StringHelpers\Sanitize;

/**
 * Class to change settings used by the Maintainer library.
 *
 * @api
 */
final class Settings
{
    use MaintainerTrait;

    /**
     * Whether compression can be applied automatically.
     *
     * @var bool
     */
    public private(set) bool $compress_auto_run = false;

    /**
     * InnoDB FULLTEXT settings, used to track changes of server settings.
     *
     * @var ?string
     */
    public private(set) ?string $innodb_fulltext = null;

    /**
     * Schema name to use to enable database maintenance for the service.
     *
     * @var ?string
     */
    public private(set) ?string $maintenance_schema_name = null;

    /**
     * Setting's column name to use to enable database maintenance for the service.
     *
     * @var ?string
     */
    public private(set) ?string $maintenance_setting_column = null;

    /**
     * Setting's name to use to enable database maintenance for the service.
     *
     * @var ?string
     */
    public private(set) ?string $maintenance_setting_name = null;

    /**
     * Table name to use to enable database maintenance for the service.
     *
     * @var ?string
     */
    public private(set) ?string $maintenance_table_name = null;

    /**
     * Value's column name to use to enable database maintenance for the service.
     *
     * @var ?string
     */
    public private(set) ?string $maintenance_value_column = null;

    /**
     * MyISAM FULLTEXT settings, used to track changes of server settings.
     *
     * @var ?string
     */
    public private(set) ?string $myisam_fulltext = null;

    /**
     * Flag indicating whether COMPRESSED row format is to be suggested over DYNAMIC when both are available.
     *
     * @var bool
     */
    public private(set) bool $prefer_compressed = false;

    /**
     * Flag indicating whether EXTENDED should be used with CHECK and REPAIR.
     *
     * @var bool
     */
    public private(set) bool $prefer_extended = false;

    /**
     * Whether REPAIR can be run automatically if an issue is detected by CHECK.
     *
     * @var bool
     */
    public private(set) bool $repair_auto_run = false;

    /**
     * Use FLUSH statement(s) at the end of processing, if have necessary permission and applicable.
     *
     * @var bool
     */
    public private(set) bool $use_flush = false;

    /**
     * Current InnoDB FULLTEXT settings, used to track changes of server settings.
     *
     * @var ?string
     */
    public private(set) ?string $innodb_fulltext_current = null;

    /**
     * Current MyISAM FULLTEXT settings, used to track changes of server settings.
     *
     * @var ?string
     */
    public private(set) ?string $myisam_fulltext_current = null;

    /**
     * Class constructor.
     *
     * @param \PDO|null $dbh    PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @param string    $prefix Maintainer database prefix.
     *
     * @throws \RuntimeException
     */
    public function __construct(?\PDO $dbh = null, string $prefix = 'maintainer__')
    {
        $this->init($dbh, $prefix);
        /** @var array{compress_auto_run: string, innodb_fulltext: string, maintenance_schema_name: string, maintenance_setting_column: string, maintenance_setting_name: string, maintenance_table_name: string, maintenance_value_column: string, myisam_fulltext: string, prefer_compressed: string, prefer_extended: string, repair_auto_run: string, use_flush: string, version: string} $settings */
        $settings = Query::query('SELECT `setting`, `value` FROM `'.$this->prefix."settings` WHERE `setting` NOT IN ('version')", return: 'pair');
        // Convert to booleans
        $this->compress_auto_run = (bool) $settings['compress_auto_run'];
        $this->prefer_compressed = (bool) $settings['prefer_compressed'];
        $this->prefer_extended = (bool) $settings['prefer_extended'];
        $this->repair_auto_run = (bool) $settings['repair_auto_run'];
        $this->use_flush = (bool) $settings['use_flush'];
        // Maintenance settings
        $this->maintenance_schema_name = Sanitize::whiteString($settings['maintenance_schema_name'] ?? '') ? null : $settings['maintenance_schema_name'];
        $this->maintenance_setting_column = Sanitize::whiteString($settings['maintenance_setting_column'] ?? '') ? null : $settings['maintenance_setting_column'];
        $this->maintenance_setting_name = Sanitize::whiteString($settings['maintenance_setting_name'] ?? '') ? null : $settings['maintenance_setting_name'];
        $this->maintenance_table_name = Sanitize::whiteString($settings['maintenance_table_name'] ?? '') ? null : $settings['maintenance_table_name'];
        $this->maintenance_value_column = Sanitize::whiteString($settings['maintenance_value_column'] ?? '') ? null : $settings['maintenance_value_column'];
        // Get FULLTEXT settings
        $innodb_fulltext = Query::query("SELECT GROUP_CONCAT(`VARIABLE_VALUE`) AS `settings` FROM `INFORMATION_SCHEMA`.`GLOBAL_VARIABLES` WHERE `VARIABLE_NAME` IN ('innodb_ft_min_token_size', 'innodb_ft_max_token_size', 'innodb_ft_server_stopword_table', 'innodb_ft_user_stopword_table', 'innodb_ft_enable_stopword', 'ngram_token_size') ORDER BY `VARIABLE_NAME`;", return: 'value');
        $myisam_fulltext = Query::query("SELECT GROUP_CONCAT(`VARIABLE_VALUE`) AS `settings` FROM `INFORMATION_SCHEMA`.`GLOBAL_VARIABLES` WHERE `VARIABLE_NAME` IN ('ft_min_word_len', 'ft_max_word_len', 'ft_stopword_file') ORDER BY `VARIABLE_NAME`;", return: 'value');
        $this->innodb_fulltext_current = Sanitize::whiteString($innodb_fulltext ?? '') ? null : $innodb_fulltext;
        $this->myisam_fulltext_current = Sanitize::whiteString($myisam_fulltext ?? '') ? null : $myisam_fulltext;
        $this->innodb_fulltext = Sanitize::whiteString($settings['innodb_fulltext'] ?? '') ? null : $settings['innodb_fulltext'];
        $this->myisam_fulltext = Sanitize::whiteString($settings['myisam_fulltext'] ?? '') ? null : $settings['myisam_fulltext'];

        if ($this->innodb_fulltext === null) {
            $this->innodb_fulltext = $this->innodb_fulltext_current;
        }

        if ($this->myisam_fulltext) {
            $this->myisam_fulltext = $this->myisam_fulltext_current;
        }

    }

    /**
     * Exclude a column from histogram generation in case it's not excluded by default.
     *
     * @param string       $schema Schema name
     * @param string       $table  Table name
     * @param array|string $column Column(s) name
     * @param bool         $delete Whether to remove the column(s) from the list
     *
     * @return self
     *
     * @noinspection PhpUnused
     *
     * @throws \RuntimeException|\UnexpectedValueException
     */
    public function excludeColumn(string $schema, string $table, array|string $column, bool $delete = false): self
    {
        if (\is_string($column)) {
            $column = [$column];
        }

        foreach ([$schema, $table, $column] as $argument) {
            if (!Sanitize::dbName($argument)) {
                throw new \UnexpectedValueException('Invalid argument provided');
            }
        }

        if ($delete) {
            (void) Query::query('INSERT IGNORE INTO `'.$this->prefix.'columns_exclude` (`schema`, `table`, `column`) VALUES (:schema, :table, :column);', [':schema' => $schema, ':table' => $table, ':column' => [$column, 'in', 'string']]);
        } else {
            (void) Query::query('DELETE FROM `'.$this->prefix.'columns_exclude` WHERE `schema`=:schema AND `table`=:table AND `column` IN (:column)', [':schema' => $schema, ':table' => $table, ':column' => [$column, 'in', 'string']]);
        }

        return $this;
    }

    /**
     * Include a column for histogram generation in case it's excluded by default.
     *
     * @param string       $schema Schema name
     * @param string       $table  Table name
     * @param array|string $column Column(s) name
     * @param bool         $delete Whether to remove the column(s) from the list
     *
     * @return self
     *
     * @noinspection PhpUnused
     *
     * @throws \RuntimeException|\UnexpectedValueException
     */
    public function includeColumn(string $schema, string $table, array|string $column, bool $delete = false): self
    {
        if (\is_string($column)) {
            $column = [$column];
        }

        foreach ([$schema, $table, $column] as $argument) {
            if (!Sanitize::dbName($argument)) {
                throw new \UnexpectedValueException('Invalid argument provided');
            }
        }

        if ($delete) {
            (void) Query::query('INSERT IGNORE INTO `'.$this->prefix.'columns_include` (`schema`, `table`, `column`) VALUES (:schema, :table, :column);', [':schema' => $schema, ':table' => $table, ':column' => [$column, 'in', 'string']]);
        } else {
            (void) Query::query('DELETE FROM `'.$this->prefix.'columns_include` WHERE `schema`=:schema AND `table`=:table AND `column` IN (:column)', [':schema' => $schema, ':table' => $table, ':column' => [$column, 'in', 'string']]);
        }

        return $this;
    }

    /**
     * Set a number of buckets for histograms when using ANALYZE in MySQL 8+.
     *
     * @param string       $schema  Schema name
     * @param array|string $table   Table name(s). If empty string or array - will update all tables.
     * @param int          $buckets Number of buckets for histograms
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @throws \RuntimeException|\UnexpectedValueException
     */
    public function setBuckets(string $schema, array|string $table = [], int $buckets = 100): self
    {
        $this->schemaTableChecker($schema, $table);
        // Negative values do not make sense in this case, so reverting them to 0 for consistency
        if ($buckets < 1) {
            $buckets = 1;
        }

        // Values over 100 do not make sense either, so reverting them to default 10
        if ($buckets > 1024) {
            $buckets = 1024;
        }

        $table = $this->normalizeTable($table);
        (void) Query::query('UPDATE `'.$this->prefix.'tables` SET `analyze_histogram_buckets`=:value WHERE `schema`=:schema'.($table === [] ? '' : 'AND `table` IN (:table)').';', [
            ':schema' => $schema,
            ':table' => [$table, 'in', 'string'],
            ':value' => [$buckets, 'int'],
        ]);

        return $this;
    }

    /**
     * Set the number of days to wait since the previous run of an `$action`. Unless the designated amount of time has passed, the action will not be suggested for the table.
     *
     * @param string       $schema Schema name
     * @param array|string $table  Table name(s). If empty string or array - will update all tables.
     * @param string       $action Action name
     * @param int          $days   Number of days
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function setDays(string $schema, array|string $table, #[ExpectedValues(['analyze', 'check', 'optimize'])] string $action, int $days): self
    {
        $this->schemaTableChecker($schema, $table);
        $table = $this->normalizeTable($table);
        if (!\in_array($action, ['analyze', 'check', 'optimize'], strict: true)) {
            throw new \UnexpectedValueException('Unsupported action type `'.$action.'`');
        }

        if ($days < 1) {
            $days = 1;
        }

        (void) Query::query('UPDATE `'.$this->prefix.'tables` SET `'.$action.'_days_delay`=:value WHERE `schema`=:schema'.($table === [] ? '' : 'AND `table` IN (:table)').';', [
            ':schema' => $schema,
            ':table' => [$table, 'in', 'string'],
            ':value' => [$days, 'int'],
        ]);

        return $this;
    }

    /**
     * Enable or disable various options to fine-tune the global behavior of the library.
     *
     * @param string $setting Setting name
     * @param bool   $flag    Enable flag
     *
     * @return $this
     *
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function setGlobalFineTune(#[ExpectedValues(['prefer_compressed', 'prefer_extended', 'compress_auto_run', 'repair_auto_run', 'use_flush'])] string $setting, bool $flag): self
    {
        if (!\in_array($setting, ['prefer_compressed', 'prefer_extended', 'compress_auto_run', 'repair_auto_run', 'use_flush'], strict: true)) {
            throw new \UnexpectedValueException('Unsupported setting `'.$setting.'`');
        }

        (void) Query::query('UPDATE `'.$this->prefix.'settings` SET `value`=:value WHERE `setting`=:setting;', [
            ':setting' => $setting,
            ':value' => (int) $flag,
        ]);

        return $this;
    }

    /**
     * Setup details for the database variable that needs to be updated to enable maintenance mode for the service.
     *
     * @param string|null $schema         Schema name
     * @param string|null $table          Table name
     * @param string|null $setting_column Column, where to search for the setting
     * @param string|null $setting_name   Setting name
     * @param string|null $value_column   Column, where the value of the setting is stored
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @throws \RuntimeException|\UnexpectedValueException
     */
    public function setMaintenance(?string $schema = null, ?string $table = null, ?string $setting_column = null, ?string $setting_name = null, ?string $value_column = null): self
    {
        foreach ([$schema, $table, $setting_column, $setting_name, $value_column] as $argument) {
            if (
                $argument !== null
                && !Sanitize::dbName($argument)
            ) {
                throw new \UnexpectedValueException('Invalid maintenance argument provided');
            }
        }

        $queries = [];
        $queries[] = [
            'UPDATE `'.$this->prefix."settings` SET `value`=:value WHERE `setting`='maintenance_schema_name';",
            [
                ':value' => [$schema ?? null, $schema === null ? 'null' : 'string'],
            ],
        ];
        $queries[] = [
            'UPDATE `'.$this->prefix."settings` SET `value`=:value WHERE `setting`='maintenance_table_name';",
            [
                ':value' => [$table ?? null, $table === null ? 'null' : 'string'],
            ],
        ];
        $queries[] = [
            'UPDATE `'.$this->prefix."settings` SET `value`=:value WHERE `setting`='maintenance_setting_column';",
            [
                ':value' => [$setting_column ?? null, $setting_column === null ? 'null' : 'string'],
            ],
        ];
        $queries[] = [
            'UPDATE `'.$this->prefix."settings` SET `value`=:value WHERE `setting`='maintenance_setting_name';",
            [
                ':value' => [$setting_name ?? null, $setting_name === null ? 'null' : 'string'],
            ],
        ];
        $queries[] = [
            'UPDATE `'.$this->prefix."settings` SET `value`=:value WHERE `setting`='maintenance_value_column';",
            [
                ':value' => [$value_column ?? null, $value_column === null ? 'null' : 'string'],
            ],
        ];
        (void) Query::query($queries);

        return $this;
    }

    /**
     * Set a threshold for number rows in the INNODB_FT_DELETED table to suggest FULLTEXT-only OPTIMIZE.
     *
     * @param string       $schema    Schema name
     * @param array|string $table     Table name(s). If empty string or array - will update all tables.
     * @param int          $threshold Rows threshold
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @throws \RuntimeException|\UnexpectedValueException
     */
    public function setOptimizeDeletedThreshold(string $schema, array|string $table = [], int $threshold = 10000): self
    {
        $this->schemaTableChecker($schema, $table);
        // Negative values do not make sense in this case, so reverting them to 0 for consistency
        if ($threshold < 0) {
            $threshold = 0;
        }

        $table = $this->normalizeTable($table);
        (void) Query::query('UPDATE `'.$this->prefix.'tables` SET `optimize_fulltext_deleted`=:value WHERE `schema`=:schema'.($table === [] ? '' : 'AND `table` IN (:table)').';', [
            ':schema' => $schema,
            ':table' => [$table, 'in', 'string'],
            ':value' => [$threshold, 'int'],
        ]);

        return $this;
    }

    /**
     * Enable or disable automatic run of certain actio types.
     *
     * @param string       $schema Schema name
     * @param array|string $table  Table name(s). If empty string or array - will update all tables.
     * @param string       $action Action name
     * @param bool         $flag   Enable flag
     *
     * @return $this
     *
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function setRun(string $schema, array|string $table, #[ExpectedValues(['analyze', 'check', 'fulltext_rebuild', 'optimize'])] string $action, bool $flag): self
    {
        $this->schemaTableChecker($schema, $table);
        $table = $this->normalizeTable($table);
        if (!\in_array($action, ['analyze', 'check', 'fulltext_rebuild', 'optimize'], strict: true)) {
            throw new \UnexpectedValueException('Unsupported action type `'.$action.'`');
        }

        (void) Query::query('UPDATE `'.$this->prefix.'tables` SET `'.$action.'_auto_run`=:value WHERE `schema`=:schema'.($table === [] ? '' : 'AND `table` IN (:table)').';', [
            ':schema' => $schema,
            ':table' => [$table, 'in', 'string'],
            ':value' => (int) $flag,
        ]);

        return $this;
    }

    /**
     * Enable or disable suggestion of certain action types.
     *
     * @param string       $schema Schema name
     * @param array|string $table  Table name(s). If empty string or array - will update all tables.
     * @param string       $action Action type
     * @param bool         $flag   Enable flag
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function setSuggest(string $schema, array|string $table, #[ExpectedValues(['analyze', 'check', 'compress', 'optimize'])] string $action, bool $flag): self
    {
        $this->schemaTableChecker($schema, $table);
        $table = $this->normalizeTable($table);
        if (!\in_array($action, ['analyze', 'check', 'compress', 'optimize'], strict: true)) {
            throw new \UnexpectedValueException('Unsupported action type `'.$action.'`');
        }

        (void) Query::query('UPDATE `'.$this->prefix.'tables` SET `'.$action.'_suggest`=:value WHERE `schema`=:schema'.($table === [] ? '' : 'AND `table` IN (:table)').';', [
            ':schema' => $schema,
            ':table' => [$table, 'in', 'string'],
            ':value' => (int) $flag,
        ]);

        return $this;
    }

    /**
     * Enable or disable various options to fine-tune the per-table behavior of the library.
     *
     * @param string       $schema  Schema name
     * @param array|string $table   Table name(s). If empty string or array - will update all tables.
     * @param string       $setting Setting name
     * @param bool         $flag    Enable flag
     *
     * @return $this
     *
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function setTableFineTune(string $schema, array|string $table, #[ExpectedValues(['use_checksum', 'exact_rows', 'only_if_changed', 'analyze_histogram', 'analyze_histogram_auto'])] string $setting, bool $flag): self
    {
        $this->schemaTableChecker($schema, $table);
        $table = $this->normalizeTable($table);
        if (!\in_array($setting, ['use_checksum', 'exact_rows', 'only_if_changed', 'analyze_histogram', 'analyze_histogram_auto'], strict: true)) {
            throw new \UnexpectedValueException('Unsupported setting `'.$setting.'`');
        }

        (void) Query::query('UPDATE `'.$this->prefix.'tables` SET `'.$setting.'`=:value WHERE `schema`=:schema'.($table === [] ? '' : 'AND `table` IN (:table)').';', [
            ':schema' => $schema,
            ':table' => [$table, 'in', 'string'],
            ':value' => (int) $flag,
        ]);

        return $this;
    }

    /**
     * Set a threshold for fragmentation of table data. If the current value is equal or greater - table will be suggested for OPTIMIZE.
     *
     * @param string       $schema    Schema name
     * @param array|string $table     Table name(s). If empty string or array - will update all tables.
     * @param float        $threshold Fragmentation threshold
     *
     * @return $this
     *
     * @throws \RuntimeException|\UnexpectedValueException
     */
    public function setThresholdFragmentation(string $schema, array|string $table = [], float $threshold = 10.0): self
    {
        $this->schemaTableChecker($schema, $table);
        // Negative values do not make sense in this case, so reverting them to 0 for consistency
        if ($threshold < 0) {
            $threshold = 0.0;
        }

        // Values over 100 do not make sense either, so reverting them to default 10
        if ($threshold > 100) {
            $threshold = 10.0;
        }

        $table = $this->normalizeTable($table);
        (void) Query::query('UPDATE `'.$this->prefix.'tables` SET `threshold_fragmentation`=:value WHERE `schema`=:schema'.($table === [] ? '' : 'AND `table` IN (:table)').';', [
            ':schema' => $schema,
            ':table' => [$table, 'in', 'string'],
            ':value' => [$threshold, 'float'],
        ]);

        return $this;
    }

    /**
     * Set a threshold for delta for the number of rows in the table compared to the last run. If the current value is equal or greater - table will be suggested for CHECK and ANALYZE commands.
     *
     * @param string       $schema    Schema name
     * @param array|string $table     Table name(s). If empty string or array - will update all tables.
     * @param int          $threshold Rows threshold
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @throws \RuntimeException|\UnexpectedValueException
     */
    public function setThresholdRowsDelta(string $schema, array|string $table = [], int $threshold = 10000): self
    {
        $this->schemaTableChecker($schema, $table);
        // Negative values do not make sense in this case, so reverting them to 0 for consistency
        if ($threshold < 0) {
            $threshold = 0;
        }

        $table = $this->normalizeTable($table);
        (void) Query::query('UPDATE `'.$this->prefix.'tables` SET `threshold_rows_delta`=:value WHERE `schema`=:schema'.($table === [] ? '' : 'AND `table` IN (:table)').';', [
            ':schema' => $schema,
            ':table' => [$table, 'in', 'string'],
            ':value' => [$threshold, 'int'],
        ]);

        return $this;
    }

    /**
     * Set a threshold for delta for the number of rows in the table compared to the last run. If the current value is equal or greater - table will be suggested for CHECK and ANALYZE commands.
     *
     * @param string       $schema    Schema name
     * @param array|string $table     Table name(s). If empty string or array - will update all tables.
     * @param float        $threshold Rows threshold
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @throws \RuntimeException|\UnexpectedValueException
     */
    public function setThresholdSizeChange(string $schema, array|string $table = [], float $threshold = 25.00): self
    {
        $this->schemaTableChecker($schema, $table);
        // Negative values do not make sense in this case, so reverting them to 0 for consistency
        if ($threshold < 0) {
            $threshold = 0.0;
        }

        // Values over 100 do not make sense either, so reverting them to default 10
        if ($threshold > 100) {
            $threshold = 10.0;
        }

        $table = $this->normalizeTable($table);
        (void) Query::query('UPDATE `'.$this->prefix.'tables` SET `threshold_size_change`=:value WHERE `schema`=:schema'.($table === [] ? '' : 'AND `table` IN (:table)').';', [
            ':schema' => $schema,
            ':table' => [$table, 'in', 'string'],
            ':value' => [$threshold, 'float'],
        ]);

        return $this;
    }
}
