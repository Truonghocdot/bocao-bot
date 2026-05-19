import express from "express";
import cors from "cors";
import dotenv from "dotenv";
import scrapeRoute from "./routes/scrape.route.js";

dotenv.config();

const app = express();

app.use(cors());
app.use(express.json({ limit: "10mb" }));

// Health check
app.get("/health", (_req, res) => {
  res.json({ status: "ok", timestamp: new Date().toISOString() });
});

app.use("/api/scrape", scrapeRoute);

const PORT = Number(process.env.PORT) || 3333;

app.listen(PORT, "127.0.0.1", () => {
  console.log(`🚀 Scraper service running on http://127.0.0.1:${PORT}`);
});