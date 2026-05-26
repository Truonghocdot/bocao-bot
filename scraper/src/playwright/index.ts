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
import { isDebug } from "../utils/contants.js";
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

async function downloadResultsPageByPage(
  page: Awaited<ReturnType<typeof createPage>>,
  limit: number | undefined,
  downloadDir: string
): Promise<string[]> {
  const downloadedFiles: string[] = [];
  let totalPages = await getTotalPages(page);
  let processedRows = 0;

  if (limit && limit < totalPages) {
    totalPages = limit;
  }

  console.log(`📚 Will scrape pages: ${totalPages}`);

  for (let currentPage = 1; currentPage <= totalPages; currentPage++) {
    if (currentPage > 1) {
      await goToPage(page, currentPage);
    }

    const rows = await extractCurrentPageRows(
      page,
      currentPage,
      processedRows
    );

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

      await submitSearch(page, token, payload.fromDate, payload.toDate);

      // Kiểm tra bảng có trống không
      const empty = await isEmptyResultTable(page);

      if (empty) {
        console.warn(`⚠️ Kết quả trống sau lần ${attempt}. ${
          attempt < MAX_RETRIES ? "Thử lại (re-solve captcha)..." : "Hết lần thử, kết thúc."
        }`);

        if (attempt < MAX_RETRIES) {
          // Không cần load lại trang, chỉ cần re-solve captcha và submit lại
          // fillSearchForm không cần chạy lại vì form ASP.NET giữ nguyên state
          continue;
        }

        // Hết retry — trả về kết quả rỗng thay vì crash
        return { downloaded: 0, downloadDir: absoluteDownloadDir };
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
