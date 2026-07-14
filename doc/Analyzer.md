# Analysis and automation

The main class of the library, that you're likely to use the most is `Analyzer`. It has several public methods that can suggest actions to be run for tables, as well as automatically run those suggestions.

For all methods below schema name (first argument) is required, table name(s) (second argument) is optional. If no table name is provided, all tables for the schema will be processed. Multiple table names can be passed in an array.

## updateTables

```php
(new \Simbiat\Database\Maintainer\Analyzer())->updateTables(string $schema, string|array $table = []);
```

This is a method that populates and updates the main `maintainer__tables` table, which stores all data about the tables. Most of the data is taken from `information_schema.TABLES` table, with a few exceptions explained in the below columns' description. If you add a new table, you'll need to run this method (or any other method calling this one) to get the new table into the list. If a table is removed — no need to do anything, since this method will remove all non-existent tables or columns. Note that there are no FOREIGN KEY constraints, so renaming (or removal) of a schema, a table or a column won't automatically propagate to this table. This is intentional, due to lack of control over underlying architecture of `information_schema` in various MySQL forks.

The method itself doesn't return anything, all the data can be found in the table:

- General information about the table:
    - `schema` - Name of the schema
    - `table` - Name of the table
    - `analyzed` - Date and time of the last analysis
    - `engine` - Table's engine
    - `row_format` - Table's row format
    - `has_fulltext` - Flag indicating if table has a FULLTEXT index
    - `page_compressed` - Flag indicating that table uses InnoDB page compression
    - `rows_current` - Current number of rows in table. Uses either `information_schema` or `COUNT(*)` if `exact_rows` is `1`
    - `update_time` - Last update time to the table at the time of analysis. Uses either `information_schema` or `mysql.innodb_table_stats`, if the user has access.
    - `data_length_current` - Current size of the data only
    - `index_length_current` - Current size of the indexes only
    - `data_free_current` - Current free space
    - `optimize_fulltext_deleted` - Count of rows for the table from INNODB_FT_DELETED. If not calculated yet (including due to lack of `SET GLOBAL` permission) or table doesn't have FULLTEXT indexes, this will be `NULL`.
- Table settings:
    - `exact_rows` - Whether to get exact row count for the table
    - `only_if_changed` - Whether so suggest action only if the table has changed since last time the action was run
    - `threshold_fragmentation` - Minimum fragmentation ratio to suggest a table for OPTIMIZE
    - `threshold_rows_delta` - Minimum delta for number of rows in the table compared to last run of a command to consider a table significantly changed
    - `threshold_size_change` - Minimum size change (in percents) to suggest a table for OPTIMIZE
    - `use_checksum` - Whether to get CHECKSUM use it to determine if there was a change
    - `analyze_suggest` - Whether ANALYZE can be suggested
    - `analyze_auto_run` - Whether ANALYZE can be run automatically
    - `analyze_days_delay` - Days to wait between runs of ANALYZE
    - `analyze_histogram` - Flag indicating whether HISTOGRAM optimization should be run when ANALYZE is suggested
    - `analyze_histogram_auto` - Flag indicating whether HISTOGRAM auto update should be enabled for the MySQL table, if available
    - `analyze_histogram_buckets` - Number of buckets to use for HISTOGRAM optimization in MySQL
    - `check_suggest` - Whether CHECK can be suggested
    - `check_auto_run` - Whether CHECK can be run automatically
    - `check_days_delay` - Days to wait between runs of CHECK
    - `optimize_suggest` - Whether OPTIMIZE can be suggested
    - `optimize_auto_run` - Whether OPTIMIZE can be run automatically
    - `optimize_days_delay` - Days to wait between runs of full OPTIMIZE
    - `optimize_fulltext_threshold` - Minimum number of rows in INNODB_FT_DELETED table to suggest FULLTEXT-only OPTIMIZE
    - `fulltext_rebuild_auto_run` - Whether FULLTEXT rebuild can be run automatically
    - `compress_suggest` - Whether compression can be suggested
- Calculated by running extra queries if the appropriate setting is enabled:
    - `rows_date` - Date when exact row count was taken. Limits calculation to once per day.
    - `checksum_current` - Optional current checksum of the table to evaluate if there was a change of data
    - `checksum_date` - Time the last checksum was taken. Limits calculation to once per day.
- Calculated by virtual column's expression:
    - `total_length_current` - Current table size
    - `fragmentation_current` - Current table fragmentation
    - `size_change` - Size change since last OPTIMIZE was run
    - `analyze_days_since` - Days since last time CHECK was run
    - `analyze_rows_delta` - Current delta of rows in table compared to the time when last ANALYZE was run
    - `check_days_since` - Days since last time CHECK was run
    - `check_rows_delta` - Current delta of rows in table compared to the time when last CHECK was run
    - `fragmentation_before` - Fragmentation value before last OPTIMIZE
    - `fragmentation_after` - Fragmentation value after last OPTIMIZE
- Flags indicating that an action is suggested:
    - `analyze` - Flag indicating whether ANALYZE was suggested to be run during last analysis
    - `check` - Flag indicating whether CHECK was suggested to be run during last analysis
    - `optimize` - Flag indicating whether full OPTIMIZE was suggested to be run during last analysis
    - `optimize_fulltext` - Flag indicating whether FULLTEXT-only OPTIMIZE was suggested to be run during last analysis
    - `fulltext_rebuild` - Flag indicating whether FULLTEXT rebuild was suggested
    - `repair` - Flag indicating whether REPAIR was suggested to be run during last analysis. Set to `1` only if CHECK has failed.
    - `compress` - Flag indicating whether compression was suggested during last analysis
- Columns populated during action runs:
    - `analyze_date` - Date when ANALYZE was run last time
    - `analyze_rows` - Number of rows in the table at the time when last ANALYZE was run
    - `analyze_checksum` - Optional checksum of the table at the time when last ANALYZE was run
    - `check_date` - Date when CHECK was run last time
    - `check_rows` - Number of rows in the table at the time when last CHECK was run
    - `check_checksum` - Optional checksum of the table at the time when last CHECK was run
    - `optimize_date` - Date when full OPTIMIZE was run last time
    - `optimize_fulltext_date` - Date when FULLTEXT-only OPTIMIZE was run last time
    - `optimize_days_since` - Days since last time CHECK was run
    - `data_length_before` - Size of data before last OPTIMIZE
    - `index_length_before` - Size of index before last OPTIMIZE
    - `data_free_before` - Size of free space before last OPTIMIZE
    - `data_length_after` - Size of data after last OPTIMIZE
    - `index_length_after` - Size of index after last OPTIMIZE
    - `data_free_after` - Size of free space after last OPTIMIZE
    - `total_length_after` - Total size after last OPTIMIZE
    - `fulltext_rebuild_date` - Date when FULLTEXT rebuild was run last time
    - `repair_date` - Date when REPAIR was run last time

The method can run for long time on initial scan of `information_schema` (due to how data the schema itself works by opening tables' handles). It'll run even longer if `exact_rows` or `use_checksum` is enabled for any table, especially a large one (that's why these settings are disabled by default).

## suggest

```php
(new \Simbiat\Database\Maintainer\Analyzer())->suggest(string $schema, string|array $table = []);
```

This is the method that you're likely to run next after or instead of updating tables (implicitly calls `updateTables()` when called). It'll analyze available data about the tables and suggest actions, running which may help maintain a healthy database, reclaim some space and even improve performance. Refer comments in code for details on the logic. This will also update `analyzed` timestamp in the `maintainer__table`.

The output of the method is an array that'll look like this (with all tables for which an action is suggested):

```php
array(1) {
  [0]=>
  array(13) {
    ["schema"]=>
    string(16) "simbiatr_simbiat"
    ["table"]=>
    string(10) "sys__files"
    ["check"]=>
    bool(true)
    ["check_auto_run"]=>
    bool(false)
    ["repair"]=>
    bool(false)
    ["compress"]=>
    bool(false)
    ["analyze"]=>
    bool(true)
    ["analyze_auto_run"]=>
    bool(true)
    ["optimize"]=>
    bool(false)
    ["optimize_auto_run"]=>
    bool(true)
    ["fulltext_rebuild"]=>
    bool(false)
    ["fulltext_rebuild_auto_run"]=>
    bool(false)
    ["analyze_histogram"]=>
    bool(true)
  }
}
```

As you can see in contains a bunch of flags indicating if an action was suggested, as well as those that are used for automated processing to determine if a respective action is allowed to be run automatically.

## autoProcess

```php
(new \Simbiat\Database\Maintainer\Analyzer())->autoProcess(string $schema, string|array $table = []);
```

This method runs `updateTables()` and `suggest()` in sequence, and then runs respective actions, if any are suggested and allowed to be run.

If details for maintenance mode are provided through `setMaintenance()`, this method will attempt to enable maintenance mode before processing. If that fails, exception will be thrown preventing execution. Maintenance mode will also be attempted to be disabled after processing regardless of any errors in-between. If disabling maintenance mode fails, this will be recorded in the output, but no exception will be thrown.

If `set_global` feature is available, `innodb_optimize_fulltext_only` and `innodb_ft_num_word_optimize` will be explicitly reset, to cover situations, when this failed during FULLTEXT optimizations (if any).

If `use_flush` is set to `1` through `setGlobalFineTune()` and the user has the necessary privilege, an appropriate FLUSH command will be executed, as well.

The output of the method will return an array that looks like this:

```php
array(2) {
  ["maintainer_general"]=>
  array(5) {
    ["maintenance_start"]=>
    bool(true)
    ["fulltext_settings_reset"]=>
    bool(true)
    ["flush"]=>
    bool(true)
    ["maintenance_end"]=>
    bool(true)
    ["timings"]=>
    array(2) {
      [30]=>
      array(2) {
        ["query"]=>
        string(47) "OPTIMIZE TABLE `simbiatr_simbiat`.`sys__files`;"
        ["time"]=>
        array(1) {
          [0]=>
          int(313761266)
        }
      }
      [34]=>
      array(2) {
        ["query"]=>
        string(84) "FLUSH LOCAL HOSTS, QUERY CACHE, TABLE_STATISTICS, INDEX_STATISTICS, USER_STATISTICS;"
        ["time"]=>
        array(1) {
          [0]=>
          int(99085)
        }
      }
    }
  }
  ["simbiatr_simbiat"]=>
  array(1) {
    ["sys__files"]=>
    array(7) {
      ["repair"]=>
      bool(false)
      ["check"]=>
      bool(false)
      ["compress"]=>
      bool(false)
      ["optimize"]=>
      bool(true)
      ["analyze_histogram"]=>
      bool(false)
      ["analyze"]=>
      bool(false)
      ["fulltext_rebuild"]=>
      bool(false)
    }
  }
}
```

The array will contain a key representing the schema with all respective tables as its keys. Each table will have a list of keys representing all possible actions. If that key is `false`, it means it was skipped or otherwise not run. If it is `true`, it means the action was successfully run. If it's a string, it means that the action failed, and the string is the respective error message.

`maintainer_general` key will contain some general information about the run. Sub-keys `maintenance_start`, `maintenance_end`, `fulltext_settings_reset`, `flush` will indicate status of respective actions with same logic as actions for tables. `timings` key will contain timings as per `\Simbiat\Database\Query` class (uses `hrtime()`, nanoseconds) for all actions that are *not* UPDATE.

## getCommands

```php
(new \Simbiat\Database\Maintainer\Analyzer())->getCommands(string $schema, string|array $table = [], bool $integrate = false, bool $flat = false);
```

In case you'd prefer to run the commands manually or through some other automation process, you can use this method to generate a list of the respective commands. Passing `$integrate` as `true` will also include commands required to update `maintainer__tables`, so that future suggestions would account for the successful runs.

> [!WARNING]  
> Note that the result will include commands even for actions that are not set for auto-running.

The output will look like this:

```php
array(7) {
  'prepare' =>
  array(1) {
    [0] =>
    string(90) "UPDATE `simbiatr_simbiat`.`sys__settings` SET `value` = 0 WHERE `setting` = 'maintenance';"
  }
  'common' =>
  array(1) {
    'simbiatr_simbiat' =>
    array(2) {
      'seo__visitors' =>
      array(2) {
        [0] =>
        string(56) "CHECK TABLE `simbiatr_simbiat`.`seo__visitors` EXTENDED;"
        [1] =>
        string(224) "UPDATE `simbiatr_simbiat`.`maintainer__tables` SET `check_date`=CURRENT_TIMESTAMP(6), `check_rows`=`rows_current`, `check_checksum`=`checksum_current`, `check`=0 WHERE `schema`='simbiatr_simbiat' AND `table`='seo__visitors';"
      }
      'cron__log' =>
      array(6) {
        [0] =>
        string(52) "CHECK TABLE `simbiatr_simbiat`.`cron__log` EXTENDED;"
        [1] =>
        string(220) "UPDATE `simbiatr_simbiat`.`maintainer__tables` SET `check_date`=CURRENT_TIMESTAMP(6), `check_rows`=`rows_current`, `check_checksum`=`checksum_current`, `check`=0 WHERE `schema`='simbiatr_simbiat' AND `table`='cron__log';"
        [2] =>
        string(92) "ANALYZE TABLE `simbiatr_simbiat`.`cron__log` PERSISTENT FOR COLUMNS (`instance`) INDEXES ();"
        [3] =>
        string(215) "UPDATE `simbiatr_simbiat`.`maintainer__tables` SET `analyze_date`=CURRENT_TIMESTAMP(6), `analyze_rows`=`rows_current`, `analyze_checksum`=`checksum_current` WHERE `schema`='simbiatr_simbiat' AND `table`='cron__log';"
        [4] =>
        string(45) "ANALYZE TABLE `simbiatr_simbiat`.`cron__log`;"
        [5] =>
        string(228) "UPDATE `simbiatr_simbiat`.`maintainer__tables` SET `analyze_date`=CURRENT_TIMESTAMP(6), `analyze_rows`=`rows_current`, `analyze_checksum`=`checksum_current`, `analyze`=0 WHERE `schema`='simbiatr_simbiat' AND `table`='cron__log';"
      }
    }
  }
  'pre_optimize' =>
  array(1) {
    [0] =>
    string(43) "SET GLOBAL innodb_optimize_fulltext_only=0;"
  }
  'optimize' =>
  array(1) {
    'simbiatr_simbiat' =>
    array(2) {
      'bic__swift' =>
      array(4) {
        [0] =>
        string(341) "UPDATE `simbiatr_simbiat`.`maintainer__tables`
                LEFT JOIN `information_schema`.`TABLES` ON `schema`=`TABLE_SCHEMA` AND `table`=`TABLE_NAME`
                SET `data_length_before`=`DATA_LENGTH`, `index_length_before`=`INDEX_LENGTH`, `data_free_before`=`DATA_FREE` WHERE `schema`='simbiatr_simbiat' AND `table`='bic__swift';"
        [1] =>
        string(47) "OPTIMIZE TABLE `simbiatr_simbiat`.`bic__swift`;"
        [2] =>
        string(569) "UPDATE `simbiatr_simbiat`.`maintainer__tables`
                LEFT JOIN `information_schema`.`TABLES` ON `schema`=`TABLE_SCHEMA` AND `table`=`TABLE_NAME`
                SET `data_length_after`=`DATA_LENGTH`, `index_length_after`=`INDEX_LENGTH`, `data_free_current`=`DATA_FREE`, `data_length_current`=`DATA_LENGTH`, `index_length_current`=`INDEX_LENGTH`, `data_free_after`=`DATA_FREE`, `optimize_date`=CURRENT_TIMESTAMP(6), `optimize`=0, `optimize_fulltext_date`=CURRENT_TIMESTAMP(6), `optimize_fulltext`=0 WHERE `schema`='simbiatr_simbiat' AND `table`='bic__swift';"
        [3] =>
        string(229) "UPDATE `simbiatr_simbiat`.`maintainer__tables` SET `analyze_date`=CURRENT_TIMESTAMP(6), `analyze_rows`=`rows_current`, `analyze_checksum`=`checksum_current`, `analyze`=0 WHERE `schema`='simbiatr_simbiat' AND `table`='bic__swift';"
      }
      'uc__users' =>
      array(9) {
        [0] =>
        string(340) "UPDATE `simbiatr_simbiat`.`maintainer__tables`
                LEFT JOIN `information_schema`.`TABLES` ON `schema`=`TABLE_SCHEMA` AND `table`=`TABLE_NAME`
                SET `data_length_before`=`DATA_LENGTH`, `index_length_before`=`INDEX_LENGTH`, `data_free_before`=`DATA_FREE` WHERE `schema`='simbiatr_simbiat' AND `table`='uc__users';"
        [1] =>
        string(46) "OPTIMIZE TABLE `simbiatr_simbiat`.`uc__users`;"
        [2] =>
        string(568) "UPDATE `simbiatr_simbiat`.`maintainer__tables`
                LEFT JOIN `information_schema`.`TABLES` ON `schema`=`TABLE_SCHEMA` AND `table`=`TABLE_NAME`
                SET `data_length_after`=`DATA_LENGTH`, `index_length_after`=`INDEX_LENGTH`, `data_free_current`=`DATA_FREE`, `data_length_current`=`DATA_LENGTH`, `index_length_current`=`INDEX_LENGTH`, `data_free_after`=`DATA_FREE`, `optimize_date`=CURRENT_TIMESTAMP(6), `optimize`=0, `optimize_fulltext_date`=CURRENT_TIMESTAMP(6), `optimize_fulltext`=0 WHERE `schema`='simbiatr_simbiat' AND `table`='uc__users';"
        [3] =>
        string(228) "UPDATE `simbiatr_simbiat`.`maintainer__tables` SET `analyze_date`=CURRENT_TIMESTAMP(6), `analyze_rows`=`rows_current`, `analyze_checksum`=`checksum_current`, `analyze`=0 WHERE `schema`='simbiatr_simbiat' AND `table`='uc__users';"
        [4] =>
        string(134) "ANALYZE TABLE `simbiatr_simbiat`.`uc__users` PERSISTENT FOR COLUMNS (`strikes`, `prefix`, `suffix`, `timezone`, `country`) INDEXES ();"
        [5] =>
        string(215) "UPDATE `simbiatr_simbiat`.`maintainer__tables` SET `analyze_date`=CURRENT_TIMESTAMP(6), `analyze_rows`=`rows_current`, `analyze_checksum`=`checksum_current` WHERE `schema`='simbiatr_simbiat' AND `table`='uc__users';"
        [6] =>
        string(60) "SET GLOBAL innodb_ft_aux_table='simbiatr_simbiat/uc__users';"
        [7] =>
        string(178) "UPDATE `maintainer__tables` SET `optimize_fulltext_deleted`=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.INNODB_FT_DELETED) WHERE `schema`='simbiatr_simbiat' AND `table`='uc__users';"
        [8] =>
        string(36) "SET GLOBAL innodb_ft_aux_table=NULL;"
      }
    }
  }
  'pre_fulltext' =>
  array(2) {
    [0] =>
    string(43) "SET GLOBAL innodb_optimize_fulltext_only=1;"
    [1] =>
    string(45) "SET GLOBAL innodb_ft_num_word_optimize=10000;"
  }
  'fulltext' =>
  array(1) {
      'simbiatr_simbiat' =>
       array(2) {
        'ffxiv__character' =>
        array(9) {
          [0] =>
          string(347) "UPDATE `simbiatr_simbiat`.`maintainer__tables`
                  LEFT JOIN `information_schema`.`TABLES` ON `schema`=`TABLE_SCHEMA` AND `table`=`TABLE_NAME`
                  SET `data_length_before`=`DATA_LENGTH`, `index_length_before`=`INDEX_LENGTH`, `data_free_before`=`DATA_FREE` WHERE `schema`='simbiatr_simbiat' AND `table`='ffxiv__character';"
          [1] =>
          string(53) "OPTIMIZE TABLE `simbiatr_simbiat`.`ffxiv__character`;"
          [2] =>
          string(575) "UPDATE `simbiatr_simbiat`.`maintainer__tables`
                  LEFT JOIN `information_schema`.`TABLES` ON `schema`=`TABLE_SCHEMA` AND `table`=`TABLE_NAME`
                  SET `data_length_after`=`DATA_LENGTH`, `index_length_after`=`INDEX_LENGTH`, `data_free_current`=`DATA_FREE`, `data_length_current`=`DATA_LENGTH`, `index_length_current`=`INDEX_LENGTH`, `data_free_after`=`DATA_FREE`, `optimize_date`=CURRENT_TIMESTAMP(6), `optimize`=0, `optimize_fulltext_date`=CURRENT_TIMESTAMP(6), `optimize_fulltext`=0 WHERE `schema`='simbiatr_simbiat' AND `table`='ffxiv__character';"
          [3] =>
          string(235) "UPDATE `simbiatr_simbiat`.`maintainer__tables` SET `analyze_date`=CURRENT_TIMESTAMP(6), `analyze_rows`=`rows_current`, `analyze_checksum`=`checksum_current`, `analyze`=0 WHERE `schema`='simbiatr_simbiat' AND `table`='ffxiv__character';"
          [4] =>
          string(102) "ANALYZE TABLE `simbiatr_simbiat`.`ffxiv__character` PERSISTENT FOR COLUMNS (`pvp_matches`) INDEXES ();"
          [5] =>
          string(222) "UPDATE `simbiatr_simbiat`.`maintainer__tables` SET `analyze_date`=CURRENT_TIMESTAMP(6), `analyze_rows`=`rows_current`, `analyze_checksum`=`checksum_current` WHERE `schema`='simbiatr_simbiat' AND `table`='ffxiv__character';"
          [6] =>
          string(67) "SET GLOBAL innodb_ft_aux_table='simbiatr_simbiat/ffxiv__character';"
          [7] =>
          string(185) "UPDATE `maintainer__tables` SET `optimize_fulltext_deleted`=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.INNODB_FT_DELETED) WHERE `schema`='simbiatr_simbiat' AND `table`='ffxiv__character';"
          [8] =>
          string(36) "SET GLOBAL innodb_ft_aux_table=NULL;"
        }
        'ffxiv__character_achievement' =>
        array(4) {
          [0] =>
          string(359) "UPDATE `simbiatr_simbiat`.`maintainer__tables`
                  LEFT JOIN `information_schema`.`TABLES` ON `schema`=`TABLE_SCHEMA` AND `table`=`TABLE_NAME`
                  SET `data_length_before`=`DATA_LENGTH`, `index_length_before`=`INDEX_LENGTH`, `data_free_before`=`DATA_FREE` WHERE `schema`='simbiatr_simbiat' AND `table`='ffxiv__character_achievement';"
          [1] =>
          string(65) "OPTIMIZE TABLE `simbiatr_simbiat`.`ffxiv__character_achievement`;"
          [2] =>
          string(587) "UPDATE `simbiatr_simbiat`.`maintainer__tables`
                  LEFT JOIN `information_schema`.`TABLES` ON `schema`=`TABLE_SCHEMA` AND `table`=`TABLE_NAME`
                  SET `data_length_after`=`DATA_LENGTH`, `index_length_after`=`INDEX_LENGTH`, `data_free_current`=`DATA_FREE`, `data_length_current`=`DATA_LENGTH`, `index_length_current`=`INDEX_LENGTH`, `data_free_after`=`DATA_FREE`, `optimize_date`=CURRENT_TIMESTAMP(6), `optimize`=0, `optimize_fulltext_date`=CURRENT_TIMESTAMP(6), `optimize_fulltext`=0 WHERE `schema`='simbiatr_simbiat' AND `table`='ffxiv__character_achievement';"
          [3] =>
          string(247) "UPDATE `simbiatr_simbiat`.`maintainer__tables` SET `analyze_date`=CURRENT_TIMESTAMP(6), `analyze_rows`=`rows_current`, `analyze_checksum`=`checksum_current`, `analyze`=0 WHERE `schema`='simbiatr_simbiat' AND `table`='ffxiv__character_achievement';"
        }
      }
  }
  'stats' =>
  array(12) {
    [0] =>
    string(61) "SET GLOBAL innodb_ft_aux_table='simbiatr_simbiat/bic__swift';"
    [1] =>
    string(179) "UPDATE `maintainer__tables` SET `optimize_fulltext_deleted`=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.INNODB_FT_DELETED) WHERE `schema`='simbiatr_simbiat' AND `table`='bic__swift';"
    [2] =>
    string(36) "SET GLOBAL innodb_ft_aux_table=NULL;"
    [3] =>
    string(60) "SET GLOBAL innodb_ft_aux_table='simbiatr_simbiat/uc__users';"
    [4] =>
    string(178) "UPDATE `maintainer__tables` SET `optimize_fulltext_deleted`=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.INNODB_FT_DELETED) WHERE `schema`='simbiatr_simbiat' AND `table`='uc__users';"
    [5] =>
    string(36) "SET GLOBAL innodb_ft_aux_table=NULL;"
    [6] =>
    string(67) "SET GLOBAL innodb_ft_aux_table='simbiatr_simbiat/ffxiv__character';"
    [7] =>
    string(185) "UPDATE `maintainer__tables` SET `optimize_fulltext_deleted`=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.INNODB_FT_DELETED) WHERE `schema`='simbiatr_simbiat' AND `table`='ffxiv__character';"
    [8] =>
    string(36) "SET GLOBAL innodb_ft_aux_table=NULL;"
    [9] =>
    string(79) "SET GLOBAL innodb_ft_aux_table='simbiatr_simbiat/ffxiv__character_achievement';"
    [10] =>
    string(197) "UPDATE `maintainer__tables` SET `optimize_fulltext_deleted`=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.INNODB_FT_DELETED) WHERE `schema`='simbiatr_simbiat' AND `table`='ffxiv__character_achievement';"
    [11] =>
    string(36) "SET GLOBAL innodb_ft_aux_table=NULL;"
  }
  'reset' =>
  array(5) {
    [0] =>
    string(49) "SET GLOBAL innodb_optimize_fulltext_only=DEFAULT;"
    [1] =>
    string(47) "SET GLOBAL innodb_ft_num_word_optimize=DEFAULT;"
    [2] =>
    string(36) "SET GLOBAL innodb_ft_aux_table=NULL;"
    [3] =>
    string(84) "FLUSH LOCAL HOSTS, QUERY CACHE, TABLE_STATISTICS, INDEX_STATISTICS, USER_STATISTICS;"
    [4] =>
    string(90) "UPDATE `simbiatr_simbiat`.`sys__settings` SET `value` = 0 WHERE `setting` = 'maintenance';"
  }
}
```

The array will have 8 keys, representing 8 phases:

1. `prepare` - phase to enable maintenance mode if the feature is set up.
2. `common` - all commands that are *not* `OPTIMIZE` split by schema and table. These can be run in parallel, if required.
3. `pre_optimize` - just 1 command to set `innodb_optimize_fulltext_only` to `0`. Empty if lacking `SET GLOBAL` permission.
4. `optimize` - all full `OPTIMIZE` commands. split by schema and table. These can be run in parallel, if required.
5. `pre_fulltext` - setting `innodb_optimize_fulltext_only` to `1` and `innodb_ft_num_word_optimize` to `10000` to enable FULLTEXT-only optimization. Empty if lacking `SET GLOBAL` permission.
6. `fulltext` - all FULLTEXT-only `OPTIMIZE` commands. split by schema and table. These can be run in parallel, if required. Empty if lacking `SET GLOBAL` permission.
7. `stats` - updating FULLTEXT statistics. Should be run *SEQUENTIALLY*! Empty if lacking `SET GLOBAL` permission.
8. `reset` - resetting all `GLOBAL` settings and, if `SET GLOBAL` permision is available, `FLUSH` command, if respective permission is avialable and disabling maintenance if the feature is set up.

This kind of splitting is done to allow processing tables in `common`, `optimize` and `fultlext` phases in parallel to save processing time. Check `writeCommandsToFiles` documentation.

## getCommandsFlat

```php
(new \Simbiat\Database\Maintainer\Analyzer())->getCommandsFlat(string $schema, string|array $table = [], bool $integrate = false);
```

Same as `getCommands` but a flat (non-associative) array will be returned with commands in order of respective phases:

```php
array(7) {
  [0]=>
  string(90) "UPDATE `simbiatr_simbiat`.`sys__settings` SET `value` = 1 WHERE `setting` = 'maintenance';"
  [1]=>
  string(53) "CHECK TABLE `simbiatr_simbiat`.`sys__files` EXTENDED;"
  [2]=>
  string(201) "UPDATE `simbiatr_simbiat`.`maintainer__tables` SET `check_date`=CURRENT_TIMESTAMP(6), `check_rows`=`rows_current`, `check_checksum`=`checksum_current`, `check`=0 WHERE `schema`='simbiatr_simbiat' AND `table`='sys__files';"
  [3]=>
  string(46) "ANALYZE TABLE `simbiatr_simbiat`.`sys__files`;"
  [4]=>
  string(209) "UPDATE `simbiatr_simbiat`.`maintainer__tables` SET `analyze_date`=CURRENT_TIMESTAMP(6), `analyze_rows`=`rows_current`, `analyze_checksum`=`checksum_current`, `analyze`=0 WHERE `schema`='simbiatr_simbiat' AND `table`='sys__files';"
  [5]=>
  string(84) "FLUSH LOCAL HOSTS, QUERY CACHE, TABLE_STATISTICS, INDEX_STATISTICS, USER_STATISTICS;"
  [6]=>
  string(90) "UPDATE `simbiatr_simbiat`.`sys__settings` SET `value` = 0 WHERE `setting` = 'maintenance';"
}
```

## writeCommandsToFiles

```php
(new \Simbiat\Database\Maintainer\Analyzer())->writeCommandsToFiles(string $schema, string|array $table = [], bool $integrate = false);
```

This will dump the commands generated by `getCommands` into files split by phases:

1. `prepare` - will be saved into `01-prepare.sql`.
2. `common` - will be saved into `02-common/database_name.table_name.sql`.
3. `pre_optimize` - will be saved into `03-pre_optimize.sql`.
4. `optimize` - will be saved into `04-optimize/database_name.table_name.sql`.
5. `pre_fulltext` - will be saved into `05-pre_fulltext.sql`.
6. `fulltext` - will be saved into `06-fulltext/database_name.table_name.sql`.
7. `stats` - will be saved into `07-stats.sql`.
8. `reset` - will be saved into `08-reset.sql`.

Then you can prepare an automation, that’ll run respective files if they exist with parallel run for `common`, `optimize` and `fulltext`. The simplest example (written by Lumo), can be found below:

```shell
#!/usr/bin/env bash
set -euo pipefail

BASE_DIR="${1:?Usage: $0 <base_dir> [threads]}"
THREADS="${2:-2}"

run_single() {
    local f="$BASE_DIR/$1"
    [[ -f "$f" ]] || return 0
    echo ">>> Running $1"
    mysql < "$f"
}

run_parallel() {
    local d="$BASE_DIR/$1"
    [[ -d "$d" ]] || return 0
    find "$d" -name '*.sql' -print0 \
        | xargs -0 -P "$THREADS" -I {} sh -c '
            echo ">>> Running $1"
            mysql < "$1"
        ' _ {}
}

run_single   "01-prepare.sql"
run_parallel  "02-common"
run_single   "03-pre_optimize.sql"
run_parallel  "04-optimize"
run_single   "05-pre_fulltext.sql"
run_parallel  "06-fulltext"
run_single   "07-stats.sql"
run_single   "08-reset.sql"

echo "✓ All stages completed."
```