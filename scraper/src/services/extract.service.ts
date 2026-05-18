import { Page } from "@playwright/test";
import path from "path";

export async function extractRows(page: Page): Promise<number[]> {
  const rows = page.locator('tr:has(input[id*="LnkGetPDFActive"])');
  const count = await rows.count();

  console.log(`📄 Found ${count} PDFs`);

  return Array.from({ length: count }, (_, i) => i);
}

export async function downloadAllPdfs(
  page: Page,
  indices: number[],
  downloadDir: string
) {
  const rows = page.locator('tr:has(input[id*="LnkGetPDFActive"])');

  for (const i of indices) {
    const row = rows.nth(i);

    const companyName = await row
      .locator(".enterprise_name")
      .first()
      .innerText();

    const safeName = companyName
      .trim()
      .replace(/[^\p{L}\p{N}\s-]/gu, "")
      .replace(/\s+/g, "_");

    const filename = `${String(i + 1).padStart(3, "0")}_${safeName}.pdf`;

    const button = row.locator('input[id*="LnkGetPDFActive"]');

    const downloadPromise = page.waitForEvent("download");

    await button.click();

    const download = await downloadPromise;

    await download.saveAs(path.join(downloadDir, filename));

    console.log(`⬇️ Downloaded: ${filename}`);

    await page.waitForTimeout(1000);
  }
}