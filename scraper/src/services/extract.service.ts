import { Page } from "@playwright/test";
import { assertNotDkkdErrorPage } from "./form.service.js";

const PDF_BTN = 'input[id*="LnkGetPDFActive"]';

export interface RowDetail {
  globalIndex: number;
  pageIndex: number;
  rowIndex: number;
  btnName?: string;
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
  const totalPdfButtons = await page.locator(PDF_BTN).count();
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
      btnName: btnName || undefined,
      companyName: companyName.trim(),
      filename: `${String(startIndex + rows.length + 1).padStart(4, "0")}_${safeName}.pdf`,
      pdfUrl,
      postData,
      cookieHeader,
      userAgent
    });
  }

  console.log(`📄 Page ${currentPage}: ${rows.length} rows (table tr: ${totalRows}, pdf buttons: ${totalPdfButtons})`);

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
