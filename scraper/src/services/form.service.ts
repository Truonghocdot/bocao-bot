import { Page } from "@playwright/test";
import fs from "fs";
import path from "path";
import {
  DKKD_AUTH_REDIRECT_CODE,
  DKKD_AUTH_REDIRECT_MESSAGE,
  DKKD_EMPTY_RESULT_CODE,
  DKKD_EMPTY_RESULT_MESSAGE,
  DKKD_ERROR_CODE,
  DKKD_ERROR_MESSAGE,
  DKKD_ERROR_PATH,
  DKKD_LOGIN_PATH,
  SITE_URL,
} from "../utils/contants.js";
import { getEndDay, getStartDay } from "../utils/date.js";

const MAX_AUTH_REDIRECT_RETRIES = 8;
const MAX_LISTING_RECOVERY_RETRIES = 3;
const ANNOUNCEMENT_TYPE_SELECTOR = "#ctl00_C_ANNOUNCEMENT_TYPE_IDFilterFld";
const FROM_DATE_SELECTOR = "#ctl00_C_PUBLISH_DATEFilterFldFrom";
const TO_DATE_SELECTOR = "#ctl00_C_PUBLISH_DATEFilterFldTo";
let errorScreenshotDir: string | null = null;

export function setFormErrorScreenshotDir(dir: string): void {
  errorScreenshotDir = dir;
}

function getFormErrorScreenshotDir(): string {
  if (errorScreenshotDir) {
    return errorScreenshotDir;
  }

  return path.resolve(process.cwd(), "..", "storage", "errors");
}

function isDkkdErrorPageUrl(url: string): boolean {
  return url.includes(DKKD_ERROR_PATH);
}

function isDkkdAuthRedirectUrl(url: string): boolean {
  return url.includes(DKKD_LOGIN_PATH);
}

function isListingPageUrl(url: string): boolean {
  return url.startsWith(SITE_URL);
}

function makeDkkdSiteError(step: string): Error {
  return new Error(`${DKKD_ERROR_CODE}: ${DKKD_ERROR_MESSAGE} [step=${step}]`);
}

function makeDkkdEmptyResultError(step: string): Error {
  return new Error(`${DKKD_EMPTY_RESULT_CODE}: ${DKKD_EMPTY_RESULT_MESSAGE} [step=${step}]`);
}

type SubmitDiagnostic = {
  url: string;
  title: string;
  tableText: string;
  tableHtmlLength: number;
  captchaLength: number;
  validationText: string;
};

function isEmptyResultDiagnostic(diagnostic: unknown): diagnostic is SubmitDiagnostic {
  if (!diagnostic || typeof diagnostic !== "object") {
    return false;
  }

  const tableText = "tableText" in diagnostic
    ? String((diagnostic as { tableText?: unknown }).tableText ?? "")
    : "";

  if (tableText.includes("Danh sách trống")) {
    return true;
  }

  const totalMatch = tableText.match(/Tổng cộng\s*:?\s*(\d+)/i);
  return Boolean(totalMatch && parseInt(totalMatch[1], 10) === 0);
}

async function captureFormErrorScreenshot(page: Page, step: string): Promise<void> {
  if (page.isClosed()) {
    return;
  }

  const errDir = getFormErrorScreenshotDir();
  fs.mkdirSync(errDir, { recursive: true });

  const screenshotPath = path.join(
    errDir,
    `form-error-${step.replace(/[^a-zA-Z0-9_-]/g, "_")}-${Date.now()}.png`
  );

  try {
    await page.screenshot({
      path: screenshotPath,
      fullPage: true,
    });
    console.warn(`📸 Đã lưu screenshot lỗi form [${step}]: ${screenshotPath}`);
  } catch (error: any) {
    console.warn(`⚠️ Không thể chụp screenshot lỗi form [${step}]: ${error.message}`);
  }
}

export function assertNotDkkdErrorPage(page: Page, step: string): void {
  const currentUrl = page.url();

  if (isDkkdErrorPageUrl(currentUrl)) {
    throw makeDkkdSiteError(step);
  }
}

async function waitForSelectorOrDkkdError(
  page: Page,
  selector: string,
  step: string,
  timeout = 30000
): Promise<void> {
  try {
    const found = await Promise.race([
      page.waitForSelector(selector, { state: "visible", timeout }).then(() => "selector" as const),
      page.waitForURL(`**${DKKD_ERROR_PATH}`, { timeout }).then(() => "dkkd-error" as const),
    ]);

    if (found === "dkkd-error") {
      throw makeDkkdSiteError(step);
    }
  } catch (err: any) {
    await captureFormErrorScreenshot(page, step);

    // Nếu timeout xảy ra, kiểm tra URL hiện tại — trang có thể đã redirect
    // sang error page nhưng chưa kịp resolve Promise.race
    if (isDkkdErrorPageUrl(page.url())) {
      throw makeDkkdSiteError(step);
    }
    throw err;
  }
}

async function ensureOnListingPage(page: Page, step: string): Promise<void> {
  for (let attempt = 1; attempt <= MAX_LISTING_RECOVERY_RETRIES; attempt++) {
    const currentUrl = page.url();

    if (isDkkdErrorPageUrl(currentUrl)) {
      throw makeDkkdSiteError(`${step}:attempt${attempt}`);
    }

    if (isListingPageUrl(currentUrl)) {
      return;
    }

    if (isDkkdAuthRedirectUrl(currentUrl)) {
      console.warn(
        `⚠️ DKKD redirected to login before ${step}. Recovering to SITE_URL... attempt=${attempt}/${MAX_LISTING_RECOVERY_RETRIES}`
      );
    } else {
      console.warn(
        `⚠️ Unexpected page before ${step}: ${currentUrl}. Recovering to SITE_URL... attempt=${attempt}/${MAX_LISTING_RECOVERY_RETRIES}`
      );
    }

    await page.goto(SITE_URL, {
      waitUntil: "domcontentloaded",
      timeout: 60000,
    });
  }

  throw new Error(`${DKKD_AUTH_REDIRECT_CODE}: ${DKKD_AUTH_REDIRECT_MESSAGE} [step=${step}]`);
}

async function assertSearchFormValues(
  page: Page,
  fromDate?: string,
  toDate?: string
): Promise<void> {
  const expectedFromDate = fromDate || getStartDay();
  const expectedToDate = toDate || getEndDay();

  const state = await page.evaluate(
    ({ announcementSelector, fromDateSelector, toDateSelector }) => {
      const announcement = document.querySelector(announcementSelector) as HTMLSelectElement | null;
      const from = document.querySelector(fromDateSelector) as HTMLInputElement | null;
      const to = document.querySelector(toDateSelector) as HTMLInputElement | null;

      return {
        announcementType: announcement?.value?.trim() ?? "",
        fromDate: from?.value?.trim() ?? "",
        toDate: to?.value?.trim() ?? "",
      };
    },
    {
      announcementSelector: ANNOUNCEMENT_TYPE_SELECTOR,
      fromDateSelector: FROM_DATE_SELECTOR,
      toDateSelector: TO_DATE_SELECTOR,
    }
  );

  const issues: string[] = [];

  if (state.announcementType !== "NEW") {
    issues.push(`announcementType='${state.announcementType || "empty"}'`);
  }

  if (state.fromDate !== expectedFromDate) {
    issues.push(`fromDate='${state.fromDate || "empty"}' expected='${expectedFromDate}'`);
  }

  if (state.toDate !== expectedToDate) {
    issues.push(`toDate='${state.toDate || "empty"}' expected='${expectedToDate}'`);
  }

  if (issues.length > 0) {
    await captureFormErrorScreenshot(page, "submitSearch_form_values");
    throw new Error(`SEARCH_FORM_STATE_INVALID: ${issues.join(", ")}`);
  }
}

export async function openSite(page: Page) {
  for (let attempt = 1; attempt <= MAX_AUTH_REDIRECT_RETRIES; attempt++) {
    console.log(`🌐 Opening page... attempt ${attempt}/${MAX_AUTH_REDIRECT_RETRIES}`);

    await page.goto(SITE_URL, {
      waitUntil: "domcontentloaded",
      timeout: 60000,
    });

    const currentUrl = page.url();

    if (isDkkdErrorPageUrl(currentUrl)) {
      throw makeDkkdSiteError(`openSite:attempt${attempt}`);
    }

    if (isDkkdAuthRedirectUrl(currentUrl)) {
      console.warn(
        `⚠️ DKKD redirected to login page after opening SITE_URL. Retrying SITE_URL... attempt=${attempt}`
      );
      continue;
    }

    return;
  }

  throw new Error(`${DKKD_AUTH_REDIRECT_CODE}: ${DKKD_AUTH_REDIRECT_MESSAGE} [step=openSite]`);
}

export async function fillSearchForm(page: Page, fromDate?: string, toDate?: string) {
  await ensureOnListingPage(page, "fillSearchForm");
  assertNotDkkdErrorPage(page, "fillSearchForm:start");
  console.log("📌 Selecting announcement type...");

  await waitForSelectorOrDkkdError(
    page,
    ANNOUNCEMENT_TYPE_SELECTOR,
    "fillSearchForm:announcementType"
  );

  try {
    await page.selectOption(
      ANNOUNCEMENT_TYPE_SELECTOR,
      "NEW"
    );
  } catch (error: any) {
    // Nếu selectOption timeout, trang có thể đã redirect sang error page
    if (isDkkdErrorPageUrl(page.url())) {
      throw makeDkkdSiteError("fillSearchForm:selectOption");
    }
    throw error;
  }

  await waitForSelectorOrDkkdError(
    page,
    FROM_DATE_SELECTOR,
    "fillSearchForm:fromDate",
    10000
  );

  // remove readonly
  await page.evaluate(() => {
    document
      .querySelectorAll("input[readonly]")
      .forEach((el) => {
        el.removeAttribute("readonly");
      });
  });

  await page.fill(
    FROM_DATE_SELECTOR,
    fromDate || getStartDay()
  );

  await page.fill(
    TO_DATE_SELECTOR,
    toDate || getEndDay()
  );

  // remove enterprise code field
  await page.evaluate(() => {
    document
      .querySelector("#ctl00_C_ENT_GDT_CODEFld")
      ?.remove();
  });

  await waitForSelectorOrDkkdError(
    page,
    "#ctl00_C_BtnFilter",
    "fillSearchForm:filterButton",
    10000
  );
  assertNotDkkdErrorPage(page, "fillSearchForm:end");
}

export async function submitSearch(page: Page, token: string, fromDate?: string, toDate?: string) {
  await ensureOnListingPage(page, "submitSearch");
  assertNotDkkdErrorPage(page, "submitSearch:start");
  await assertSearchFormValues(page, fromDate, toDate);
  console.log("✅ Injecting captcha token...");

  await page.evaluate((captchaToken) => {
    const textarea = document.getElementById(
      "g-recaptcha-response"
    ) as HTMLTextAreaElement;

    if (!textarea) {
      throw new Error("g-recaptcha-response not found");
    }

    textarea.value = captchaToken;
    textarea.innerHTML = captchaToken;
    textarea.style.display = "block";
    textarea.style.position = "fixed";
    textarea.style.bottom = "0";
    textarea.style.left = "0";
    textarea.style.width = "1px";
    textarea.style.height = "1px";
    textarea.style.opacity = "0";
    textarea.style.pointerEvents = "none";
    textarea.style.zIndex = "-1";

    textarea.dispatchEvent(
      new Event("change", {
        bubbles: true,
      })
    );

    textarea.dispatchEvent(
      new Event("input", {
        bubbles: true,
      })
    );
  }, token);

  console.log("🔍 Submitting form...");

  // Bypass client-side validation của ASP.NET WebForms
  await page.evaluate(() => {
    (window as any).ValidateFilter = () => true;
  });

  // ASP.NET PostBack là full-page navigation —
  // phải dùng waitForNavigation() để bắt đúng navigation MỚI phát sinh sau click.
  // waitForLoadState() chỉ check trạng thái page hiện tại, không đợi navigation mới.
  console.log("🔍 Submit filter...");

  const oldHtml = await page
    .locator("#ctl00_C_CtlList")
    .innerHTML();

  try {
    await page.click("#ctl00_C_BtnFilter", {
      timeout: 10000,
      noWaitAfter: true,
    });
  } catch (error: any) {
    await captureFormErrorScreenshot(page, "submitSearch_click_filter");

    if (isDkkdErrorPageUrl(page.url())) {
      throw makeDkkdSiteError("submitSearch:clickFilter");
    }

    throw error;
  }

  try {
    await page.waitForFunction(
      (previous) => {
        const current = document.querySelector(
          "#ctl00_C_CtlList"
        )?.innerHTML;

        return current && current !== previous;
      },
      oldHtml,
      {
        timeout: 30000,
      }
    );
  } catch (error: any) {
    await captureFormErrorScreenshot(page, "submitSearch_wait_results");

    const diagnostic = await page.evaluate(() => {
      const table = document.querySelector("#ctl00_C_CtlList");
      const captcha = document.querySelector("#g-recaptcha-response") as HTMLTextAreaElement | null;
      const validationSummary = document.querySelector(".validation-summary-errors, #ctl00_C_ValidationSummary1");

      return {
        url: window.location.href,
        title: document.title,
        tableText: table?.textContent?.replace(/\s+/g, " ").trim().slice(0, 300) ?? "",
        tableHtmlLength: table?.innerHTML?.length ?? 0,
        captchaLength: captcha?.value?.length ?? 0,
        validationText: validationSummary?.textContent?.replace(/\s+/g, " ").trim().slice(0, 300) ?? "",
      };
    }).catch((diagnosticError: any) => ({
      diagnosticError: diagnosticError.message,
    }));

    console.warn("⚠️ Submit did not update result table", diagnostic);

    if (isDkkdErrorPageUrl(page.url())) {
      throw makeDkkdSiteError("submitSearch:waitResults");
    }

    if (isEmptyResultDiagnostic(diagnostic)) {
      throw makeDkkdEmptyResultError("submitSearch:waitResults");
    }

    throw error;
  }

  console.log("✅ Result table updated");
  assertNotDkkdErrorPage(page, "submitSearch:end");

  const tableText = await page
    .locator("#ctl00_C_CtlList")
    .innerText({ timeout: 10000 })
    .catch(() => "");

  if (tableText.includes("Danh sách trống")) {
    throw makeDkkdEmptyResultError("submitSearch:emptyResults");
  }
}
