import { Page } from "@playwright/test";
import path from "path";
import fs from "fs";
import { assertNotDkkdErrorPage } from "./form.service.js";

const PDF_BTN = 'input[id*="LnkGetPDFActive"]';

function isTimeoutError(error: any): boolean {
  const message = String(error?.message ?? "");
  return message.includes("Timeout") || message.includes("timed out");
}

function safeScreenshotName(row: RowDetail): string {
  const baseName = row.filename.replace(/\.pdf$/i, "");
  return baseName
    .replace(/[^\p{L}\p{N}_-]/gu, "_")
    .slice(0, 120);
}

async function captureDownloadErrorScreenshot(
  page: Page,
  row: RowDetail,
  error: any
): Promise<void> {
  if (page.isClosed()) {
    return;
  }

  const storageRoot = path.resolve(process.cwd(), "..", "storage");
  const errDir = path.join(storageRoot, "errors");
  fs.mkdirSync(errDir, { recursive: true });

  const screenshotPath = path.join(
    errDir,
    `download-timeout-${String(row.globalIndex + 1).padStart(4, "0")}-${safeScreenshotName(row)}-${Date.now()}.png`
  );

  try {
    await page.screenshot({
      path: screenshotPath,
      fullPage: true,
    });

    console.warn(
      `📸 Đã lưu screenshot lỗi timeout file #${row.globalIndex + 1}: ${screenshotPath}`
    );
  } catch (screenshotError: any) {
    console.warn(
      `⚠️ Không thể chụp screenshot lỗi file #${row.globalIndex + 1}: ${screenshotError.message}`
    );
    console.warn(`⚠️ Lỗi gốc: ${error.message}`);
  }
}

export interface RowDetail {
  globalIndex: number;
  pageIndex: number;
  rowIndex: number;
  companyName: string;
  filename: string;
  pdfUrl?: string;
  postData?: Record<string, string>;
  cookieHeader?: string;
  userAgent?: string;
}

/**
 * Kiểm tra xem bảng kết quả có trống không.
 * Trả về true nếu thấy "Danh sách trống" hoặc tổng cộng = 0.
 */
export async function isEmptyResultTable(page: Page): Promise<boolean> {
  try {
    const tableHtml = await page
      .locator("#ctl00_C_CtlList")
      .innerHTML({ timeout: 10000 });

    if (tableHtml.includes("Danh sách trống")) {
      return true;
    }

    // Fallback: kiểm tra tổng cộng = 0
    const totalText = await page
      .locator("i")
      .filter({ hasText: "Tổng cộng" })
      .innerText({ timeout: 5000 })
      .catch(() => "");

    const match = totalText.match(/(\d+)/);
    if (match && parseInt(match[1], 10) === 0) {
      return true;
    }

    return false;
  } catch {
    // Nếu không tìm thấy bảng, coi như trống
    return true;
  }
}

export async function goToPage(page: Page, pageNumber: number) {
  assertNotDkkdErrorPage(page, `goToPage:${pageNumber}:start`);
  console.log(`➡️ Going to page ${pageNumber}`);

  const oldFirstRow = await page
    .locator(".enterprise_name")
    .first()
    .innerText();

  await page.evaluate((num) => {
    const eventTarget = document.querySelector('input[name="__EVENTTARGET"]');
    const eventArgument = document.querySelector('input[name="__EVENTARGUMENT"]');
    const form = document.querySelector("form");

    if (!eventTarget || !eventArgument || !form) {
      throw new Error("ASP.NET form fields not found");
    }

    // @ts-ignore
    eventTarget.value = "ctl00$C$CtlList";
    // @ts-ignore
    eventArgument.value = `Page$${num}`;
    // @ts-ignore
    form.submit();
  }, pageNumber);

  await page.waitForFunction(
    (prev) => {
      const el = document.querySelector(".enterprise_name");
      return el && el.textContent?.trim() !== prev;
    },
    oldFirstRow,
    { timeout: 30000 }
  );
  assertNotDkkdErrorPage(page, `goToPage:${pageNumber}:end`);
}

export async function extractCurrentPageRows(
  page: Page,
  currentPage: number,
  startIndex: number
): Promise<RowDetail[]> {
  const rowsLocator = page.locator('#ctl00_C_CtlList tr');
  const totalRows = await rowsLocator.count();
  const rows: RowDetail[] = [];

  // Thu thập state của form trên page hiện tại (dùng cho POST request qua axios)
  const formData = await page.evaluate(() => {
    const form = document.querySelector('form');
    if (!form) return {};
    const data = new FormData(form);
    const result: Record<string, string> = {};
    for (const [key, value] of data.entries()) {
      result[key] = value.toString();
    }
    return result;
  });

  const cookies = await page.context().cookies();
  const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');
  const userAgent = await page.evaluate(() => navigator.userAgent);
  const pdfUrl = page.url();

  for (let i = 1; i < totalRows - 1; i++) {
    const row = rowsLocator.nth(i);
    const btn = row.locator(PDF_BTN);

    if (!(await btn.count())) {
      continue;
    }

    const companyName = await row
      .locator(".enterprise_name")
      .innerText()
      .catch(() => `Unknown_${startIndex + i}`);

    const safeName = companyName
      .trim()
      .replace(/[^\p{L}\p{N}\s-]/gu, "")
      .replace(/\s+/g, "_");

    const btnName = await btn.getAttribute("name");
    
    // Copy form state cho request của row này
    const postData = { ...formData };
    if (btnName) {
      // Input type="image" gửi kèm x và y tọa độ click
      postData[`${btnName}.x`] = "1";
      postData[`${btnName}.y`] = "1";
    }

    rows.push({
      globalIndex: startIndex + rows.length,
      pageIndex: currentPage,
      rowIndex: rows.length,
      companyName: companyName.trim(),
      filename: `${String(startIndex + rows.length + 1).padStart(4, "0")}_${safeName}.pdf`,
      pdfUrl,
      postData,
      cookieHeader,
      userAgent
    });
  }

  console.log(`📄 Page ${currentPage}: ${rows.length} rows`);

  return rows;
}

export async function collectAllRows(
  page: Page,
  limit?: number
): Promise<RowDetail[]> {
  const allRows: RowDetail[] = [];
  let totalPages = await getTotalPages(page);

  // limit ở đây là số trang tối đa muốn lấy
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
      allRows.length
    );

    allRows.push(...rows);

    console.log(`📦 Accumulated rows: ${allRows.length}`);
  }

  return allRows;
}

export async function downloadAllPdfs(
  page: Page,
  rows: RowDetail[],
  downloadDir: string
) {
  console.log(`🚀 Bắt đầu tải ${rows.length} PDF...`);

  const activePageText = await page.locator('.Pager span').first().innerText().catch(() => "1");
  let currentPage = parseInt(activePageText.trim(), 10) || 1;

  for (const row of rows) {
    // Nếu browser/context đã bị đóng thì dừng hẳn — không thể tiếp tục
    if (page.isClosed()) {
      console.warn(`⚠️ Browser đã đóng tại file #${row.globalIndex + 1}, dừng tải.`);
      break;
    }

    if (row.pageIndex !== currentPage) {
      await goToPage(page, row.pageIndex);
      currentPage = row.pageIndex;
    }

    try {
      const btn = page.locator(PDF_BTN).nth(row.rowIndex);
      const [download] = await Promise.all([
        page.waitForEvent("download", { timeout: 60000 }),
        btn.click({ timeout: 10000, noWaitAfter: true }),
      ]);

      await download.saveAs(path.join(downloadDir, row.filename));

      console.log(`⬇️ Downloaded: ${row.filename}`);
    } catch (err: any) {
      // Browser/context bị đóng — không thể tiếp tục dù muốn
      if (
        err.message?.includes('Target page, context or browser has been closed') ||
        err.message?.includes('browser has been closed') ||
        page.isClosed()
      ) {
        console.warn(`⚠️ Browser đóng khi tải file #${row.globalIndex + 1}, dừng tải.`);
        break;
      }

      // Lỗi click timeout hoặc lỗi download đơn lẻ — bỏ qua, tiếp tục file tiếp theo
      if (isTimeoutError(err)) {
        await captureDownloadErrorScreenshot(page, row, err);
      }

      console.warn(`⚠️ Bỏ qua file #${row.globalIndex + 1} (${row.filename}): ${err.message}`);
    }
  }
}

const ROWS_PER_PAGE = 20;

export async function getTotalRecords(page: Page): Promise<number> {
  const text = await page
    .locator("i")
    .filter({ hasText: "Tổng cộng" })
    .innerText()
    .catch(() => "Tổng cộng: 0");

  const match = text.match(/(\d+)/);

  if (!match) {
    console.log("⚠️ Không đọc được tổng số bản ghi, mặc định 0");
    return 0;
  }

  return parseInt(match[1], 10);
}

export async function getTotalPages(page: Page): Promise<number> {
  const totalRecords = await getTotalRecords(page);

  if (totalRecords === 0) return 1;

  const totalPages = Math.ceil(totalRecords / ROWS_PER_PAGE);

  console.log(`📊 Total records: ${totalRecords}`);
  console.log(`📚 Total pages: ${totalPages}`); 

  return totalPages;
}
