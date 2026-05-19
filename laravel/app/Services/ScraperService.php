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
     * Ước lượng timeout dựa trên số lượng trang cần scrape.
     * Playwright mất trung bình ~8 giây/trang (bao gồm load, click, download).
     * Thêm 300 giây buffer cho khởi động browser và overhead mạng.
     *
     * Giới hạn tối đa 18000 giây (5 tiếng) cho trường hợp "tất cả" (~1800 trang).
     */
    protected function estimateTimeout(?int $limit): int
    {
        $secondsPerPage = 8;
        $buffer         = 300;
        $maxTimeout     = 18000; // 5 tiếng

        if ($limit === null) {
            // "Tất cả" — ước lượng theo worst case 1800 trang
            return $maxTimeout;
        }

        $estimated = ($limit * $secondsPerPage) + $buffer;

        return min($estimated, $maxTimeout);
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

        $response = Http::timeout($timeout)->post($this->baseUrl, $payload);

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
