import { createBrowser, createPage } from "../browser/index.js";
import { openSite, fillSearchForm, submitSearch } from "../services/form.service.js";
import { collectAllRows, downloadAllPdfs, RowDetail } from "../services/extract.service.js";
import { solveCaptcha } from "../captcha/index.js";
import { generateDownloadDir } from "../utils/date.js";
import { isDebug } from "../utils/contants.js";
import { createZip } from "../services/zip.service.js";
import fs from "fs";
import path from "path";

export interface ScrapePayload {
  fromDate?: string;   // dd/mm/yyyy — nếu không truyền thì lấy hôm qua
  toDate?: string;     // dd/mm/yyyy — nếu không truyền thì lấy hôm nay
  limit?: number;      // tối đa bao nhiêu TRANG — undefined = lấy tất cả
  dryRun?: boolean;    // true = chỉ xem danh sách, không tải PDF
}

export interface ScrapeResult {
  downloaded: number;
  zip?: string;
  dryRun?: boolean;
  preview?: RowDetail[];
}

export async function scrapeDKKD(payload: ScrapePayload): Promise<ScrapeResult> {
  const downloadDir = generateDownloadDir();

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
        dryRun: true,
        preview: allItems,
      };
    }

    /* ----------------------------------------------------------------
     | FULL RUN — Tải PDF + nén ZIP
     * -------------------------------------------------------------- */
    await downloadAllPdfs(page, allItems, downloadDir); 

    // Không tạo ZIP nếu không tải được file nào
    const downloadedFiles = fs.existsSync(downloadDir)
      ? fs.readdirSync(downloadDir).filter((f) => f.endsWith(".pdf"))
      : [];

    if (downloadedFiles.length === 0) {
      console.log("📭 Không có file PDF nào để nén.");
      return { downloaded: 0 };
    }

    const zipFilename = `dkkd_new_${Date.now()}.zip`;
    const storageRoot = path.resolve(process.cwd(), "..", "storage");
    const zipPath = path.join(storageRoot, "zips", zipFilename);

    await createZip(downloadDir, zipPath);

    console.log("🎉 DONE");
    console.log(`📦 ZIP: ${zipPath}`);

    if (isDebug) {
      await page.pause();
    }

    return {
      downloaded: allItems.length,
      zip: zipPath,
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
