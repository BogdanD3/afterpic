<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Crypt;

abstract class TestCase extends BaseTestCase
{
    /**
     * Polling cursor as the server hands it out (see PhotoController::updates).
     */
    protected function updatesCursor(int $afterId, int $since = 0): string
    {
        return Crypt::encryptString(json_encode(['after_id' => $afterId, 'since' => $since]));
    }

    protected function updatesUrl(int $afterId, int $since = 0): string
    {
        return '/api/photos/updates?cursor='.urlencode($this->updatesCursor($afterId, $since));
    }
}
