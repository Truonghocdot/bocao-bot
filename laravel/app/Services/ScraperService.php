<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScraperService
{
    protected string $baseUrl;
    protected const MAX_ERROR_MESSAGE_LENGTH = 500;

    public function __construct()
    {
        $this->baseUrl = config('services.scraper.url', 'http://127.0.0.1:3333/api/scrape');
    }

    /**
     * Ước lượng timeout dựa trên số lượng bản ghi cần scrape.
     *
     * Từ log thực tế:
     *   - Phase 1 (crawl pages): ~7.2 giây/trang, mỗi trang 20 rows
     *   - Phase 2 (download PDF): ~1.35 giây/file
     *   - Buffer: +20%
     *
     * Worst case "tất cả" (~1730 bản): ~50 phút → giới hạn 3600 giây (1 tiếng).
     */
    protected function estimateTimeout(?int $limit): int
    {
        $secondsPerPage     = 7.2;
        $secondsPerDownload = 1.35;
        $rowsPerPage        = 20;
        $bufferMultiplier   = 1.2;
        $maxTimeout         = 3600; // 1 tiếng — đủ cho worst case ~1730 bản

        if ($limit === null) {
            return $maxTimeout;
        }

        $pages    = (int) ceil($limit / $rowsPerPage);
        $crawl    = $pages * $secondsPerPage;
        $download = $limit * $secondsPerDownload;

        return (int) min(ceil(($crawl + $download) * $bufferMultiplier), $maxTimeout);
    }

    /**
     * Call the Express Scraper API
     */
    public function runScrape(?string $fromDate = null, ?string $toDate = null, ?int $limit = null, ?string $downloadKey = null): array
    {
        $timeout = $this->estimateTimeout($limit);

        Log::info("Sending request to scraper API: {$this->baseUrl}", [
            'limit'   => $limit ?? 'all',
            'timeout' => $timeout,
        ]);

        $payload = [];
        if ($fromDate)    $payload['fromDate']    = $fromDate;
        if ($toDate)      $payload['toDate']      = $toDate;
        if ($limit)       $payload['limit']       = $limit;
        if ($downloadKey) $payload['downloadKey'] = $downloadKey;

        $response = Http::timeout($timeout)
            ->connectTimeout(10)
            ->post($this->baseUrl, $payload);

        if ($response->successful() && $response->json('success')) {
            return $response->json('data');
        }

        Log::error('Scraper API failed: ' . $response->body());
        $errorMessage = (string) $response->json('message', 'Unknown error');
        $errorMessage = preg_replace('/\s+/', ' ', $errorMessage) ?? 'Unknown error';

        if (strlen($errorMessage) > self::MAX_ERROR_MESSAGE_LENGTH) {
            $errorMessage = substr($errorMessage, 0, self::MAX_ERROR_MESSAGE_LENGTH) . '...';
        }

        throw new \Exception('Scraper API returned an error: ' . $errorMessage);
    }
}
