import { createBrowser, createPage } from "../browser/index.js";
import { openSite, fillSearchForm, setFormErrorScreenshotDir, submitSearch } from "../services/form.service.js";
import {
  collectAllRows,
  extractCurrentPageRows,
  getTotalPages,
  getTotalRecords,
  goToPage,
  RowDetail,
  isEmptyResultTable,
} from "../services/extract.service.js";
import { solveCaptcha } from "../captcha/index.js";
import { downloadCurrentPagePdfsByClick } from "../download/index.js";
import { generateDownloadDir } from "../utils/date.js";
import {
  DKKD_EMPTY_RESULT_CODE,
  DKKD_EMPTY_RESULT_MESSAGE,
  isDebug,
} from "../utils/contants.js";
import fs from "fs";
import path from "path";

export interface ScrapePayload {
  fromDate?: string;   // dd/mm/yyyy — nếu không truyền thì lấy hôm qua
  toDate?: string;     // dd/mm/yyyy — nếu không truyền thì lấy hôm nay
  limit?: number;      // tối đa bao nhiêu TRANG — undefined = lấy tất cả
  dryRun?: boolean;    // true = chỉ xem danh sách, không tải PDF
  downloadKey?: string; // thư mục downloads/<downloadKey> do Laravel cấp
  estimateOnly?: boolean; // true = chỉ đọc tổng số trang / số bản ghi để estimate timeout
}

export interface ScrapeResult {
  downloaded: number;
  downloadDir?: string;
  files?: string[];
  dryRun?: boolean;
  preview?: RowDetail[];
  totalPages?: number;
  totalRecords?: number;
}

function isDkkdEmptyResultError(error: unknown): boolean {
  return error instanceof Error && error.message.includes(DKKD_EMPTY_RESULT_CODE);
}

function makeDkkdEmptyResultError(): Error {
  return new Error(`${DKKD_EMPTY_RESULT_CODE}: ${DKKD_EMPTY_RESULT_MESSAGE}`);
}

async function downloadResultsPageByPage(
  page: Awaited<ReturnType<typeof createPage>>,
  limit: number | undefined,
  downloadDir: string
): Promise<string[]> {
  const downloadedFiles: string[] = [];
  const totalRecords = await getTotalRecords(page);
  const siteTotalPages = Math.max(1, Math.ceil(totalRecords / 20));
  let totalPages = siteTotalPages;
  let processedRows = 0;

  if (limit && limit < totalPages) {
    totalPages = limit;
  }

  const expectedRowsForPage = (pageNumber: number): number => {
    if (pageNumber < totalPages || totalPages < siteTotalPages) {
      return 20;
    }

    const remainder = totalRecords % 20;
    return remainder === 0 ? 20 : remainder;
  };

  const extractRowsWithRetry = async (
    pageNumber: number,
    startIndex: number
  ): Promise<RowDetail[]> => {
    const expectedRows = expectedRowsForPage(pageNumber);
    const maxAttempts = 3;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
      const rows = await extractCurrentPageRows(page, pageNumber, startIndex);

      if (rows.length >= expectedRows) {
        return rows;
      }

      const message = `Page ${pageNumber} chỉ có ${rows.length}/${expectedRows} rows, retry ${attempt}/${maxAttempts}`;

      if (attempt === maxAttempts) {
        throw new Error(`INCOMPLETE_PAGE_ROWS: ${message}`);
      }

      console.warn(`⚠️ ${message}`);
      await page.waitForTimeout(3000);

      if (pageNumber > 1) {
        await goToPage(page, pageNumber - 1);
        await goToPage(page, pageNumber);
      }
    }

    throw new Error(`INCOMPLETE_PAGE_ROWS: Page ${pageNumber} không đủ rows.`);
  };

  console.log(`📚 Will scrape pages: ${totalPages}`);

  for (let currentPage = 1; currentPage <= totalPages; currentPage++) {
    if (currentPage > 1) {
      await goToPage(page, currentPage);
    }

    const rows = await extractRowsWithRetry(currentPage, processedRows);

    processedRows += rows.length;
    console.log(`📦 Accumulated rows: ${processedRows}`);

    const pageDownloadedFiles = await downloadCurrentPagePdfsByClick(
      page,
      rows,
      downloadDir
    );

    downloadedFiles.push(...pageDownloadedFiles);
    console.log(`📦 Accumulated downloaded files: ${downloadedFiles.length}`);
  }

  return downloadedFiles;
}

export async function scrapeDKKD(payload: ScrapePayload): Promise<ScrapeResult> {
  const downloadDir = generateDownloadDir(payload.downloadKey);
  const absoluteDownloadDir = path.resolve(downloadDir);
  const errorDir = path.join(downloadDir, "errors");
  const MAX_RETRIES = 3;

  // Chỉ tạo thư mục nếu không phải dryRun
  if (!payload.dryRun) {
    fs.mkdirSync(downloadDir, { recursive: true });
  }

  const browser = await createBrowser();
  const page = await createPage(browser);
  setFormErrorScreenshotDir(errorDir);

  try {
    await openSite(page);
    await fillSearchForm(page, payload.fromDate, payload.toDate);

    let allItems: RowDetail[] = [];
    let downloadedPaths: string[] = [];

    for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
      console.log(`🤖 Solving captcha... (attempt ${attempt}/${MAX_RETRIES})`);
      const token = await solveCaptcha(page.url());

      try {
        await submitSearch(page, token, payload.fromDate, payload.toDate);
      } catch (error: any) {
        if (!isDkkdEmptyResultError(error)) {
          throw error;
        }

        console.warn(`⚠️ Kết quả trống sau lần ${attempt}. ${
          attempt < MAX_RETRIES ? "Thử lại (re-solve captcha)..." : "Hết lần thử, báo dữ liệu trống."
        }`);

        if (attempt < MAX_RETRIES) {
          await openSite(page);
          await fillSearchForm(page, payload.fromDate, payload.toDate);
          continue;
        }

        throw makeDkkdEmptyResultError();
      }

      // Kiểm tra bảng có trống không
      const empty = await isEmptyResultTable(page);

      if (empty) {
        console.warn(`⚠️ Kết quả trống sau lần ${attempt}. ${
          attempt < MAX_RETRIES ? "Thử lại (re-solve captcha)..." : "Hết lần thử, kết thúc."
        }`);

        if (attempt < MAX_RETRIES) {
          await openSite(page);
          await fillSearchForm(page, payload.fromDate, payload.toDate);
          continue;
        }

        throw makeDkkdEmptyResultError();
      }

      if (payload.estimateOnly) {
        const totalRecords = await getTotalRecords(page);
        const totalPages = await getTotalPages(page);

        return {
          downloaded: 0,
          downloadDir: absoluteDownloadDir,
          totalPages,
          totalRecords,
        };
      }

      if (payload.dryRun) {
        // Có kết quả — dry-run vẫn thu thập toàn bộ rows để trả preview.
        allItems = await collectAllRows(page, payload.limit);
      } else {
        // Full-run tải từng page ngay sau khi extract để tránh stale ASP.NET state.
        downloadedPaths = await downloadResultsPageByPage(
          page,
          payload.limit,
          downloadDir
        );
      }

      break;
    }

    /* ----------------------------------------------------------------
     | DRY RUN — Chỉ trả về preview, không tải / không nén ZIP
     * -------------------------------------------------------------- */
    if (payload.dryRun) {
      console.log(`📥 Sẽ xử lý: ${allItems.length} bản`);
      console.log("🔍 DRY RUN mode — bỏ qua tải file.");

      allItems.forEach((row) => {
        console.log(`  [${row.globalIndex + 1}] (Page ${row.pageIndex}) ${row.companyName}`);
      });

      if (isDebug) {
        await page.pause();
      }

      return {
        downloaded: 0,
        downloadDir: absoluteDownloadDir,
        dryRun: true,
        preview: allItems,
      };
    }

    /* ----------------------------------------------------------------
     | FULL RUN — Tải PDF
     * -------------------------------------------------------------- */
    const downloadedFiles = downloadedPaths
      .map((file) => path.resolve(file))
      .sort();

    if (downloadedFiles.length === 0) {
      console.log("📭 Không có file PDF nào được tải.");
      return { downloaded: 0, downloadDir: absoluteDownloadDir };
    }

    console.log("🎉 DONE");
    console.log(`📄 Downloaded files: ${downloadedFiles.length}`);

    if (isDebug) {
      await page.pause();
    }

    return {
      downloaded: downloadedFiles.length,
      downloadDir: absoluteDownloadDir,
      files: downloadedFiles,
    };
  } catch (error: any) {
    console.error("❌ scrapeDKKD ERROR:", error.message);

    fs.mkdirSync(errorDir, { recursive: true });

    await page.screenshot({
      path: path.join(errorDir, `error-${Date.now()}.png`),
      fullPage: true,
    });

    throw error;
  } finally {
    await browser.close();
  }
}
