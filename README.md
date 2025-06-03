# Database Tables Maintainer

This library is an evolution of [Optimize Tables](https://github.com/Simbiat/optimize-tables) developed to suggest and optionally run various maintenance tasks on tables in MySQL and MariaDB (potentially, other forks, but not tested). While MS SQL has its Maintenance Plan Wizard, there is nothing like that for MySQL.

# Benefits

One may think that simply getting a list of tables and running `OPTIMIZE` against them is enough, but in reality, it's not that simple:

- Not all engines support all commands, including OPTIMIZE.
- There are some special parameters that may improve OPTIMIZE results in the case of FULLTEXT indexes.
- It's useful to periodically run `CHECK` to avoid potential corruption of tables, but running them too frequently may affect service availability.
- ANALYZE is quite a useful command as well, allowing to update MySQL statistics that may improve some of the SELECT queries, which may not be needed in case of `OPTIMIZE`.
- MariaDB 10.4+ and MySQL 8.0+ also support histograms that may improve SELECT in cases when a column does not have indexes for some reason.
- No matter how useful these commands are, there are cases when you do not need to run them, especially on large tables, since they may take quite some time to complete. The simplest case: there have been no or very few changes since the last time the OPTIMIZE was run.
- FULLTEXT indexes may also require rebuild in the case of certain server settings changing.

This library aims to cover all these points in as smart a manner as was possible at the moment of writing. For details, refer to this readme or comments in the code.

# Pre-requisites

- [DB Query](https://github.com/Simbiat/db-query)
- [DB Manager](https://github.com/Simbiat/db-manager)
- PHP 8.4+ with MBString enabled
- MySQL or MariaDB with read access to `information_schema` (and optionally `mysql`) schema and read-write access to schema from `PDO` object

# Installation

1. Download (manually or through composer).
2. Establish DB connection using [DB Pool](https://github.com/Simbiat/db-pool) library or passing a `PDO` object to `Installer`'s constructor.
3. Install:

```php
(new \Simbiat\Database\Maintainer\Installer($dbh))->install();
```

This will create a few tables in the database with a `maintainer__` prefix by default. If a different prefix is desired, check [customization documentation](doc/Settings.md). Further documentation will user `maintainer__` prefix, when referencing respective tables.

# Usage

* [Analysis and automation](doc/Analyzer.md)
* [Manual operations](doc/Commander.md)
* [Customization](doc/Settings.md)