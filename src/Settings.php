<?php
declare(strict_types = 1);

namespace Simbiat\Database\Maintainer;

use JetBrains\PhpStorm\ExpectedValues;
use Simbiat\Database\Query;
use function in_array;

/**
 * Class to change settings used by the Maintainer library
 */
class Settings
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
     * Enable or disable suggestion of certain action types.
     *
     * @param string       $schema Schema name
     * @param string|array $table  Table name(s). If empty string or array - will update all tables.
     * @param string       $action Action type
     * @param bool         $flag   Enable flag
     *
     * @return $this
     */
    public function setSuggest(string $schema, string|array $table, #[ExpectedValues(['analyze', 'check', 'compress', 'optimize'])] string $action, bool $flag): self
    {
        $this->schemaTableChecker($schema, $table);
        if (\is_string($table)) {
            $table = [$table];
        }
        if (in_array($action, ['analyze', 'check', 'compress', 'optimize'], true)) {
            Query::query('UPDATE `'.$this->prefix.'tables` SET `'.$action.'_suggest`=:value WHERE `schema`=:schema'.(empty($table) ? '' : 'AND `table` IN (:table)').';', [
                ':schema' => $schema,
                ':table' => [$table, 'in', 'string'],
                ':value' => (int)$flag,
            ]);
        } else {
            throw new \UnexpectedValueException('Unsupported action type `'.$action.'`');
        }
        return $this;
    }
    
    /**
     * Enable or disable automatic run of certain actio types.
     *
     * @param string       $schema Schema name
     * @param string|array $table  Table name(s). If empty string or array - will update all tables.
     * @param string       $action Action name
     * @param bool         $flag   Enable flag
     *
     * @return $this
     */
    public function setRun(string $schema, string|array $table, #[ExpectedValues(['analyze', 'check', 'fulltext_rebuild', 'optimize'])] string $action, bool $flag): self
    {
        $this->schemaTableChecker($schema, $table);
        if (\is_string($table)) {
            $table = [$table];
        }
        if (in_array($action, ['analyze', 'check', 'fulltext_rebuild', 'optimize'], true)) {
            Query::query('UPDATE `'.$this->prefix.'tables` SET `'.$action.'_auto_run`=:value WHERE `schema`=:schema'.(empty($table) ? '' : 'AND `table` IN (:table)').';', [
                ':schema' => $schema,
                ':table' => [$table, 'in', 'string'],
                ':value' => (int)$flag,
            ]);
        } else {
            throw new \UnexpectedValueException('Unsupported action type `'.$action.'`');
        }
        return $this;
    }
    
    /**
     * Set the number of days to wait since the previous run of an `$action`. Unless the designated amount of time has passed, the action will not be suggested for the table.
     *
     * @param string       $schema Schema name
     * @param string|array $table  Table name(s). If empty string or array - will update all tables.
     * @param string       $action Action name
     * @param int          $days   Number of days
     *
     * @return $this
     */
    public function setDays(string $schema, string|array $table, #[ExpectedValues(['analyze', 'check', 'optimize'])] string $action, int $days): self
    {
        $this->schemaTableChecker($schema, $table);
        if (\is_string($table)) {
            $table = [$table];
        }
        if (in_array($action, ['analyze', 'check', 'optimize'], true)) {
            if ($days < 1) {
                $days = 1;
            }
            Query::query('UPDATE `'.$this->prefix.'tables` SET `'.$action.'_days_delay`=:value WHERE `schema`=:schema'.(empty($table) ? '' : 'AND `table` IN (:table)').';', [
                ':schema' => $schema,
                ':table' => [$table, 'in', 'string'],
                ':value' => [$days, 'int'],
            ]);
        } else {
            throw new \UnexpectedValueException('Unsupported action type `'.$action.'`');
        }
        return $this;
    }
    
    /**
     * Enable or disable various options to fine-tune the per-table behavior of the library.
     *
     * @param string       $schema  Schema name
     * @param string|array $table   Table name(s). If empty string or array - will update all tables.
     * @param string       $setting Setting name
     * @param bool         $flag    Enable flag
     *
     * @return $this
     */
    public function setTableFineTune(string $schema, string|array $table, #[ExpectedValues(['use_checksum', 'exact_rows', 'only_if_changed', 'analyze_histogram', 'analyze_histogram_auto'])] string $setting, bool $flag): self
    {
        $this->schemaTableChecker($schema, $table);
        if (\is_string($table)) {
            $table = [$table];
        }
        if (in_array($setting, ['use_checksum', 'exact_rows', 'only_if_changed', 'analyze_histogram', 'analyze_histogram_auto'], true)) {
            Query::query('UPDATE `'.$this->prefix.'tables` SET `'.$setting.'`=:value WHERE `schema`=:schema'.(empty($table) ? '' : 'AND `table` IN (:table)').';', [
                ':schema' => $schema,
                ':table' => [$table, 'in', 'string'],
                ':value' => (int)$flag,
            ]);
        } else {
            throw new \UnexpectedValueException('Unsupported setting `'.$setting.'`');
        }
        return $this;
    }
    
    /**
     * Set a threshold for fragmentation of table data. If the current value is equal or greater - table will be suggested for OPTIMIZE.
     *
     * @param string       $schema    Schema name
     * @param string|array $table     Table name(s). If empty string or array - will update all tables.
     * @param float        $threshold Fragmentation threshold
     *
     * @return $this
     */
    public function setThresholdFragmentation(string $schema, string|array $table = [], float $threshold = 10.0): self
    {
        $this->schemaTableChecker($schema, $table);
        #Negative values do not make sense in this case, so reverting them to 0 for consistency
        if ($threshold < 0) {
            $threshold = 0.0;
        }
        #Values over 100 do not make sense either, so reverting them to default 10
        if ($threshold > 100) {
            $threshold = 10.0;
        }
        if (\is_string($table)) {
            $table = [$table];
        }
        Query::query('UPDATE `'.$this->prefix.'tables` SET `threshold_fragmentation`=:value WHERE `schema`=:schema'.(empty($table) ? '' : 'AND `table` IN (:table)').';', [
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
     * @param string|array $table     Table name(s). If empty string or array - will update all tables.
     * @param int          $threshold Rows threshold
     *
     * @return $this
     */
    public function setThresholdRowsDelta(string $schema, string|array $table = [], int $threshold = 10000): self
    {
        $this->schemaTableChecker($schema, $table);
        #Negative values do not make sense in this case, so reverting them to 0 for consistency
        if ($threshold < 0) {
            $threshold = 0;
        }
        if (\is_string($table)) {
            $table = [$table];
        }
        Query::query('UPDATE `'.$this->prefix.'tables` SET `threshold_rows_delta`=:value WHERE `schema`=:schema'.(empty($table) ? '' : 'AND `table` IN (:table)').';', [
            ':schema' => $schema,
            ':table' => [$table, 'in', 'string'],
            ':value' => [$threshold, 'int'],
        ]);
        return $this;
    }
    
    /**
     * Set a number of buckets for histograms when using ANALYZE in MySQL 8+
     *
     * @param string       $schema  Schema name
     * @param string|array $table   Table name(s). If empty string or array - will update all tables.
     * @param int          $buckets Number of buckets for histograms
     *
     * @return $this
     */
    public function setBuckets(string $schema, string|array $table = [], int $buckets = 100): self
    {
        $this->schemaTableChecker($schema, $table);
        #Negative values do not make sense in this case, so reverting them to 0 for consistency
        if ($buckets < 1) {
            $buckets = 1;
        }
        #Values over 100 do not make sense either, so reverting them to default 10
        if ($buckets > 1024) {
            $buckets = 1024;
        }
        if (\is_string($table)) {
            $table = [$table];
        }
        Query::query('UPDATE `'.$this->prefix.'tables` SET `analyze_histogram_buckets`=:value WHERE `schema`=:schema'.(empty($table) ? '' : 'AND `table` IN (:table)').';', [
            ':schema' => $schema,
            ':table' => [$table, 'in', 'string'],
            ':value' => [$buckets, 'int'],
        ]);
        return $this;
    }
    
    /**
     * Enable or disable various options to fine-tune the global behavior of the library.
     * @param string $setting Setting name
     * @param bool   $flag    Enable flag
     *
     * @return $this
     */
    public function setGlobalFineTune(#[ExpectedValues(['prefer_compressed', 'prefer_extended', 'compress_auto_run', 'repair_auto_run', 'use_flush'])] string $setting, bool $flag): self
    {
        if (in_array($setting, ['prefer_compressed', 'prefer_extended', 'compress_auto_run', 'repair_auto_run', 'use_flush'], true)) {
            Query::query('UPDATE `'.$this->prefix.'settings` SET `value`=:value WHERE `setting`=:setting;', [
                ':value' => (int)$flag,
                ':setting' => $setting,
            ]);
        } else {
            throw new \UnexpectedValueException('Unsupported setting `'.$setting.'`');
        }
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
     */
    public function setMaintenance(?string $schema = null, ?string $table = null, ?string $setting_column = null, ?string $setting_name = null, ?string $value_column = null): self
    {
        foreach ([$schema, $table, $setting_column, $setting_name, $value_column] as $argument) {
            if (\preg_match('/^[\w\-]{1,64}$/u', $argument) !== 1) {
                throw new \UnexpectedValueException('Invalid maintenance argument provided');
            }
        }
        $queries = [];
        $queries[] = ['UPDATE `'.$this->prefix.'settings` SET `value`=:value WHERE `setting`=\'maintenance_schema_name\';', [
            ':value' => [empty($schema) ? null : $schema, empty($schema) ? 'null' : 'string']
        ]];
        $queries[] = ['UPDATE `'.$this->prefix.'settings` SET `value`=:value WHERE `setting`=\'maintenance_table_name\';', [
            ':value' => [empty($table) ? null : $table, empty($table) ? 'null' : 'string']
        ]];
        $queries[] = ['UPDATE `'.$this->prefix.'settings` SET `value`=:value WHERE `setting`=\'maintenance_setting_column\';', [
            ':value' => [empty($setting_column) ? null : $setting_column, empty($setting_column) ? 'null' : 'string']
        ]];
        $queries[] = ['UPDATE `'.$this->prefix.'settings` SET `value`=:value WHERE `setting`=\'maintenance_setting_name\';', [
            ':value' => [empty($setting_name) ? null : $setting_name, empty($setting_name) ? 'null' : 'string']
        ]];
        $queries[] = ['UPDATE `'.$this->prefix.'settings` SET `value`=:value WHERE `setting`=\'maintenance_value_column\';', [
            ':value' => [empty($value_column) ? null : $value_column, empty($value_column) ? 'null' : 'string']
        ]];
        Query::query($queries);
        return $this;
    }
    
    /**
     * Exclude a column from histogram generation in case it's not excluded by default.
     *
     * @param string       $schema Schema name
     * @param string       $table  Table name
     * @param string|array $column Column(s) name
     * @param bool         $delete Whether to remove the column(s) from the list
     *
     * @return self
     */
    public function excludeColumn(string $schema, string $table, string|array $column, bool $delete = false): self
    {
        if (\is_string($column)) {
            $column = [$column];
        }
        foreach ([$schema, $table, $column] as $argument) {
            if (\preg_match('/^[\w\-]{1,64}$/u', $argument) !== 1) {
                throw new \UnexpectedValueException('Invalid argument provided');
            }
        }
        if ($delete) {
            Query::query('INSERT IGNORE INTO `'.$this->prefix.'columns_exclude` (`schema`, `table`, `column`) VALUES (:schema, :table, :column);', [':schema' => $schema, ':table' => $table, ':column' => [$column, 'in', 'string']]);
        } else {
            Query::query('DELETE FROM `'.$this->prefix.'columns_exclude` WHERE `schema`=:schema AND `table`=:table AND `column` IN (:column)', [':schema' => $schema, ':table' => $table, ':column' => [$column, 'in', 'string']]);
        }
        return $this;
    }
    
    /**
     * Include a column for histogram generation in case it's excluded by default.
     *
     * @param string       $schema Schema name
     * @param string       $table  Table name
     * @param string|array $column Column(s) name
     * @param bool         $delete Whether to remove the column(s) from the list
     *
     * @return self
     */
    public function includeColumn(string $schema, string $table, string|array $column, bool $delete = false): self
    {
        if (\is_string($column)) {
            $column = [$column];
        }
        foreach ([$schema, $table, $column] as $argument) {
            if (\preg_match('/^[\w\-]{1,64}$/u', $argument) !== 1) {
                throw new \UnexpectedValueException('Invalid argument provided');
            }
        }
        if ($delete) {
            Query::query('INSERT IGNORE INTO `'.$this->prefix.'columns_include` (`schema`, `table`, `column`) VALUES (:schema, :table, :column);', [':schema' => $schema, ':table' => $table, ':column' => [$column, 'in', 'string']]);
        } else {
            Query::query('DELETE FROM `'.$this->prefix.'columns_include` WHERE `schema`=:schema AND `table`=:table AND `column` IN (:column)', [':schema' => $schema, ':table' => $table, ':column' => [$column, 'in', 'string']]);
        }
        return $this;
    }
}