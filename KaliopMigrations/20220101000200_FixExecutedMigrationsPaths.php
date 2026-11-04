<?php

use Doctrine\DBAL\Connection;
use Kaliop\IbexaMigrationBundle\API\MigrationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Kaliop\IbexaMigrationBundle\API\Value\Migration;
use Kaliop\IbexaMigrationBundle\API\Value\MigrationDefinition;

/**
 * Make paths of all executed migrations relative, if possible
 */
class FixExecutedMigrationsPaths implements MigrationInterface
{
    private $container;
    /** @var Connection */
    private $connection;
    private $migrationsTableName;

    // The API says we have to have a static method, but we like better non-static... :-P
    public static function execute(ContainerInterface $container)
    {
        $migration = new self($container);
        $migration->goForIt();
    }

    private function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    private function goForIt()
    {
        $this->migrationsTableName = $this->container->getParameter('kaliop_migration_bundle.table_name');

        $this->connection = $this->container->get('ibexa.persistence.connection');

        /** @var \Kaliop\IbexaMigrationBundle\Core\Helper\ConsoleIO $io */
        $io = $this->container->get('kaliop_migration_bundle.helper.console_io');
        // NB: in theory this could be null!
        $output = $io->getOutput();

        if (!$this->tableExist($this->migrationsTableName))
        {
            $output->writeln("Nothing to update: database table '{$this->migrationsTableName}' not found");
            return;
        }

        $toMigrate = $this->loadAllMigrations();
        $output->writeln("<info>Found " . count($toMigrate) . ' migrations in the database table</info>');

        $rootDir = realpath($this->container->get('kernel')->getProjectDir()) . '/';

        foreach ($toMigrate as $legacyMigration) {
            $name = $legacyMigration['migration'];
            $path = $legacyMigration['path'];
            // note: we handle the case of 'current dir', but path is expected to include a filename...
            if (strpos($path, './') === 0 && $path !== './') {
                $this->updateMigrationPath($name, substr($path, 2));
                $output->writeln("Updated path info for migration: {$name} ({$path})");
            } elseif (strpos($path, $rootDir) === 0) {
                if ($path === $rootDir) {
                    $this->updateMigrationPath($name, './');
                } else {
                    $this->updateMigrationPath($name, substr($path, strlen($rootDir)));
                }
                $output->writeln("Updated path info for migration: {$name} ({$path})");
            }
        }

        $output->writeln("<info>All known migrations have been modified to use a relative path</info>");
    }

    private function tableExist($tableName)
    {
        // NB: do NOT use SchemaManager::listTables() here - Symfony's DoctrineBundle installs a
        //     schema assets filter that hides all tables not managed by Doctrine.
        $result = $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.tables WHERE table_catalog = ? AND table_name = ?',
            array($this->connection->getDatabase(), $tableName)
        )->fetchAssociative();

        return $result !== false;
    }

    private function loadAllMigrations()
    {
        $q = $this->connection->createQueryBuilder();
        $q->select('migration, path')
            ->from($this->migrationsTableName)
            ->orderBy('migration', 'ASC');
        $result = $q->executeQuery();

        return $result->fetchAllAssociative();
    }

    private function updateMigrationPath($migrationName, $path)
    {
        $this->connection->executeQuery(
            'UPDATE ' . $this->migrationsTableName . ' SET path = :newPath WHERE migration = :migrationName',
            ['newPath' => $path, 'migrationName' => $migrationName]
        );
    }
}
