# Manual operations

In case it is required to execute an action on a table manually, instead of running `autoProcess()`, you can use `Commander` class' methods to do so. Note that the action will be run even if it's not suggested.

All methods except `maintenance` and `flush` have these arguments:

- `string $schema` - mandatory schema name.
- `string $table` - mandatory table name.
- `bool $integrate = false` - whether to include commands to update `maintainer__tables` to count the manual run towards future suggestions.

All methods _including_ `maintenance` and `flush` have an argument `bool $run = false` - whether to execute the commands right away. By default, methods will return an array or string (for `maintenance` and `flush`) with the respective command(s), that you can later execute manually. If `$run` is `true`, those actions will be executed right away, and a `boolean` will be returned instead (unless an exception occurs).

## compress

```php
(new \Simbiat\Database\Maintainer\Commander())->compress(string $schema, string $table, bool $integrate = false, bool $run = false, bool $prefer_compressed = false);
```

Compresses an InnoDB or MyISAM table. If page compression is supported (MariaDB 10.4+, InnoDB only), then it will be applied. If it's not supported, and `prefer_compressed` setting is enabled or `$prefer_compressed` is passed as `true` (`false` by default), then a row format will be updated to `COMPRESSED` (InnoDB only). Otherwise, if we have an InnoDB or a MyISAM table, `DYNAMIC` row format will be applied. While `DYNAMIC` technically does not compress anything, using it _may_ reduce size of some tables, and it is generally recommended.

## check

```php
(new \Simbiat\Database\Maintainer\Commander())->check(string $schema, string $table, bool $integrate = false, bool $run = false, bool $prefer_extended = false, bool $auto_repair = false);
```

Runs a `CHECK` on a InnoDB, MyISAM, Aria, Archive or CSV table. If `prefer_extended` setting is enabled or `$prefer_extended` is passed as `true` (`false` by default) `EXTENDED` option will be used. Otherwise, `MEDIUM` will be used, which will be faster. If `$auto_repair` is `true` will attempt to run `REPAIR`.

## repair

```php
(new \Simbiat\Database\Maintainer\Commander())->repair(string $schema, string $table, bool $integrate = false, bool $run = false, bool $prefer_extended = false);
```

Runs a `REPAIR` on a MyISAM, Aria, Archive or CSV table. If `prefer_extended` setting is enabled or `$prefer_extended` is passed as `true` (`false` by default) `EXTENDED` option will be used.

## analyze

```php
(new \Simbiat\Database\Maintainer\Commander())->analyze(string $schema, string $table, bool $integrate = false, bool $run = false);
```

Runs a `ANALYZE` on an InnoDB, MyISAM or Aria table.

## histogram

```php
(new \Simbiat\Database\Maintainer\Commander())->histogram(string $schema, string $table, bool $integrate = false, bool $run = false, bool $no_skip = false);
```

Runs a `ANALYZE` on an InnoDB, MyISAM or Aria table to generate histograms, if supported (`UPDATE HISTOGRAM ON` for MySQL 8+ and `PERSISTENT FOR COLUMNS` for MariaDB 10.4+), and there are applicable columns available. Will not do anything if automated generation is already enabled globally for MariaDB (`use_stat_tables` is set to `complementary` or `preferably`). For MySQL 8.4+, will generate histograms only for columns that do not have automated generation enabled for them. If `$no_skip` is `true` will force generation of histograms even if automated generation is enabled.

## optimize

```php
(new \Simbiat\Database\Maintainer\Commander())->optimize(string $schema, string $table, bool $integrate = false, bool $run = false, bool $no_skip = false);
```

Runs a `OPTIMIZE` on an InnoDB, MyISAM, Aria or Archive table. If table has FULLTEXT indexes and `set_global` feature is enabled, will additionally run `SET GLOBAL innodb_optimize_fulltext_only=1;`, `SET GLOBAL innodb_ft_num_word_optimize=10000`, trigger `OPTIMIZE` again to now update FULLTEXT indexes, and then reset the settings. Will not run FULLTEXT optimization if `innodb_optimize_fulltext_only` update fails. For InnoDB will also run ANALYZE for histograms, if supported and enabled for the table. Use `no_skip` set to `true` to force generation of histograms even if automated generation is already enabled (same as with `analyze()`).

## fulltextRebuild

```php
(new \Simbiat\Database\Maintainer\Commander())->fulltextRebuild(string $schema, string $table, bool $integrate = false, bool $run = false);
```

Rebuilds a FULLTEXT index in an InnoDB, MyISAM, Aria or Mroonga database. In practice, drops and re-adds every FULLTEXT index one-by-one.

## flush

```php
(new \Simbiat\Database\Maintainer\Commander())->flush(bool $run = false);
```

If supported and respective permissions are available runs either `FLUSH OPTIMIZER_COSTS;` for MySQL 8+ or `FLUSH LOCAL HOSTS, QUERY CACHE, TABLE_STATISTICS, INDEX_STATISTICS, USER_STATISTICS;` for MariaDB. The former refreshes optimizer cache, especially useful after `ANALYZE`, while the latter can free-up some memory.

## maintenance

```php
(new \Simbiat\Database\Maintainer\Commander())->maintenance(bool $activate = true, bool $run = false);
```

Activates or deactivates maintenance mode, if setup properly through `Settings` class. Action is dependent on value of `$activate`, which is `true` by default.