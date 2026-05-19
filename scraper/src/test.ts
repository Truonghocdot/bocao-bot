/**
 * Script chạy test thủ công từ CLI
 * 
 * Sử dụng:
 *   pnpm run test:scrape              → dry run hôm nay
 *   pnpm run test:scrape -- --full    → chạy thật (có tải PDF + ZIP)
 *   DEBUG=1 pnpm run test:scrape      → mở browser hiển thị + pause cuối
 */

import dotenv from "dotenv";
import { scrapeDKKD } from "./playwright/index.js";
import { formatDate } from "./utils/date.js";

dotenv.config();

const isFullRun = process.argv.includes("--full");

const threeDaysAgo = formatDate(new Date(Date.now() - 1000 * 60 * 60 * 24 * 3));
const today = formatDate(new Date());


console.log("=====================================");
console.log(isFullRun ? "🚀 TEST — FULL RUN" : "🔍 TEST — DRY RUN (no downloads)");
console.log(`📅 Từ: ${threeDaysAgo} → ${today}`);
console.log("=====================================\n");

const result = await scrapeDKKD({
  fromDate: threeDaysAgo,
  toDate: today,
  dryRun: !isFullRun,
  limit: 1,
});

console.log("\n=====================================");
console.log("📊 KẾT QUẢ:");
console.log(JSON.stringify(result, null, 2));
console.log("=====================================");
