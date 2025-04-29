<?php

namespace nexxai\EngineSwap\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

class EngineSwapCommand extends Command implements PromptsForMissingInput
{
    protected $signature = 'migrate:dbengine { source : The name of the database in config/database.php to copy from }
                                             { target : The name of the database in config/database.php to replicate data to }';

    protected $description = 'Migrate existing data from one DB engine to another';

    public function handle(): int
    {
        $source = $this->argument('source');
        $target = $this->argument('target');

        if (! $target_db = config('database.connections.'.$target)) {
            $this->error('Target database connection not found in config/database.php');

            return 1;
        }

        // Check if the source and target engines are different
        if ($source === $target) {
            $this->error('Source and target engines must be different.');

            return 1;
        }

        $this->error('WARNING: This command is destructive and will **DELETE ANY EXISTING DATA** in the target database.');
        $this->newLine();
        $this->info('Target database connection: '.$target);
        $this->line('Host: '.$target_db['host']);
        $this->line('Port: '.$target_db['port']);
        $this->line('Database: '.$target_db['database']);
        $this->line('Driver: '.$target_db['driver']);

        if (! $this->confirm('Are you absolutely sure you want to continue?', false)) {
            $this->error('Migration cancelled.');

            return 1;
        }

        $this->info("Emptying database {$target} and running migrations...");
        $this->call('migrate:fresh', [
            '--database' => $target,
            '--force' => true,
        ]);

        $this->info("Migrating data from {$source} to {$target}...");

        // Get the list of tables being created in the migrations
        $paths = base_path('database/migrations');

        // Find the migration files
        $migration_files = $this->getMigrationFiles($paths);

        $tables_to_migrate = [];

        // Use nikic AST parser to parse the migration files and find the Schema::create calls
        foreach ($migration_files as $file) {
            $tables = $this->getTables($file);
            if ($tables) {
                $tables_to_migrate[] = $tables;
            }
        }

        $failures = [];

        foreach ($tables_to_migrate as $table_data) {
            $table = $table_data['table'];

            $this->migrateTable($source, $target, $table, $failures);
        }

        $this->info('Migration complete.');
        $this->newLine();

        if (! empty($failures)) {
            if (! empty($failures['unique_constraint'])) {
                $this->error('Unique constraint failures encountered.  The following rows were not migrated:');
                foreach ($failures['unique_constraint'] as $table => $rows) {
                    $this->line("Table: {$table}");
                    foreach ($rows as $row) {
                        $this->info(json_encode($row));
                    }
                    $this->newLine();
                }
            }
        }

        return 0;
    }

    /**
     * Get the tables from the migration file.
     *
     * @param  string  $file  The name of the migration file
     * @return ?array The list of tables to migrate
     */
    public function getTables(string $file): ?array
    {
        $code = file_get_contents($file);

        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $ast = $parser->parse($code);

        $nodeFinder = new NodeFinder;

        $static_calls = $nodeFinder->findInstanceOf($ast, StaticCall::class);

        foreach ($static_calls as $call) {
            if ($call->class->toString() === 'Schema' && $call->name->toString() === 'create') {
                if (isset($call->args[0]->value->value)) {
                    // I don't know why the LSP says that the `->value->value` property does not exist because it definitely does
                    // @phpstan-ignore-next-line
                    /** @disregard **/
                    return ['table' => $call->args[0]->value->value, 'complete' => false];
                }
            }
        }

        return null;
    }

    /**
     * Get the migration files from the database/migrations directory.
     *
     * Pulled from Illuminate\Database\Migrations\Migrator and slightly modified
     *
     * @param  array  $paths  The paths to search for migration files
     * @return array The list of migration files
     */
    public function getMigrationFiles($paths): array
    {
        return (new Collection($paths))
            ->flatMap(fn ($path) => str_ends_with($path, '.php') ? [$path] : glob($path.'/*_*.php'))
            ->filter()
            ->values()
            ->keyBy(fn ($file) => $this->getMigrationName($file))
            ->sortBy(fn ($file, $key) => $key)
            ->all();
    }

    /**
     * Migrate the data from the source table to the target table.
     *
     * @param  string  $source  The name of the source database connection
     * @param  string  $target  The name of the target database connection
     * @param  string  $table  The name of the table to migrate
     * @param  array  &$failures  An array to store any failures encountered during the migration
     */
    public function migrateTable(string $source, string $target, string $table, array &$failures): void
    {
        // Check if the table has already been migrated
        if (DB::connection($target)->table($table)->get()->count() === DB::connection($source)->table($table)->get()->count()) {
            return;
        }

        $this->info("Migrating table: {$table}");

        // TODO: This will likely fail for large tables.  We should probably chunk the data somehow.
        //
        // Get the data from the source engine
        $source_data = DB::connection($source)->table($table)->get();

        foreach ($source_data as $row) {
            $this->populateTableByRow($source, $target, $table, $row, $failures);
        }
    }

    /**
     * Populate the target table with the data from the source table.
     *
     * @param  string  $source  The name of the source database connection
     * @param  string  $target  The name of the target database connection
     * @param  string  $table  The name of the table to populate
     * @param  \stdClass  $row  The row of data to insert
     * @param  array  $failures  An array to store any failures encountered during the migration
     */
    public function populateTableByRow(string $source, string $target, string $table, \stdClass $row, array &$failures): void
    {
        try {
            DB::connection($target)->table($table)->insert(get_object_vars($row));
        } catch (UniqueConstraintViolationException $e) {
            // Make a note of any unique constraint violations so we can display them at the end of the process
            $failures['unique_constraint'][$table][] = $row;
        } catch (QueryException $e) {
            // Check if the error is a foreign key constraint violation
            if ($e->getCode() === '23000') {
                $foreign_table = $this->parseMissingForeignTable($e->getMessage());

                // Migrate the foreign table first
                $this->migrateTable($source, $target, $foreign_table, $failures);

                // Retry the current insert after migrating the missing foreign table
                $this->populateTableByRow($source, $target, $table, $row, $failures);
            } else {
                // If it's not a foreign key constraint violation, rethrow the exception since this is a "real" error
                throw $e;
            }
        }
    }

    /**
     * Parse the error message to get the name of the missing foreign table.
     */
    public function parseMissingForeignTable(string $message): string
    {
        preg_match('/(?i)references\s*`(.*?)`/i', $message, $matches);

        return $matches[1] ?? '';
    }

    /**
     * Get the migration name from the file name.
     *
     * @param  string  $file  The name of the migration file
     * @return string The migration name
     */
    public function getMigrationName(string $file): string
    {
        return preg_replace('/^.*?_(\d{4}_\d{2}_\d{2}_\d{6})_(.*)\.php$/', '$1', $file);
    }
}
