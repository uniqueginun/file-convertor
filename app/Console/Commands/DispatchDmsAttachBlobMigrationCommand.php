<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDmsAttachBlobMigrationJob;
use App\Support\DmsAttachMigrationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchDmsAttachBlobMigrationCommand extends Command
{
    protected $signature = 'dms:dispatch-attach-blob-migration
                            {--batch-size=250 : Number of rows to claim per run}
                            {--dry-run : Show how many rows would be dispatched without updating or queuing}';

    protected $description = 'Claim pending dms_attach rows and dispatch blob migration jobs';

    public function handle(): int
    {
        $batchSize = max(1, (int) $this->option('batch-size'));

        if ($this->option('dry-run')) {
            $count = DB::table('dms_attach')
                ->where('is_uploaded', false)
                ->whereNotNull('attach_file')
                ->whereNotNull('uuid')
                ->whereIn('migration_status', DmsAttachMigrationStatus::dispatchable())
                ->count();

            $this->info("Dry run: {$count} row(s) eligible for dispatch (batch limit {$batchSize}).");

            return self::SUCCESS;
        }

        $workerId = $this->workerId();
        $now = now();

        $ids = DB::transaction(function () use ($batchSize, $workerId, $now): array {
            $rows = DB::table('dms_attach')
                ->select('id')
                ->where('is_uploaded', false)
                ->whereNotNull('attach_file')
                ->whereNotNull('uuid')
                ->whereIn('migration_status', DmsAttachMigrationStatus::dispatchable())
                ->orderBy('id')
                ->limit($batchSize)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            if ($rows->isEmpty()) {
                return [];
            }

            $ids = $rows->pluck('id')->all();

            DB::table('dms_attach')
                ->whereIn('id', $ids)
                ->update([
                    'migration_status' => DmsAttachMigrationStatus::QUEUED,
                    'migration_queued_at' => $now,
                    'migration_locked_at' => $now,
                    'migration_worker_id' => $workerId,
                    'updated_at' => $now,
                ]);

            return $ids;
        });

        if ($ids === []) {
            $this->info('No rows to dispatch.');

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            ProcessDmsAttachBlobMigrationJob::dispatch($id);
        }

        $queue = config('filesystems.blob_migration_queue', 'blob-migration');
        $this->info('Dispatched '.count($ids).' job(s) to the ['.$queue.'] queue.');

        return self::SUCCESS;
    }

    private function workerId(): string
    {
        return gethostname().':'.getmypid().':dispatch';
    }
}
