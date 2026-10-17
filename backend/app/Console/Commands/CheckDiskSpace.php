<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

#[Signature('app:check-disk-space')]
#[Description('Warn when the photo storage disk is almost full')]
class CheckDiskSpace extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = Storage::disk('local')->path('');
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false || $total <= 0) {
            Log::warning('Could not read the disk usage of the photo storage', ['path' => $path]);
            $this->error("Could not read the disk usage of {$path}.");

            return Command::FAILURE;
        }

        $usedPercent = (int) round(($total - $free) / $total * 100);
        $threshold = (int) config('photos.disk_warning_percent');
        $freeGb = round($free / 1024 ** 3, 1);

        if ($usedPercent >= $threshold) {
            Log::warning('Photo storage disk is almost full', [
                'used_percent' => $usedPercent,
                'free_gb' => $freeGb,
                'threshold_percent' => $threshold,
            ]);
            $this->error("Disk is {$usedPercent}% full ({$freeGb} GB free), warning level is {$threshold}%.");

            return Command::FAILURE;
        }

        $this->info("Disk is {$usedPercent}% full ({$freeGb} GB free).");

        return Command::SUCCESS;
    }
}
