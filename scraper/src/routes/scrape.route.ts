import { Router } from "express";
import { runScrapeController } from "../controllers/scrape.controller.js";

const router = Router();

router.post("/", runScrapeController);

export default router;