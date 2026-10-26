<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\RoomDeleter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:prune-expired-events {--dry-run : Only list the events that would be deleted}')]
#[Description('Delete events whose expiry date plus the grace days has passed (all rooms and photos)')]
class PruneExpiredEvents extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RoomDeleter $deleter): int
    {
        $cutoff = now()->subDays((int) config('photos.events.grace_days'));
        $dryRun = (bool) $this->option('dry-run');

        $events = Event::active()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $cutoff)
            ->get();

        foreach ($events as $event) {
            $this->line("{$event->id}  {$event->name}  expired {$event->expires_at}");

            if (! $dryRun) {
                $deleter->deleteEvent($event);
            }
        }

        $this->info($dryRun
            ? "{$events->count()} event(s) would be deleted."
            : "{$events->count()} event(s) are being deleted.");

        return Command::SUCCESS;
    }
}
