<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('app:check-photo-environment')]
#[Description('Verify that the server can process and store photos')]
class CheckPhotoEnvironment extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $gdInfo = function_exists('gd_info') ? gd_info() : [];

        $checks = [
            'GD extension' => extension_loaded('gd'),
            'WebP encoding' => ! empty($gdInfo['WebP Support']),
            'EXIF extension' => function_exists('exif_read_data'),
            'Fileinfo extension' => extension_loaded('fileinfo'),
            'Private storage writable' => is_writable(Storage::disk('local')->path('')),
            'Public storage writable' => is_writable(Storage::disk('public')->path('')),
        ];

        $failed = false;

        foreach ($checks as $label => $passed) {
            $this->line(($passed ? '<info>OK</info>   ' : '<error>FAIL</error> ').$label);
            $failed = $failed || ! $passed;
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
