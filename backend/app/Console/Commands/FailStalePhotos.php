<?php

namespace App\Console\Commands;

use App\Models\Photo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:fail-stale-photos')]
#[Description('Mark photos stuck in pending for too long as failed')]
class FailStalePhotos extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) config('photos.stale_pending_minutes'));

        $count = Photo::query()
            ->where('status', Photo::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->update(['status' => Photo::STATUS_FAILED]);

        $this->info("Marked {$count} stale photo(s) as failed.");

        return Command::SUCCESS;
    }
}
