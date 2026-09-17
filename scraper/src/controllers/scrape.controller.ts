import { Request, Response } from "express";
import { runScrape } from "../services/scrape.service.js";
import type { ScrapeRequestPayload } from "../services/scrape.service.js";

let isScraping = false;

async function executeScrape(
  payload: ScrapeRequestPayload,
  res: Response,
  legacyResponse = false
) {
  if (isScraping) {
    return res.status(409).json({
      success: false,
      message: "Scraper is already running. Please wait for the current job to finish.",
    });
  }

  isScraping = true;

  try {
    const result = await runScrape(payload);
    const data = legacyResponse && result.files
      ? { ...result, files: result.files.map((file) => file.path) }
      : result;

    return res.json({
      success: true,
      data,
    });
  } catch (error: any) {
    console.error("❌ Controller Error:", error.message);

    return res.status(500).json({
      success: false,
      message: error.message,
    });
  } finally {
    isScraping = false;
  }
}

export async function runScrapeController(req: Request, res: Response) {
  return executeScrape(req.body, res, true);
}

export async function inspectScrapeController(req: Request, res: Response) {
  return executeScrape({
    ...req.body,
    dryRun: false,
    estimateOnly: true,
    limit: undefined,
    downloadKey: undefined,
  }, res);
}

export async function downloadScrapeController(req: Request, res: Response) {
  return executeScrape({
    ...req.body,
    dryRun: false,
    estimateOnly: false,
  }, res);
}
