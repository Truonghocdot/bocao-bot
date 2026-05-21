import fs from "fs";
import path from "path";

type Level = "debug" | "info" | "warn" | "error";

const LEVEL_MAP: Record<Level, string> = {
  debug: "DEBUG",
  info: "INFO",
  warn: "WARNING",
  error: "ERROR",
};

function nowString(): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");

  return [
    d.getFullYear(),
    "-",
    pad(d.getMonth() + 1),
    "-",
    pad(d.getDate()),
    " ",
    pad(d.getHours()),
    ":",
    pad(d.getMinutes()),
    ":",
    pad(d.getSeconds()),
  ].join("");
}

function sanitize(value: unknown): string {
  if (value instanceof Error) {
    const message = value.stack || value.message || String(value);
    return message.replace(/\s+/g, " ").trim();
  }

  if (typeof value === "string") {
    return value.replace(/\s+/g, " ").trim();
  }

  try {
    return JSON.stringify(value);
  } catch {
    return String(value);
  }
}

function formatLine(level: Level, args: unknown[]): string {
  const appEnv = process.env.APP_ENV || process.env.NODE_ENV || "production";
  const message = args.map(sanitize).join(" ");
  return `[${nowString()}] ${appEnv}.${LEVEL_MAP[level]}: ${message}`;
}

function write(level: Level, args: unknown[]): void {
  const line = formatLine(level, args);
  const output = `${line}\n`;

  writeToLogFile(output);

  if (level === "error" || level === "warn") {
    process.stderr.write(output);
    return;
  }

  process.stdout.write(output);
}

function logFilePath(): string {
  return process.env.SCRAPER_LOG_FILE
    || path.resolve(process.cwd(), "..", "laravel", "storage", "logs", "scraper.log");
}

function writeToLogFile(output: string): void {
  const filePath = logFilePath();

  try {
    fs.mkdirSync(path.dirname(filePath), { recursive: true });
    fs.appendFileSync(filePath, output, "utf8");
  } catch {
    // Do not break runtime logging if file logging is unavailable.
  }
}

export function setupConsoleLogger(): void {
  console.log = (...args: unknown[]) => write("info", args);
  console.info = (...args: unknown[]) => write("info", args);
  console.warn = (...args: unknown[]) => write("warn", args);
  console.error = (...args: unknown[]) => write("error", args);
  console.debug = (...args: unknown[]) => write("debug", args);
}
