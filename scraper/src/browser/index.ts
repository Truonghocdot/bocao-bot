import { chromium, Browser, Page } from "@playwright/test";
import { isDebug } from "../utils/contants.js";

export async function createBrowser(): Promise<Browser> {
  return chromium.launch({
    headless: !isDebug,
    slowMo: isDebug ? 600 : 0,
  });
}

export async function createPage(browser: Browser): Promise<Page> {
  const context = await browser.newContext({
    viewport: {
      width: 1366,
      height: 768,
    },
    userAgent:
      "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36",
    locale: "vi-VN",
    acceptDownloads: true,
  });

  await context.route("**/*", (route) => {
    const type = route.request().resourceType();

    if (["font", "image", "media"].includes(type)) {
      route.abort();
      return;
    }

    route.continue();
  });

  return context.newPage();
}
