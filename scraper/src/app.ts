import express from "express";
import cors from "cors";
import dotenv from "dotenv";
import scrapeRoute from "./routes/scrape.route.js";
import { setupConsoleLogger } from "./utils/logger.js";

dotenv.config();
setupConsoleLogger();

const app = express();

app.use(cors());
app.use(express.json({ limit: "10mb" }));

// Health check
app.get("/health", (_req, res) => {
  res.json({ status: "ok", timestamp: new Date().toISOString() });
});

app.use("/api/scrape", scrapeRoute);

const PORT = Number(process.env.PORT) || 3333;

const server = app.listen(PORT, "127.0.0.1", () => {
  console.log(`🚀 Scraper service running on http://127.0.0.1:${PORT}`);
});

server.on("error", (error) => {
  console.error("Scraper server failed", error);
  process.exit(1);
});

const keepAlive = setInterval(() => {
  // Keep the service process alive under pnpm/tsx + supervisor.
}, 60 * 60 * 1000);

process.on("unhandledRejection", (reason) => {
  console.error("Unhandled rejection in scraper process", reason);
});

process.on("uncaughtException", (error) => {
  console.error("Uncaught exception in scraper process", error);
});

process.on("SIGTERM", () => {
  console.info("Received SIGTERM, shutting down scraper server");
  clearInterval(keepAlive);
  server.close(() => process.exit(0));
});
