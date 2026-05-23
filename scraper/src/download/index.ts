import axios from "axios";
import fs from "fs";
import https from "https";
import path from "path";
import pLimit from "p-limit";
import { RowDetail } from "../services/extract.service.js";

const DEFAULT_DOWNLOAD_CONCURRENCY = 3;
const DEFAULT_DOWNLOAD_TIMEOUT_MS = 60000;
const DEFAULT_DOWNLOAD_RETRIES = 3;
const DEFAULT_RETRY_BASE_DELAY_MS = 2000;
const DEFAULT_WORKER_DELAY_MS = 400;
const DEFAULT_TLS_REJECT_UNAUTHORIZED = false;

function getDownloadConcurrency(): number {
  return Math.max(1, Number(process.env.DOWNLOAD_CONCURRENCY || DEFAULT_DOWNLOAD_CONCURRENCY));
}

function getDownloadTimeoutMs(): number {
  return Math.max(10000, Number(process.env.DOWNLOAD_TIMEOUT_MS || DEFAULT_DOWNLOAD_TIMEOUT_MS));
}

function getDownloadRetries(): number {
  return Math.max(1, Number(process.env.DOWNLOAD_RETRIES || DEFAULT_DOWNLOAD_RETRIES));
}

function getWorkerDelayMs(): number {
  return Math.max(0, Number(process.env.DOWNLOAD_WORKER_DELAY_MS || DEFAULT_WORKER_DELAY_MS));
}

function getRetryBaseDelayMs(): number {
  return Math.max(250, Number(process.env.DOWNLOAD_RETRY_BASE_DELAY_MS || DEFAULT_RETRY_BASE_DELAY_MS));
}

function shouldRejectUnauthorized(): boolean {
  const defaultValue = DEFAULT_TLS_REJECT_UNAUTHORIZED ? "1" : "0";
  const raw = String(process.env.DOWNLOAD_TLS_REJECT_UNAUTHORIZED ?? defaultValue)
    .trim()
    .toLowerCase();

  return !["0", "false", "no", "off"].includes(raw);
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function jitter(ms: number): number {
  return ms + Math.floor(Math.random() * 300);
}

function isRetryableError(error: any): boolean {
  const status = error?.response?.status;
  const code = String(error?.code || "");
  const message = String(error?.message || "").toLowerCase();

  if ([408, 425, 429, 500, 502, 503, 504].includes(status)) {
    return true;
  }

  return (
    code === "ECONNRESET" ||
    code === "ETIMEDOUT" ||
    code === "ECONNABORTED" ||
    message.includes("timeout") ||
    message.includes("socket hang up")
  );
}

function ensureDownloadRequest(row: RowDetail): void {
  if (!row.pdfUrl || !row.postData || !row.cookieHeader || !row.userAgent) {
    throw new Error(`Thiếu dữ liệu tải PDF cho file ${row.filename}`);
  }
}

async function downloadSinglePdf(row: RowDetail, downloadDir: string): Promise<string> {
  ensureDownloadRequest(row);

  const outputPath = path.join(downloadDir, row.filename);
  const response = await axios.post(row.pdfUrl!, new URLSearchParams(row.postData!), {
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
      Cookie: row.cookieHeader!,
      "User-Agent": row.userAgent!,
      Referer: row.pdfUrl!,
      Origin: new URL(row.pdfUrl!).origin,
    },
    responseType: "stream",
    timeout: getDownloadTimeoutMs(),
    maxRedirects: 5,
    httpsAgent: new https.Agent({
      rejectUnauthorized: shouldRejectUnauthorized(),
    }),
    validateStatus: (status) => status >= 200 && status < 400,
  });

  const writer = fs.createWriteStream(outputPath);

  await new Promise<void>((resolve, reject) => {
    response.data.pipe(writer);
    response.data.on("error", reject);
    writer.on("error", reject);
    writer.on("finish", resolve);
  });

  const stats = fs.statSync(outputPath);
  if (stats.size === 0) {
    fs.unlinkSync(outputPath);
    throw new Error(`Downloaded empty file: ${row.filename}`);
  }

  return outputPath;
}

async function downloadWithRetry(row: RowDetail, downloadDir: string): Promise<string | null> {
  const maxAttempts = getDownloadRetries();

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      const outputPath = await downloadSinglePdf(row, downloadDir);
      console.log(`⬇️ Downloaded: ${row.filename}`);
      return outputPath;
    } catch (error: any) {
      const retryable = isRetryableError(error);
      const isLastAttempt = attempt === maxAttempts;

      if (!retryable || isLastAttempt) {
        console.warn(
          `⚠️ Bỏ qua file #${row.globalIndex + 1} (${row.filename}) sau ${attempt} lần thử: ${error.message}`
        );
        return null;
      }

      const delayMs = jitter(getRetryBaseDelayMs() * attempt);
      console.warn(
        `⚠️ Tải lỗi file #${row.globalIndex + 1} (${row.filename}), thử lại ${attempt}/${maxAttempts} sau ${delayMs}ms: ${error.message}`
      );
      await sleep(delayMs);
    }
  }

  return null;
}

export async function downloadAllPdfsParallel(
  rows: RowDetail[],
  downloadDir: string
): Promise<string[]> {
  const concurrency = getDownloadConcurrency();
  const workerDelayMs = getWorkerDelayMs();
  const limit = pLimit(concurrency);

  console.log(`🚀 Bắt đầu tải ${rows.length} PDF bằng ${concurrency} worker...`);

  const tasks = rows.map((row) =>
    limit(async () => {
      if (workerDelayMs > 0) {
        await sleep(jitter(workerDelayMs));
      }

      return downloadWithRetry(row, downloadDir);
    })
  );

  const results = await Promise.all(tasks);
  return results.filter((file): file is string => Boolean(file));
}
