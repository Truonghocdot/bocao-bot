import { createBrowser, createPage } from "../browser/index.js";
import { openSite, fillSearchForm, submitSearch } from "../services/form.service.js";
import { collectAllRows, downloadAllPdfs, RowDetail } from "../services/extract.service.js";
import { solveCaptcha } from "../captcha/index.js";
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
}

export interface ScrapeResult {
  downloaded: number;
  downloadDir?: string;
  files?: string[];
  dryRun?: boolean;
  preview?: RowDetail[];
}

export async function scrapeDKKD(payload: ScrapePayload): Promise<ScrapeResult> {
  const downloadDir = generateDownloadDir(payload.downloadKey);
  const absoluteDownloadDir = path.resolve(downloadDir);

  // Chỉ tạo thư mục nếu không phải dryRun
  if (!payload.dryRun) {
    fs.mkdirSync(downloadDir, { recursive: true });
  }

  const browser = await createBrowser();
  const page = await createPage(browser);

  try {
    await openSite(page);

    await fillSearchForm(page, payload.fromDate, payload.toDate);

    console.log("🤖 Solving captcha...");
    const token = await solveCaptcha(page.url());

    await submitSearch(page, token);

    // Thu thập danh sách rows trên tất cả các trang
    const allItems = await collectAllRows(page, payload.limit);

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
    await downloadAllPdfs(page, allItems, downloadDir); 

    const downloadedFiles = fs.existsSync(downloadDir)
      ? fs.readdirSync(downloadDir)
          .filter((f) => f.endsWith(".pdf"))
          .sort()
          .map((file) => path.join(absoluteDownloadDir, file))
      : [];

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
