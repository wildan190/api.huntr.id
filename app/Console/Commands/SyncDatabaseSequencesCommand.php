<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncDatabaseSequencesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:sync-sequences';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize PostgreSQL sequence values with the current maximum IDs across all tables';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->info("Database driver is '{$driver}'. Sequence synchronization is only needed for PostgreSQL.");
            return self::SUCCESS;
        }

        $this->info("Scanning PostgreSQL tables and sequences...");

        $columns = DB::select("
            SELECT table_name, column_name, column_default
            FROM information_schema.columns
            WHERE table_schema = 'public' 
              AND column_default LIKE 'nextval(%'
            ORDER BY table_name
        ");

        if (empty($columns)) {
            $this->warn("No serial / sequence columns found in schema 'public'.");
            return self::SUCCESS;
        }

        $synced = 0;
        foreach ($columns as $col) {
            $tableName = $col->table_name;
            $columnName = $col->column_name;

            // Extract sequence name from nextval('sequence_name'::regclass)
            if (preg_match("/'([^']+)'/", $col->column_default, $matches)) {
                $sequenceName = $matches[1];
            } else {
                continue;
            }

            try {
                $maxId = (int) DB::table($tableName)->max($columnName);

                if ($maxId > 0) {
                    DB::statement("SELECT setval(?, ?, true)", [$sequenceName, $maxId]);
                    $this->line("✓ Table <comment>{$tableName}</comment> (col: {$columnName}) -> Sequence <info>{$sequenceName}</info> set to <info>{$maxId}</info>");
                } else {
                    DB::statement("SELECT setval(?, 1, false)", [$sequenceName]);
                    $this->line("- Table <comment>{$tableName}</comment> (col: {$columnName}) -> Sequence <info>{$sequenceName}</info> reset to 1 (empty table)");
                }
                $synced++;
            } catch (\Throwable $e) {
                $this->error("Failed to sync sequence '{$sequenceName}' for '{$tableName}.{$columnName}': " . $e->getMessage());
            }
        }

        $this->info("Successfully synchronized {$synced} sequence(s) in PostgreSQL without modifying existing data.");

        return self::SUCCESS;
    }
}
