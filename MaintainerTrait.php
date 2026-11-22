<?php

declare(strict_types=1);

namespace Simbiat\Database\Maintainer;

use Simbiat\Database\Query;
use Simbiat\StringHelpers\Sanitize;

/**
 * Collection of methods shared by classes in Maintainer namespace.
 */
trait MaintainerTrait
{
    /**
     * PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     *
     * @var \PDO|null
     */
    private ?\PDO $dbh = null;

    /**
     * PDO Cron database prefix. Only Latin characters, underscores, dashes, and numbers are allowed. Maximum 53 symbols.
     *
     * @var string
     */
    private string $prefix = 'maintainer__' {
        /**
         * @noinspection PhpMethodNamingConventionInspection https://youtrack.jetbrains.com/issue/WI-81560
         */
        set {
            if (!Sanitize::dbName($value, allow_empty: true, max_length: 49)) {
                throw new \UnexpectedValueException('Invalid database prefix');
            }

            $this->prefix = $value;
        }
    }

    /**
     * List of supported features.
     *
     * @var Features
     */
    private Features $features;

    /**
     * Library settings.
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * Class constructor.
     *
     * @param \PDO|null $dbh    PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @param string    $prefix Maintainer database prefix.
     *
     * @throws \RuntimeException
     */
    private function init(?\PDO $dbh = null, string $prefix = 'maintainer__'): void
    {
        // Check that a database connection is established
        if ($dbh !== null) {
            $this->dbh = $dbh;
            // Establish it, if possible
            new Query($dbh);
        }

        $this->prefix = $prefix;
    }

    /**
     * Helper function to normalize table name(s).
     *
     * @param array<string>|string $table
     *
     * @return array<string>
     */
    private function normalizeTable(array|string $table = []): array
    {
        if (\is_string($table)) {
            return Sanitize::whiteString($table)
                ? []
                : [$table];
        }

        return $table;
    }

    /**
     * Sanitize schema and optional table name to prevent injection.
     *
     * @param string       $schema Schema name
     * @param array|string $table  Optional table name(s)
     *
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    private function schemaTableChecker(string $schema, array|string $table = []): void
    {
        if (!Sanitize::dbName($schema)) {
            throw new \UnexpectedValueException('Invalid database schema `'.$schema.'`');
        }

        if (\preg_match('/^information_schema|performance_schema|mysql|sys$/ui', $schema) === 1) {
            throw new \UnexpectedValueException('System schema `'.$schema.'` is not supported');
        }

        $table = $this->normalizeTable($table);
        foreach ($table as $table_name) {
            if (!Sanitize::dbName($table_name)) {
                throw new \UnexpectedValueException('Invalid table name `'.$table_name.'`');
            }
        }
    }
}
