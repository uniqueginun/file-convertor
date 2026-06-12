<?php

namespace App\Console\Commands;

use App\Support\DmsAttachMigrationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverStaleDmsAttachMigrationsCommand extends Command
{
    protected $signature = 'dms:recover-stale-attach-migrations
                            {--stale-minutes=30 : Reset rows locked longer than this many minutes}
                            {--dry-run : Show how many rows would be reset without updating}';

    protected $description = 'Reset stale queued or processing dms_attach migration rows back to pending';

    public function handle(): int
    {
        $staleMinutes = max(1, (int) $this->option('stale-minutes'));
        $cutoff = now()->subMinutes($staleMinutes);

        $query = DB::table('dms_attach')
            ->whereIn('migration_status', DmsAttachMigrationStatus::stale())
            ->whereNotNull('migration_locked_at')
            ->where('migration_locked_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $count = (clone $query)->count();
            $this->info("Dry run: {$count} stale row(s) would be reset to pending (older than {$staleMinutes} minute(s)).");

            return self::SUCCESS;
        }

        $reset = $query->update([
            'migration_status' => DmsAttachMigrationStatus::PENDING,
            'migration_locked_at' => null,
            'migration_worker_id' => null,
            'updated_at' => now(),
        ]);

        $this->info("Reset {$reset} stale row(s) to pending.");

        return self::SUCCESS;
    }
}
