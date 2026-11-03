<?php

namespace App\Console\Commands;

use App\Services\InventoryService;
use Illuminate\Console\Command;

class ReleaseExpiredReservations extends Command
{
    protected $signature = 'inventory:release-expired';

    protected $description = 'Release stock held by unpaid reservations that have expired';

    /**
     * Execute the console command.
     */
    public function handle(InventoryService $inventory): int
    {
        $count = $inventory->releaseExpired();

        $this->info("Released {$count} expired reservation(s).");

        return self::SUCCESS;
    }
}
