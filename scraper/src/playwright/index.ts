import { createBrowser, createPage } from "../browser/index.js";
import { openSite, fillSearchForm, submitSearch } from "../services/form.service.js";
import { collectAllRows, getTotalPages, getTotalRecords, RowDetail, isEmptyResultTable } from "../services/extract.service.js";
import { solveCaptcha } from "../captcha/index.js";
import { downloadAllPdfsByClick } from "../download/index.js";
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

export async function scrapeDKKD(payload: ScrapePayload): Promise<ScrapeResult> {
  const downloadDir = generateDownloadDir(payload.downloadKey);
  const absoluteDownloadDir = path.resolve(downloadDir);
  const MAX_RETRIES = 3;

  // Chỉ tạo thư mục nếu không phải dryRun
  if (!payload.dryRun) {
    fs.mkdirSync(downloadDir, { recursive: true });
  }

  const browser = await createBrowser();
  const page = await createPage(browser);

  try {
    await openSite(page);
    await fillSearchForm(page, payload.fromDate, payload.toDate);

    let allItems: RowDetail[] = [];

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

      // Có kết quả — thu thập rows và thoát vòng lặp retry
      allItems = await collectAllRows(page, payload.limit);
      break;
    }

    console.log(`📥 Sẽ xử lý: ${allItems.length} bản`);

    /* ----------------------------------------------------------------
     | DRY RUN — Chỉ trả về preview, không tải / không nén ZIP
     * -------------------------------------------------------------- */
    if (payload.dryRun) {
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
    const downloadedPaths = await downloadAllPdfsByClick(page, allItems, downloadDir);

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

    const storageRoot = path.resolve(process.cwd(), "..", "storage");
    const errDir = path.join(storageRoot, "errors");
    fs.mkdirSync(errDir, { recursive: true });

    await page.screenshot({
      path: path.join(errDir, `error-${Date.now()}.png`),
      fullPage: true,
    });

    throw error;
  } finally {
    await browser.close();
  }
}
