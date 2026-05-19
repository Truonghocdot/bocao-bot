import { Page } from "@playwright/test";
import axios from "axios";
import pLimit from "p-limit";
import path from "path";
import fs from "fs";

const PDF_BTN = 'input[id*="LnkGetPDFActive"]';

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

export async function goToPage(page: Page, pageNumber: number) {
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

  const httpDownloadEnabled = process.env.HTTP_DOWNLOAD_ENABLED !== "0";
  const failedRows = httpDownloadEnabled
    ? await downloadPdfsViaHttp(rows, downloadDir)
    : rows;

  if (failedRows.length === 0) {
    return;
  }

  if (httpDownloadEnabled) {
    console.warn(`HTTP download failed for ${failedRows.length} file(s), falling back to Playwright click`);
  }

  // Xác định trang hiện tại đang mở để không bị lệch
  const activePageText = await page.locator('.Pager span').first().innerText().catch(() => "1");
  let currentPage = parseInt(activePageText.trim(), 10) || 1;

  for (const row of failedRows) {
    if (row.pageIndex !== currentPage) {
      await goToPage(page, row.pageIndex);
      currentPage = row.pageIndex;
    }

    const btn = page.locator(PDF_BTN).nth(row.rowIndex);
    const downloadPromise = page.waitForEvent("download", { timeout: 60000 });
    
    await btn.click();
    const download = await downloadPromise;
    await download.saveAs(path.join(downloadDir, row.filename));
    
    console.log(`⬇️ Downloaded: ${row.filename}`);
  }
}

async function downloadPdfsViaHttp(
  rows: RowDetail[],
  downloadDir: string
): Promise<RowDetail[]> {
  const concurrency = Math.max(1, Number(process.env.DOWNLOAD_CONCURRENCY || 4));
  const limit = pLimit(concurrency);
  const failedRows: RowDetail[] = [];

  await Promise.all(
    rows.map((row) =>
      limit(async () => {
        try {
          await downloadPdfViaHttp(row, downloadDir);
          console.log(`⬇️ Downloaded: ${row.filename}`);
        } catch (error: any) {
          failedRows.push(row);
          console.warn(`HTTP download failed: ${row.filename} — ${error.message}`);
        }
      })
    )
  );

  return failedRows.sort((a, b) => a.globalIndex - b.globalIndex);
}

async function downloadPdfViaHttp(row: RowDetail, downloadDir: string): Promise<void> {
  if (!row.pdfUrl || !row.postData || !row.cookieHeader || !row.userAgent) {
    throw new Error("Missing HTTP download metadata");
  }

  const body = new URLSearchParams();
  for (const [key, value] of Object.entries(row.postData)) {
    body.append(key, value);
  }

  const response = await axios.post(row.pdfUrl, body.toString(), {
    responseType: "arraybuffer",
    timeout: 60000,
    maxRedirects: 5,
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
      "Cookie": row.cookieHeader,
      "User-Agent": row.userAgent,
      "Referer": row.pdfUrl,
    },
    validateStatus: (status) => status >= 200 && status < 400,
  });

  const buffer = Buffer.from(response.data);
  const contentType = String(response.headers["content-type"] || "");

  if (!contentType.includes("pdf") && buffer.subarray(0, 4).toString() !== "%PDF") {
    throw new Error(`Unexpected content type: ${contentType || "unknown"}`);
  }

  fs.writeFileSync(path.join(downloadDir, row.filename), buffer);
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
