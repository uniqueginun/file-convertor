<?php

namespace App\Jobs;

use App\Support\DmsAttachBlobFileWriter;
use App\Support\DmsAttachMigrationStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProcessDmsAttachBlobMigrationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $attachId,
    ) {
        $this->onQueue((string) config('filesystems.blob_migration_queue', 'blob-migration'));
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->attachId))->dontRelease(),
        ];
    }

    public function handle(DmsAttachBlobFileWriter $writer): void
    {
        $workerId = $this->workerId();
        $now = now();

        $claimed = DB::table('dms_attach')
            ->where('id', $this->attachId)
            ->where('is_uploaded', false)
            ->where('migration_status', DmsAttachMigrationStatus::QUEUED)
            ->update([
                'migration_status' => DmsAttachMigrationStatus::PROCESSING,
                'migration_locked_at' => $now,
                'migration_worker_id' => $workerId,
                'migration_attempts' => DB::raw('migration_attempts + 1'),
                'updated_at' => $now,
            ]);

        if ($claimed === 0) {
            return;
        }

        $row = DB::table('dms_attach')
            ->select(['id', 'uuid', 'ext', 'attach_file'])
            ->where('id', $this->attachId)
            ->first();

        if ($row === null) {
            return;
        }

        try {
            $result = $writer->write($row->uuid, $row->attach_file);

            if ($result->alreadyExists()) {
                $blobHash = hash('sha256', $row->attach_file);

                if (! hash_equals($blobHash, $result->sha256())) {
                    throw new RuntimeException("Existing file sha256 mismatch for uuid [{$row->uuid}].");
                }
            }

            DB::table('dms_attach')
                ->where('id', $this->attachId)
                ->update([
                    'is_uploaded' => true,
                    'file_path' => $result->path(),
                    'processed_at' => $now,
                    'migration_status' => DmsAttachMigrationStatus::DONE,
                    'migration_file_size' => $result->size(),
                    'migration_sha256' => $result->sha256(),
                    'migration_locked_at' => null,
                    'migration_worker_id' => null,
                    'migration_last_error' => null,
                    'updated_at' => $now,
                ]);
        } catch (Throwable $exception) {
            $this->markFailed($exception, $workerId);
        }
    }

    private function markFailed(Throwable $exception, string $workerId): void
    {
        $now = now();

        DB::table('dms_attach')
            ->where('id', $this->attachId)
            ->update([
                'migration_status' => DmsAttachMigrationStatus::FAILED,
                'migration_last_error' => mb_substr($exception->getMessage(), 0, 65535),
                'migration_locked_at' => null,
                'migration_worker_id' => null,
                'updated_at' => $now,
            ]);

        DB::table('dms_attach_migration_errors')->insert([
            'attach_id' => $this->attachId,
            'worker_id' => $workerId,
            'error_message' => mb_substr($exception->getMessage(), 0, 65535),
            'error_trace' => $exception->getTraceAsString(),
            'created_at' => $now,
        ]);
    }

    private function workerId(): string
    {
        return gethostname().':'.getmypid().':worker';
    }
}
