import { createBrowser, createPage } from "./browser/index.js";
import { openSite, fillSearchForm, submitSearch } from "./services/form.service.js";
import { extractRows, downloadAllPdfs } from "./services/extract.service.js";
import { solveCaptcha } from "./captcha/index.js";
import { generateDownloadDir } from "./utils/date.js";
import { isDebug } from "./utils/contants.js";
import fs from "fs";
import dotenv from "dotenv";

dotenv.config();

async function main() {
  const downloadDir = generateDownloadDir();
  fs.mkdirSync(downloadDir, { recursive: true });

  const browser = await createBrowser();
  const page = await createPage(browser);

  try {
    await openSite(page);

    await fillSearchForm(page);

    console.log("🤖 Solving captcha...");
    const token = await solveCaptcha(page.url());

    await submitSearch(page, token);

    const items = await extractRows(page);

    await downloadAllPdfs(page, items, downloadDir);

    console.log("🎉 DONE");
    console.log(`📁 Saved to: ${downloadDir}`);

    if (isDebug) {
      await page.pause();
    }
  } catch (error: any) {
    console.error("❌ ERROR:", error.message);
    await page.screenshot({
      path: `error-${Date.now()}.png`,
      fullPage: true,
    });
  } finally {
    await browser.close();
  }
}

main().catch(console.error);