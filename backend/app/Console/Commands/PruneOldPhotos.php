<?php

namespace App\Console\Commands;

use App\Models\Photo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:prune-old-photos {--dry-run : Only list the photos that would be deleted}')]
#[Description('Delete photos older than the retention period (database row and files)')]
class PruneOldPhotos extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! config('photos.retention.enabled')) {
            $this->info('Retention is turned off (PHOTOS_RETENTION_ENABLED=false). Nothing deleted.');

            return Command::SUCCESS;
        }

        $days = (int) config('photos.retention.days');

        if ($days < 1) {
            $this->error('PHOTOS_RETENTION_DAYS must be at least 1. Nothing deleted.');

            return Command::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;
        $failed = 0;

        Photo::query()
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($photos) use ($dryRun, &$deleted, &$failed): void {
                foreach ($photos as $photo) {
                    if ($dryRun) {
                        $this->line("{$photo->uuid}  {$photo->status}  {$photo->created_at}");
                        $deleted++;

                        continue;
                    }

                    $photo->deleteWithFiles() ? $deleted++ : $failed++;
                }
            });

        if ($dryRun) {
            $this->info("{$deleted} photo(s) older than {$days} days would be deleted.");

            return Command::SUCCESS;
        }

        $this->info("Deleted {$deleted} photo(s) older than {$days} days.");

        if ($failed > 0) {
            $this->error("{$failed} photo(s) could not be deleted. They are kept and retried on the next run.");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
