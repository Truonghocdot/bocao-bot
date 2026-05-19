<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScraperService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.scraper.url', 'http://127.0.0.1:3333/api/scrape');
    }

    /**
     * Call the Express Scraper API
     */
    public function runScrape(?string $fromDate = null, ?string $toDate = null, ?int $limit = null): array
    {
        Log::info("Sending request to scraper API: {$this->baseUrl}");

        $payload = [];  
        if ($fromDate) $payload['fromDate'] = $fromDate;
        if ($toDate) $payload['toDate'] = $toDate;
        if ($limit) $payload['limit'] = $limit;

        $response = Http::timeout(600)->post($this->baseUrl, $payload);

        if ($response->successful() && $response->json('success')) {
            return $response->json('data');
        }

        Log::error('Scraper API failed: ' . $response->body());
        throw new \Exception('Scraper API returned an error: ' . $response->json('message', 'Unknown error'));
    }
}
