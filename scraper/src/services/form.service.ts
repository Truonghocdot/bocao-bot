import { Page } from "@playwright/test";
import { SITE_URL } from "../utils/contants.js";
import { getEndDay, getStartDay } from "../utils/date.js";
export async function openSite(page: Page) {
  console.log("🌐 Opening page...");
  await page.goto(SITE_URL, {
    waitUntil: "domcontentloaded",
  });
}

export async function fillSearchForm(page: Page, fromDate?: string, toDate?: string) {
  console.log("📌 Selecting announcement type...");

  await page.selectOption(
    "#ctl00_C_ANNOUNCEMENT_TYPE_IDFilterFld",
    "NEW"
  );

  await page.waitForTimeout(1500);

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

  await page.waitForTimeout(2000);
}

export async function submitSearch(page: Page, token: string) {
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
}
