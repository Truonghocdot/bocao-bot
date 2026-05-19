import { scrapeDKKD } from "../playwright/index.js";

export interface ScrapeRequestPayload {
  fromDate?: string;
  toDate?: string;
  limit?: number;
  dryRun?: boolean;
  downloadKey?: string;
}

export async function runScrape(payload: ScrapeRequestPayload) {
  return scrapeDKKD({
    fromDate: payload.fromDate,
    toDate: payload.toDate,
    limit: payload.limit,
    dryRun: payload.dryRun ?? false,
    downloadKey: payload.downloadKey,
  });
}
