import { Request, Response } from "express";
import { runScrape } from "../services/scrape.service.js";

let isScraping = false;

export async function runScrapeController(req: Request, res: Response) {
  if (isScraping) {
    return res.status(409).json({
      success: false,
      message: "Scraper is already running. Please wait for the current job to finish.",
    });
  }

  isScraping = true;

  try {
    const result = await runScrape(req.body);

    return res.json({
      success: true,
      data: result,
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