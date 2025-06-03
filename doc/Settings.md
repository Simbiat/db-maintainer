# Customization

###### Note: all methods in `Settings` class return `self`, thus can be chained together for convenience. Exceptions will be thrown in case of failure to set a setting.

## Traits

All classes in the library support two arguments in constructor:

- `\PDO|null $dbh = null` - `PDO` object to use for database communication. If `null` (default) will try to use [DB Pool](https://github.com/Simbiat/db-pool) connection, and will throw an exception if that connection is not present.
- `string $prefix = 'maintainer__'` - prefix to be used for all tables for easier identification. Ensure that it is passed every time to all respective constructors if a custom one is being used.

For brevity, both arguments will be omitted in further code examples.

All classes also have the following two methods available in them (can be called from any class):

- `getSettings()` - will provide an array of current settings for the library, as well as current FULLTEXT settings for both InnoDB and MyISAM tables.
- `getFeatures()` - will provide an array of features that may be required for certain functionality:
    - `analyze_persistent` - indicates if "persistent" or engine-independent statistics are used. `false` for MySQL or for MariaDB if `use_stat_tables` is set to `never`, otherwise `true`.
    - `skip_persistent` - indicates that "persistent" or engine-independent statistics are generated during regular run of ANALYZE commands. `false` for MySQL or for MariaDB if `use_stat_tables` is set to `never`, `prefereably` or `complementary`, otherwise `true`.
    - `histogram` - indicates if histograms are supported. `true` for MySQL 8+, otherwise `false`.
    - `auto_histogram` - indicates if automatic histogram update can be enabled for MySQL tables. `true` for MySQL 8.4+, otherwise `false`.
    - `file_per_table` - indicates if `file_per_table` is used for InnoDB engine.
    - `page_compression` - indicates if InnoDB page compression is supported. `true` for MariaDB 10.6+, otherwise `false`.
    - `set_global` - indicates if the current user can run `SET GLOBAL`.
    - `mariadb` - indicates if the current database is MariaDB.
    - `can_flush` - indicates if the current user can run `FLUSH`.
    - `can_flush_optimizer` - indicates if current user can run `FLUSH OPTIMIZER_COSTS` (only MySQL 8+).

## Global settings

Most of the global library settings that are settings applied to all tables can be changed using this method:

```php
(new \Simbiat\Database\Maintainer\Settings())->setGlobalFineTune(string $setting, bool $flag);
```

`$flag` indicates whether you are enabling the setting or disabling it. Settings that can be toggled like this are the following:

- `prefer_compressed` - whether to prefer the COMPRESSED row format over DYNAMIC for InnoDB tables if both formats are available.
- `prefer_extended` - whether to prefer the EXTENDED option for CHECK and REPAIR commands.
- `compress_auto_run` - whether to run table compression command if one is suggested for a table and `autoProcess` is triggered. Global, and not per-table, since compression is enabled for a table only once.
- `repair_auto_run` - whether to run REPAIR automatically if `autoProcess` is called. REPAIR will be run if it was previously suggested or if CHECK detected failure. Global, and not per-table, since in most scenarios you would, actually, prefer to run REPAIR manually.
- `use_flush` - whether to include FLUSH command at the end of `autoProcess` and `getCommands` (if respective permission is available).

All these settings are stored in `maintainer__settings` table.

### setMaintenance

The library allows putting your system to maintenance mode by updating a flag in your database, which can be useful if the system is public-facing and capable of showing to users that it's under maintenance. Use

```php
(new \Simbiat\Database\Maintainer\Settings())->setMaintenance(?string $schema = null, ?string $table = null, ?string $setting_column = null, ?string $setting_name = null, ?string $value_column = null);
```

To make the setup. Note that while all five arguments are technically optional, you **will** need all of them for the update to work. Here's the explanation of the arguments:

- `$schema` - schema (database) name
- `$table` - table name
- `$setting_column` -column, where to search for the setting
- `$setting_name` - setting name
- `$value_column`- column, where the value of the setting is stored

If all the settings are provided and valid, then maintenance mode will be enabled (set to `1`) before running anything (and will throw exception enabling the mode fails), and then disabled (set to `0`) after all actions are completed. Here's what the SQL query will look like for enabling the maintenance mode if arguments' names are used for respective elements of it:

```sql
UPDATE `schema`.`table` SET `value_column` = 1 WHERE `setting_column` = 'setting_name';
```

All these settings are stored in `maintainer__settings` table.

## Per-table settings

While defaults may be enough for most cases, some settings can be adjusted on a per-table basis. All the following methods require a schema (database) name (`$schema`) and an optional table name (as a string) or names (as an array). If `$table` is empty, then setting will be applied to all tables from the schema currently present in `maintainer__tables` table. This table is populated when `suggest()` method is run.

All these settings are stored in `maintainer__tables` table.

### setSuggest

```php
(new \Simbiat\Database\Maintainer\Settings())->setSuggest(string $schema, string|array $table, string $action, bool $flag);
```

It will allow toggling suggestion of various actions on tables to be suggested. Actions supported:

- `check` - will suggest CHECK. Default is `1`, that is enabled.
- `analyze` - will suggest ANALYZE. Default is `1`, that is enabled.
- `optimize` - will suggest OPTIMIZE. Default is `1`, that is enabled.
- `compress` - will suggest compression. Default is `1`, that is enabled. While compression may result in some loss of performance, on modern systems it is usually negligible, so not using it may be beneficial only for huge tables.

### setRun

```php
(new \Simbiat\Database\Maintainer\Settings())->setRun(string $schema, string|array $table, string $action, bool $flag);
```

Will allow toggling automatic running (execution) of suggested actions, when `autoProcess()` is triggered. Actions supported:

- `check` - will auto-run CHECK. Default is `0`, that is disabled. This is as a precaution, since CHECK *can* block table or even the whole database (in case of certain InnoDB failures).
- `analyze` - will auto-run ANALYZE. Default is `1`, that is enabled.
- `optimize` - will auto-run OPTIMIZE. Default is `1`, that is enabled.
- `fulltext_rebuild` - will rebuild FULLTEXT indexes for a table if FULLTEXT settings change was detected. Default is `0`, that is disabled, since rebuild of the index can take a long time.

### setDays

```php
(new \Simbiat\Database\Maintainer\Settings())->setDays(string $schema, string|array $table, string $action, int $days);
```

Will control the minimum number of days that need to pass since the last time the action was run. If analysis is run before the number of days has passed, action will not be suggested. Actions supported:

- `check` - default is `30`. CHECK can block a table or whole database in case of failures, so we definitely do not want to run it too often to reduce that risk.
- `analyze` - default is `14`. ANALYZE can update optimizer plans, so it may be a good idea to run it more frequently.
- `optimize` - default is `30`. OPTIMIZE can take a long time for huge InnoDB tables, so we may not want to run it too often, especially since fragmentation itself usually does not affect performance.

### setThresholdFragmentation

```php
(new \Simbiat\Database\Maintainer\Settings())->setThresholdFragmentation(string $schema, string|array $table = [], float $threshold = 10.0);
```

Will set a minimum fragmentation level to suggest OPTIMIZE for a table.

### setThresholdRowsDelta

```php
(new \Simbiat\Database\Maintainer\Settings())->setThresholdRowsDelta(string $schema, string|array $table = [], int $threshold = 10000);
```

Will set a minimum rows delta for a table. When suggesting CHECK and ANALYZE, the library will compare the difference in the number of rows between the current state and state at the last time CHECK or ANALYZE was run. If the absolute value is equal or more than the set threshold — action will be suggested (if other conditions are satisfied as well).

### setBuckets

```php
(new \Simbiat\Database\Maintainer\Settings())->setBuckets(string $schema, string|array $table = [], int $buckets = 100);
```

It will set a number of buckets for histograms when using ANALYZE in MySQL 8+.

### setTableFineTune

```php
(new \Simbiat\Database\Maintainer\Settings())->setTableFineTune(string $schema, string|array $table = [], string $setting, bool $flag);
```

Enables or disables various settings per table. Settings supported:

- `only_if_changed` - if `1` (default) will only suggest CHECK and ANALYZE if a table was changed. Chang is determined on a combination of factors: update date from `information_schema`/`mysql` if available, checksum if used and available for last time an action was run and/or difference in rows compared to the threshold set for the table.
- `exact_rows` - if `1` will run a `SELECT COUNT(*)` to determine exact number of rows for respective table. Default is `0`, since results in performance hit when running `updateTables()` for the first time during the day, and is, practically, needed only for InnoDB tables that rarely get INSERT or DELETE.
- `use_checksum` - if `1` will calculate CHECKSUM for the table to use to determine if it has changed. Default is `0`, since results in performance hit when running `updateTables()`, and is useful only for table that do not get INSERT/DELETE often (or their number is almost equal), but get a lot of UPDATE queries actually changing contents. CHECKSUM will not be generated if a table currently has no rows (since CHECKSUM will be empty anyway).
- `analyze_histogram` - if `1` will generate histogram/persistent statistics for a table, when ANALYZE is run. Default is `0`, since this may result in performance hit when running ANALYZE.
- `analyze_histogram_auto` - if `1` will use `AUTO UPDATE` when triggering ANALYZE for histograms in MySQL 8.4+, thus excluding them from manual generation on next runs. Default is `0`, since this is MySQL 8.4+ only and at the time of writing MANUAL is still the default there.

## Columns for histograms

Histogram statistics (or persistent statistics or engine-independent statistics, run as part of ANALYZE) can speed up queries considerably, but their collection does not run automatically by default, and running it on all columns can be costly. As such, the library excludes a lot of columns based on various criteria:

- Geometry data-types (`GEOMETRY`, `POINT`, `LINESTRING`, `POLYGON`, `MULTIPOINT`, `MULTILINESTRING`, `MULTIPOLYGON`, `GEOMETRYCOLLECTION`) are not supported at all.
- `JSON` data-type is not supported at all.
- Text (`TINYTEXT`, `TEXT`, `MEDIUMTEXT`, `LONGTEXT`), blob (`TINYBLOB`, `BLOB`, `MEDIUMBLOB`, `LONGBLOB`) and binary (`BINARY`, `VARBINARY`) data types are technically supported but are generally not used in WHERE/GROUP/JOIN, often are long or stored in binary, so do not benefit from histograms.
- `BIT` data type is usually used for flags or bitmasks and has low cardinality, but it is also stored as binary, so little to no benefit from histograms.
- `YEAR` data type has low cardinality by design, minimal benefit from histograms.
- While the other date and time types (`DATE`, `TIME`, `DATETIME`, `TIMESTAMP`) _may_ benefit from histograms in some cases, they are niche, due to the types usually used in range comparisons, which work better with indexes.
- `UUID` is MariaDB specific and generally implies sequential and somewhat uniform values, thus do not benefit from histograms.
- Integers with `AUTO_INCREMENT` mean sequential and uniform data, no benefit from histograms.
- `CURRENT_TIMESTAMP` (either as default or on update) implies sequential and likely uniform data or data changing frequently, little to no benefit from histograms. Technically should be excluded through date/time data types, but excluding explicitly as a precaution.
- Generated columns are, technically, supported, but they may be changing frequently depending on what expression they use, so most likely not good candidates.
- If the maximum length is too big (>64), we are likely dealing with text (like description columns) or otherwise non-uniform data (like JSON in VARCHAR) or generally data with too much variance. Either of these cases is unlikely to benefit from histograms.
- Columns using `TINYINT(1)` or `CHAR(1)` to `CHAR(4)` or `VARCHAR(1)` to `VARCHAR(4)` are often used as flags, including but not limited to boolean values. This implies low cardinality, which benefits little from histograms.
- Columns that are part of an index usually do not benefit from histograms either, and especially if they are the leftmost column in a multi-column index. Unique indexes with just one column also do not support histograms.
- MySQL 8.4+ supports auto-generation of the histograms based on InnoDB persistent statistics settings. This setting is stored per column, that's why we can exclude those that are already auto-updated (if `analyzy_histogram_auto` is set to `1`).

If the above criteria excludes a column that you want to generate histograms for or does not exclude a column for which you do not want to generate histograms, you can use

```php
(new \Simbiat\Database\Maintainer\Settings())->excludeColumn(string $schema, string $table, string|array $column, bool $delete = false);
```

To explicitly exclude a column or

```php
(new \Simbiat\Database\Maintainer\Settings())-includeColumn(string $schema, string $table, string|array $column, bool $delete = false);
```

To explicitly include a column. You can pass either one column name as a string or a list as an array. If a column needs to be removed from any of the lists, pass `$delete` as `true`.

###### Note that if a column is present in both lists, it will be excluded.

The lists are stored in `maintainer__columns_exclude` and `maintainer__columns_include` tables respectively.