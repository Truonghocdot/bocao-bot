import { chromium } from "@playwright/test";
import axios from "axios";
import dotenv from "dotenv";
import fs from "fs";
import path from "path";

dotenv.config();

const API_KEY = process.env.CAP_SOLVER_KEY!;
const SITE_URL =
  "https://bocaodientu.dkkd.gov.vn/egazette/Forms/Egazette/ANNOUNCEMENTSListingInsUpd.aspx";
const RECAPTCHA_SITE_KEY = "6LewYU4UAAAAAD9dQ51Cj_A_1uHLOXw9wJIxi9x0";
const START_DATE = "16/05/2026";
const END_DATE = "18/05/2026";
async function solveCaptcha(pageUrl: string): Promise<string> {
  const { data: task } = await axios.post(
    "https://api.capsolver.com/createTask",
    {
      clientKey: API_KEY,
      task: {
        type: "ReCaptchaV2TaskProxyLess",
        websiteURL: pageUrl,
        websiteKey: RECAPTCHA_SITE_KEY,
        isInvisible: false,
        pageAction: "submit", // thêm cái này
      },
    },
  );

  const taskId = task.taskId;

  for (let i = 0; i < 45; i++) {
    await new Promise((r) => setTimeout(r, 4000)); // tăng lên 4s

    const { data: result } = await axios.post(
      "https://api.capsolver.com/getTaskResult",
      {
        clientKey: API_KEY,
        taskId,
      },
    );

    if (result.status === "ready") return result.solution.gRecaptchaResponse;
    if (result.status === "failed") throw new Error(result.errorDescription);
  }

  throw new Error("Captcha timeout");
}
async function main() {
  const browser = await chromium.launch({
    headless: process.env.DEBUG !== "1",
    slowMo: process.env.DEBUG === "1" ? 800 : 0,
  });
  console.log("🚀 Browser launched");
  const context = await browser.newContext({
    viewport: { width: 1366, height: 768 },
    userAgent:
      "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36",
    locale: "vi-VN",
    timezoneId: "Asia/Ho_Chi_Minh",
    acceptDownloads: true,
  });

  const page = await context.newPage();

  try {
    await page.goto(SITE_URL, { waitUntil: "domcontentloaded" });

    console.log("Chọn loại tài liệu");
    // Chọn loại AMEND
    await page.selectOption("#ctl00_C_ANNOUNCEMENT_TYPE_IDFilterFld", "NEW");
    await page.waitForTimeout(2500);

    // Giải captcha NGAY SAU KHI FILTER THAY ĐỔI
    const token = await solveCaptcha(page.url());

    // Inject token + trigger callback mạnh mẽ
    await page.evaluate((token: string) => {
      const recaptchaResponse = document.getElementById(
        "g-recaptcha-response",
      ) as HTMLTextAreaElement;
      if (recaptchaResponse) {
        recaptchaResponse.value = token;
        recaptchaResponse.style.display = "block";
        recaptchaResponse.style.position = "fixed";
        recaptchaResponse.style.bottom = "0";
        recaptchaResponse.style.left = "0";
        recaptchaResponse.style.width = "1px";
        recaptchaResponse.style.height = "1px";
        recaptchaResponse.style.opacity = "0";
        recaptchaResponse.style.pointerEvents = "none";
        recaptchaResponse.style.zIndex = "-1";
        // Trigger sự kiện thay đổi để callback nhận diện
        recaptchaResponse.dispatchEvent(new Event("change", { bubbles: true }));
      }
    }, token);
    console.log("Captcha solved and token injected");

    // Xử lý date fields
    await page.evaluate(() => {
      document
        .querySelectorAll("input[readonly]")
        .forEach((el) => el.removeAttribute("readonly"));
    });

    await page.fill("#ctl00_C_PUBLISH_DATEFilterFldFrom", START_DATE);
    await page.fill("#ctl00_C_PUBLISH_DATEFilterFldTo", END_DATE);

    // remove field
    await page.evaluate(() => {
      document.querySelector("#ctl00_C_ENT_GDT_CODEFld")?.remove();
    });

    console.log("Điền thông tin xong, chuẩn bị submit...");

    console.log("Đang chờ timeout...");
    await page.waitForTimeout(4000);

    await page.evaluate(() => {
      (
        document.querySelector("#ctl00_C_BtnFilter") as HTMLInputElement
      ).click();
    });

    console.log("Đang chờ submit...");
    await page.waitForTimeout(8000);

    const now = new Date();
    const folderName = [
      now.getFullYear(),
      String(now.getMonth() + 1).padStart(2, "0"),
      String(now.getDate()).padStart(2, "0"),
      "-",
      String(now.getHours()).padStart(2, "0"),
      String(now.getMinutes()).padStart(2, "0"),
      String(now.getSeconds()).padStart(2, "0"),
    ].join("");

    const downloadDir = path.join("downloads", folderName);
    fs.mkdirSync(downloadDir, { recursive: true });

    const buttons = page.locator('input[id*="LnkGetPDFActive"]');
    const count = await buttons.count();

    console.log("Rows:", count);

    for (let i = 0; i < count; i++) {
      const button = buttons.nth(i);
      const row = button.locator('xpath=ancestor::tr').first();
      const companyName = await row.locator(".enterprise_name").first().innerText();

      const safeName = companyName
        .trim()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[^\p{L}\p{N}\s-]/gu, "")
        .replace(/\s+/g, "_");

      const downloadPromise = page.waitForEvent("download");
      await button.click();

      const download = await downloadPromise;
      const filename = `${String(i + 1).padStart(3, "0")}_${safeName}.pdf`;
      await download.saveAs(path.join(downloadDir, filename));

      console.log(`Downloaded: ${filename}`);
      await page.waitForTimeout(1000);
    }
    console.log("✅ Thành công! File PDF đã được lưu.");
  } catch (err: any) {
    console.error("❌ Lỗi:", err.message);
    await page.screenshot({ path: `error-${Date.now()}.png`, fullPage: true });
  } finally {
    await browser.close();
  }
}

main();
