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

  if (level === "error" || level === "warn") {
    process.stderr.write(output);
    return;
  }

  process.stdout.write(output);
}

export function setupConsoleLogger(): void {
  console.log = (...args: unknown[]) => write("info", args);
  console.info = (...args: unknown[]) => write("info", args);
  console.warn = (...args: unknown[]) => write("warn", args);
  console.error = (...args: unknown[]) => write("error", args);
  console.debug = (...args: unknown[]) => write("debug", args);
}

