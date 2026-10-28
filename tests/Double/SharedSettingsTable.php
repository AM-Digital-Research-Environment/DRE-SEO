<?php
declare(strict_types=1);

namespace DRESeo\Test\Double;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;

/**
 * The settings table two requests share, behind the MySQL connection the queue
 * locks and writes through. Each request keeps its own (stale) Settings cache,
 * as Omeka's does.
 */
final class SharedSettingsTable extends Connection
{
    /** @var array<string,string> id => JSON value */
    public array $rows = [];

    /** @var string[] */
    public array $locks = [];

    public function getDatabasePlatform(): object
    {
        if (!class_exists('Doctrine\DBAL\Platforms\MySQLPlatform')) {
            eval('namespace Doctrine\DBAL\Platforms; class MySQLPlatform {}');
        }
        return new MySQLPlatform();
    }

    public function fetchOne(string $query, array $params = []): mixed
    {
        if (str_starts_with($query, 'SELECT GET_LOCK')) {
            $this->locks[] = 'lock';
            return 1;
        }
        if (str_starts_with($query, 'SELECT RELEASE_LOCK')) {
            $this->locks[] = 'release';
            return 1;
        }
        return $this->rows[$params[0]] ?? false;
    }

    public function executeStatement(string $query, array $params = []): int
    {
        $this->rows[$params[0]] = $params[1];
        return 1;
    }
}
