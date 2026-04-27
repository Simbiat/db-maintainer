<?php
declare(strict_types = 1);

namespace Simbiat\Database\Maintainer;

use Simbiat\Database\Manage;
use Simbiat\Database\Query;

/**
 * Installer class for the Maintainer library.
 */
class Installer
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
     * Install the necessary tables
     * @return bool|string
     */
    public function install(): bool|string
    {
        return new \Simbiat\Database\Installer($this->dbh)::install(__DIR__.'/sql/*.sql', $this->getVersion(), 'maintainer__', $this->prefix);
    }
    
    /**
     * Get the current version of the Maintainer from the database perspective (can be different from the library version)
     * @return string
     */
    public function getVersion(): string
    {
        #Check if the settings table exists
        if (Manage::checkTable($this->prefix.'settings') === 1) {
            return Query::query('SELECT `value` FROM `'.$this->prefix.'settings` WHERE `setting`=\'version\'', return: 'value');
        }
        return '0.0.0';
    }
}