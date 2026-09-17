<?php

namespace App\Jobs;

use App\Services\ScrapeSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class WarmTodaySnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 14400;

    public int $tries = 1;

    public int $uniqueFor = 14400;

    public function __construct()
    {
        $this->onQueue('scrape-maintenance');
    }

    public function uniqueId(): string
    {
        return 'today';
    }

    public function handle(ScrapeSnapshotService $snapshotService): void
    {
        $today = now()->format('d/m/Y');
        $resolved = $snapshotService->resolve($today, $today, null);

        Log::info('WarmTodaySnapshotJob completed.', [
            'snapshot_id' => $resolved['snapshot']->id,
            'cache_hit' => $resolved['cache_hit'],
            'total_pages' => $resolved['snapshot']->source_total_pages,
        ]);
    }
}
