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
     * Ước lượng timeout theo số trang scraper sẽ xử lý.
     *
     * Formula:
     *   timeout = (startup + pages * pageCost + estimatedFiles * fileCost) * buffer
     *
     * `limit` từ Telegram/API là số trang, không phải số bản ghi.
     * Với chế độ "tất cả", số trang thực tế chỉ biết sau khi scraper mở site,
     * nên Laravel dùng max_timeout để không cắt request giữa chừng.
     */
    protected function estimateTimeout(?int $pageLimit): int
    {
        return $this->buildTimeEstimate($pageLimit)['timeout_seconds'];
    }

    public function estimateRunTime(?int $pageLimit): array
    {
        return $this->buildTimeEstimate($pageLimit);
    }

    protected function buildTimeEstimate(?int $pageLimit): array
    {
        $startupSeconds = max(0, (int) config('services.scraper.startup_seconds', 90));
        $secondsPerPage = max(0, (float) config('services.scraper.seconds_per_page', 8));
        $secondsPerFile = max(0, (float) config('services.scraper.seconds_per_file', 2));
        $rowsPerPage = max(1, (int) config('services.scraper.rows_per_page', 20));
        $buffer = max(1, (float) config('services.scraper.timeout_buffer', 1.25));
        $minTimeout = max(1, (int) config('services.scraper.min_timeout', 180));
        $maxTimeout = max($minTimeout, (int) config('services.scraper.max_timeout', 4800));

        if ($pageLimit === null) {
            return [
                'mode' => 'all',
                'pages' => null,
                'estimated_files' => null,
                'base_seconds' => null,
                'buffer' => $buffer,
                'timeout_seconds' => $maxTimeout,
            ];
        }

        $pages = max(1, $pageLimit);
        $estimatedFiles = $pages * $rowsPerPage;
        $baseSeconds = $startupSeconds
            + ($pages * $secondsPerPage)
            + ($estimatedFiles * $secondsPerFile);
        $estimatedSeconds = (int) ceil($baseSeconds * $buffer);

        return [
            'mode' => 'limited',
            'pages' => $pages,
            'estimated_files' => $estimatedFiles,
            'base_seconds' => (int) ceil($baseSeconds),
            'buffer' => $buffer,
            'timeout_seconds' => min(max($estimatedSeconds, $minTimeout), $maxTimeout),
        ];
    }

    /**
     * Call the Express Scraper API
     */
    public function runScrape(?string $fromDate = null, ?string $toDate = null, ?int $limit = null, ?string $downloadKey = null): array
    {
        $timeEstimate = $this->buildTimeEstimate($limit);
        $timeout = $timeEstimate['timeout_seconds'];

        Log::info("Sending request to scraper API: {$this->baseUrl}", [
            'limit'   => $limit ?? 'all',
            'timeout' => $timeout,
            'time_estimate' => $timeEstimate,
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

        // 409 = scraper đang bận xử lý job khác
        if ($response->status() === 409) {
            throw new \Exception('SCRAPER_BUSY: ' . $response->json('message', 'Scraper is already running.'));
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
