<?php

namespace Database\Seeders;

use App\Support\RandomPdfGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DmsAttachSeeder extends Seeder
{
    /**
     * Seed the dms_attach table with PDF binary blobs.
     *
     * Usage:
     *   php artisan db:seed --class=DmsAttachSeeder
     *
     * Optional env overrides (useful for dry runs):
     *   DMS_ATTACH_SEED_COUNT=1000 DMS_ATTACH_SEED_BATCH=100 php artisan db:seed --class=DmsAttachSeeder
     */
    public function run(): void
    {
        $totalRecords = (int) env('DMS_ATTACH_SEED_COUNT', 1_000_000);
        $batchSize = (int) env('DMS_ATTACH_SEED_BATCH', 250);

        if ($totalRecords < 1) {
            $this->command?->error('DMS_ATTACH_SEED_COUNT must be at least 1.');

            return;
        }

        if ($batchSize < 1) {
            $this->command?->error('DMS_ATTACH_SEED_BATCH must be at least 1.');

            return;
        }

        $generator = new RandomPdfGenerator;
        $connection = DB::connection();
        $connection->disableQueryLog();

        $now = now()->toDateTimeString();
        $seeded = 0;

        $this->command?->info("Seeding {$totalRecords} dms_attach records in batches of {$batchSize}...");
        $this->command?->getOutput()?->progressStart($totalRecords);

        while ($seeded < $totalRecords) {
            $currentBatchSize = min($batchSize, $totalRecords - $seeded);
            $rows = [];

            for ($index = 0; $index < $currentBatchSize; $index++) {
                $rows[] = [
                    'attach_file' => $generator->generate(),
                    'uuid' => (string) Str::uuid(),
                    'ext' => 'pdf',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('dms_attach')->insert($rows);

            $seeded += $currentBatchSize;
            $this->command?->getOutput()?->progressAdvance($currentBatchSize);

            unset($rows);
        }

        $this->command?->getOutput()?->progressFinish();
        $this->command?->info("Seeded {$seeded} dms_attach records.");
    }
}
