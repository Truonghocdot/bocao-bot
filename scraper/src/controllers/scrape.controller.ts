import { Request, Response } from "express";
import { runScrape } from "../services/scrape.service.js";

export async function runScrapeController(req: Request, res: Response) {
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
  }
}