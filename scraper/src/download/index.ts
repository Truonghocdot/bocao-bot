import { Page } from "@playwright/test";
import fs from "fs";
import path from "path";
import { RowDetail, goToPage } from "../services/extract.service.js";

const PDF_BTN = 'input[id*="LnkGetPDFActive"]';
const DEFAULT_CLICK_DOWNLOAD_TIMEOUT_MS = 60000;
const DEFAULT_CLICK_DOWNLOAD_RETRIES = 2;
const DEFAULT_CLICK_DOWNLOAD_DELAY_MS = 1200;

function getDownloadTimeoutMs(): number {
  return Math.max(10000, Number(process.env.DOWNLOAD_TIMEOUT_MS || DEFAULT_CLICK_DOWNLOAD_TIMEOUT_MS));
}

function getDownloadRetries(): number {
  return Math.max(1, Number(process.env.DOWNLOAD_RETRIES || DEFAULT_CLICK_DOWNLOAD_RETRIES));
}

function getDownloadDelayMs(): number {
  return Math.max(0, Number(process.env.DOWNLOAD_WORKER_DELAY_MS || DEFAULT_CLICK_DOWNLOAD_DELAY_MS));
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function jitter(ms: number): number {
  return ms + Math.floor(Math.random() * 800);
}

async function captureDownloadErrorScreenshot(page: Page, row: RowDetail, step: string): Promise<void> {
  if (page.isClosed()) {
    return;
  }

  const storageRoot = path.resolve(process.cwd(), "..", "storage");
  const errDir = path.join(storageRoot, "errors");
  fs.mkdirSync(errDir, { recursive: true });

  const screenshotPath = path.join(
    errDir,
    `download-click-${step.replace(/[^a-zA-Z0-9_-]/g, "_")}-${String(row.globalIndex + 1).padStart(4, "0")}-${Date.now()}.png`
  );

  try {
    await page.screenshot({
      path: screenshotPath,
      fullPage: true,
    });
    console.warn(`📸 Đã lưu screenshot lỗi download [${step}] file #${row.globalIndex + 1}: ${screenshotPath}`);
  } catch (error: any) {
    console.warn(`⚠️ Không thể chụp screenshot lỗi download [${step}] file #${row.globalIndex + 1}: ${error.message}`);
  }
}

async function waitBetweenDownloads(): Promise<void> {
  const delayMs = getDownloadDelayMs();

  if (delayMs > 0) {
    await sleep(jitter(delayMs));
  }
}

async function downloadSinglePdfByClick(
  page: Page,
  row: RowDetail,
  downloadDir: string
): Promise<string> {
  const targetPath = path.join(downloadDir, row.filename);
  const timeoutMs = getDownloadTimeoutMs();
  const button = row.btnName
    ? page.locator(`input[name="${row.btnName}"]`)
    : page.locator(PDF_BTN).nth(row.rowIndex);

  const [download] = await Promise.all([
    page.waitForEvent("download", { timeout: timeoutMs }),
    button.click({
      timeout: 10000,
      noWaitAfter: true,
    }),
  ]);

  await download.saveAs(targetPath);

  const stats = fs.statSync(targetPath);
  if (stats.size === 0) {
    fs.unlinkSync(targetPath);
    throw new Error(`Downloaded empty file: ${row.filename}`);
  }

  return targetPath;
}

export async function downloadAllPdfsByClick(
  page: Page,
  rows: RowDetail[],
  downloadDir: string
): Promise<string[]> {
  console.log(`🚀 Bắt đầu tải ${rows.length} PDF bằng click browser...`);

  const downloadedFiles: string[] = [];
  const maxAttempts = getDownloadRetries();
  let currentPage = 1;

  for (const row of rows) {
    if (page.isClosed()) {
      console.warn(`⚠️ Browser đã đóng tại file #${row.globalIndex + 1}, dừng tải.`);
      break;
    }

    if (row.pageIndex !== currentPage) {
      await goToPage(page, row.pageIndex);
      currentPage = row.pageIndex;
    }

    await waitBetweenDownloads();

    let successPath: string | null = null;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
      try {
        successPath = await downloadSinglePdfByClick(page, row, downloadDir);
        console.log(`⬇️ Downloaded: ${row.filename}`);
        break;
      } catch (error: any) {
        await captureDownloadErrorScreenshot(page, row, `attempt_${attempt}`);

        const isLastAttempt = attempt === maxAttempts;
        if (isLastAttempt) {
          console.warn(
            `⚠️ Bỏ qua file #${row.globalIndex + 1} (${row.filename}) sau ${attempt} lần thử: ${error.message}`
          );
          break;
        }

        console.warn(
          `⚠️ Tải lỗi file #${row.globalIndex + 1} (${row.filename}), thử lại ${attempt}/${maxAttempts}: ${error.message}`
        );

        await waitBetweenDownloads();
      }
    }

    if (successPath) {
      downloadedFiles.push(successPath);
    }
  }

  return downloadedFiles;
}

export async function downloadCurrentPagePdfsByClick(
  page: Page,
  rows: RowDetail[],
  downloadDir: string
): Promise<string[]> {
  console.log(`🚀 Bắt đầu tải ${rows.length} PDF trên page ${rows[0]?.pageIndex ?? "hiện tại"}...`);

  const downloadedFiles: string[] = [];
  const maxAttempts = getDownloadRetries();

  for (const row of rows) {
    if (page.isClosed()) {
      console.warn(`⚠️ Browser đã đóng tại file #${row.globalIndex + 1}, dừng tải.`);
      break;
    }

    await waitBetweenDownloads();

    let successPath: string | null = null;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
      try {
        successPath = await downloadSinglePdfByClick(page, row, downloadDir);
        console.log(`⬇️ Downloaded: ${row.filename}`);
        break;
      } catch (error: any) {
        await captureDownloadErrorScreenshot(page, row, `attempt_${attempt}`);

        const isLastAttempt = attempt === maxAttempts;
        if (isLastAttempt) {
          console.warn(
            `⚠️ Bỏ qua file #${row.globalIndex + 1} (${row.filename}) sau ${attempt} lần thử: ${error.message}`
          );
          break;
        }

        console.warn(
          `⚠️ Tải lỗi file #${row.globalIndex + 1} (${row.filename}), thử lại ${attempt}/${maxAttempts}: ${error.message}`
        );

        await waitBetweenDownloads();
      }
    }

    if (successPath) {
      downloadedFiles.push(successPath);
    }
  }

  return downloadedFiles;
}
