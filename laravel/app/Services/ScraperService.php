<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
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

    public function estimateRunTimeForDateRange(?string $fromDate = null, ?string $toDate = null, ?int $pageLimit = null): array
    {
        if ($pageLimit !== null) {
            return $this->buildTimeEstimate($pageLimit);
        }

        try {
            $pages = $this->estimateTotalPages($fromDate, $toDate);

            return $this->buildTimeEstimate($pages);
        } catch (\Throwable $e) {
            Log::warning('Scraper preflight estimate failed, fallback to max timeout.', [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'error' => $e->getMessage(),
            ]);

            return $this->buildTimeEstimate(null);
        }
    }

    protected function buildTimeEstimate(?int $pageLimit): array
    {
        $startupSeconds = max(0, (int) config('services.scraper.startup_seconds', 90));
        $secondsPerPage = max(0, (float) config('services.scraper.seconds_per_page', 8));
        $secondsPerFile = max(0, (float) config('services.scraper.seconds_per_file', 2));
        $rowsPerPage = max(1, (int) config('services.scraper.rows_per_page', 20));
        $buffer = max(1, (float) config('services.scraper.timeout_buffer', 1.3));
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

    protected function estimateTotalPages(?string $fromDate = null, ?string $toDate = null): int
    {
        return (int) $this->inspect($fromDate, $toDate)['totalPages'];
    }

    /**
     * @return array{totalRecords: int, totalPages: int}
     */
    public function inspect(?string $fromDate = null, ?string $toDate = null): array
    {
        $response = Http::timeout(max(
            (int) config('services.scraper.min_timeout', 180),
            (int) config('services.scraper.inspect_timeout', 360)
        ))
            ->post($this->endpoint('inspect'), array_filter([
                'fromDate' => $fromDate,
                'toDate' => $toDate,
            ], fn ($value) => $value !== null && $value !== ''));

        $data = $this->successfulDataOrFail($response, 'Scraper inspect');

        return [
            'totalRecords' => max(0, (int) ($data['totalRecords'] ?? 0)),
            'totalPages' => max(0, (int) ($data['totalPages'] ?? 0)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function download(
        ?string $fromDate,
        ?string $toDate,
        ?int $limit,
        string $downloadKey
    ): array {
        $timeEstimate = $this->buildTimeEstimate($limit);
        $timeout = $timeEstimate['timeout_seconds'];

        Log::info("Sending snapshot download request to scraper API: {$this->baseUrl}", [
            'limit' => $limit ?? 'all',
            'timeout' => $timeout,
            'download_key' => $downloadKey,
        ]);

        $response = Http::timeout($timeout)->post($this->endpoint('download'), array_filter([
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'limit' => $limit,
            'downloadKey' => $downloadKey,
        ], fn ($value) => $value !== null && $value !== ''));

        return $this->successfulDataOrFail($response, 'Scraper download');
    }

    /**
     * Call the Express Scraper API
     */
    public function runScrape(?string $fromDate = null, ?string $toDate = null, ?int $limit = null, ?string $downloadKey = null): array
    {
        return $this->download(
            $fromDate,
            $toDate,
            $limit,
            $downloadKey ?: now()->format('Ymd-His').'-legacy'
        );
    }

    protected function endpoint(string $operation): string
    {
        return rtrim($this->baseUrl, '/').'/'.$operation;
    }

    /**
     * @return array<string, mixed>
     */
    protected function successfulDataOrFail(Response $response, string $operation): array
    {
        if ($response->successful() && $response->json('success')) {
            return (array) $response->json('data', []);
        }

        if ($response->status() === 409) {
            throw new \RuntimeException('SCRAPER_BUSY: '.$response->json('message', 'Scraper is already running.'));
        }

        $errorMessage = (string) $response->json('message', 'Unknown error');
        $errorMessage = preg_replace('/\s+/', ' ', $errorMessage) ?? 'Unknown error';

        if (strlen($errorMessage) > self::MAX_ERROR_MESSAGE_LENGTH) {
            $errorMessage = substr($errorMessage, 0, self::MAX_ERROR_MESSAGE_LENGTH).'...';
        }

        Log::error("{$operation} failed", [
            'status' => $response->status(),
            'message' => $errorMessage,
        ]);

        throw new \RuntimeException("{$operation} returned an error: {$errorMessage}");
    }
}
