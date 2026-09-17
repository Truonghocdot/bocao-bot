import { Router } from "express";
import {
  downloadScrapeController,
  inspectScrapeController,
  runScrapeController,
} from "../controllers/scrape.controller.js";

const router = Router();

router.post("/", runScrapeController);
router.post("/inspect", inspectScrapeController);
router.post("/download", downloadScrapeController);

export default router;
