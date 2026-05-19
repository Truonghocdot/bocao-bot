import { Page } from "@playwright/test";
import {
  DKKD_ERROR_CODE,
  DKKD_ERROR_MESSAGE,
  DKKD_ERROR_PATH,
  SITE_URL,
} from "../utils/contants.js";
import { getEndDay, getStartDay } from "../utils/date.js";

export function assertNotDkkdErrorPage(page: Page, step: string): void {
  const currentUrl = page.url();

  if (currentUrl.includes(DKKD_ERROR_PATH)) {
    throw new Error(`${DKKD_ERROR_CODE}: ${DKKD_ERROR_MESSAGE} [step=${step}]`);
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
      throw new Error(`${DKKD_ERROR_CODE}: ${DKKD_ERROR_MESSAGE} [step=${step}]`);
    }
  } catch (err: any) {
    // Nếu timeout xảy ra, kiểm tra URL hiện tại — trang có thể đã redirect
    // sang error page nhưng chưa kịp resolve Promise.race
    if (page.url().includes(DKKD_ERROR_PATH)) {
      throw new Error(`${DKKD_ERROR_CODE}: ${DKKD_ERROR_MESSAGE} [step=${step}]`);
    }
    throw err;
  }
}

export async function openSite(page: Page) {
  console.log("🌐 Opening page...");
  await page.goto(SITE_URL, {
    waitUntil: "domcontentloaded",
  });
  assertNotDkkdErrorPage(page, "openSite");
}

export async function fillSearchForm(page: Page, fromDate?: string, toDate?: string) {
  assertNotDkkdErrorPage(page, "fillSearchForm:start");
  console.log("📌 Selecting announcement type...");

  await waitForSelectorOrDkkdError(
    page,
    "#ctl00_C_ANNOUNCEMENT_TYPE_IDFilterFld",
    "fillSearchForm:announcementType"
  );

  try {
    await page.selectOption(
      "#ctl00_C_ANNOUNCEMENT_TYPE_IDFilterFld",
      "NEW"
    );
  } catch (error: any) {
    // Nếu selectOption timeout, trang có thể đã redirect sang error page
    if (page.url().includes(DKKD_ERROR_PATH)) {
      throw new Error(`${DKKD_ERROR_CODE}: ${DKKD_ERROR_MESSAGE} [step=fillSearchForm:selectOption]`);
    }
    throw error;
  }

  await waitForSelectorOrDkkdError(
    page,
    "#ctl00_C_PUBLISH_DATEFilterFldFrom",
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
    "#ctl00_C_PUBLISH_DATEFilterFldFrom",
    fromDate || getStartDay()
  );

  await page.fill(
    "#ctl00_C_PUBLISH_DATEFilterFldTo",
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

export async function submitSearch(page: Page, token: string) {
  assertNotDkkdErrorPage(page, "submitSearch:start");
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

  await page.click("#ctl00_C_BtnFilter");

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

  console.log("✅ Result table updated");
  assertNotDkkdErrorPage(page, "submitSearch:end");
}
