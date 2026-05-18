import { Telegraf, Context, Markup } from "telegraf";
import { message } from "telegraf/filters";
import db from "./db";
import cron from "node-cron";
import { runScraper } from "./scraper";
import fs from "fs";
import path from "path";

const bot = new Telegraf(process.env.TELEGRAM_BOT_TOKEN!);
const ALLOWED_USER_ID = process.env.ALLOWED_USER_ID!;

// Basic middleware to restrict access
bot.use(async (ctx, next) => {
  if (ctx.from?.id.toString() === ALLOWED_USER_ID) {
    return next();
  }
  if (ctx.chat?.type === "private") {
    await ctx.reply("Bạn không có quyền sử dụng bot này.");
  }
});

// User session state (in memory for simplicity, though DB is better for production)
const userState: Record<string, any> = {};

bot.command("start", (ctx) => {
  ctx.reply(
    "Chào mừng bạn đến với Bot tải PDF Bố Cáo Điện Tử.\nCác lệnh khả dụng:\n/newjob - Tạo lịch tải mới\n/jobs - Xem danh sách lịch tải\n/proxies - Quản lý proxy"
  );
});

bot.command("newjob", (ctx) => {
  userState[ctx.from.id] = { step: "start_date" };
  ctx.reply("Vui lòng nhập ngày bắt đầu (Định dạng: DD/MM/YYYY, vd: 16/05/2026):");
});

bot.command("proxies", (ctx) => {
  const proxies = db.prepare("SELECT * FROM proxies").all() as any[];
  if (proxies.length === 0) {
    return ctx.reply("Chưa có proxy nào. Dùng lệnh /addproxy <url> để thêm.");
  }
  const text = proxies.map(p => `ID: ${p.id} - ${p.url} - Trạng thái: ${p.is_active ? '✅' : '❌'}`).join('\n');
  ctx.reply(`Danh sách Proxy:\n${text}`);
});

bot.command("addproxy", (ctx) => {
  const url = ctx.message.text.split(" ")[1];
  if (!url) return ctx.reply("Sử dụng: /addproxy <url_proxy> (vd: http://user:pass@ip:port)");
  
  db.prepare("INSERT INTO proxies (url) VALUES (?)").run(url);
  ctx.reply(`✅ Đã thêm proxy: ${url}`);
});

bot.command("jobs", (ctx) => {
  const jobs = db.prepare("SELECT * FROM jobs ORDER BY id DESC LIMIT 5").all() as any[];
  if (jobs.length === 0) return ctx.reply("Chưa có job nào.");
  const text = jobs.map(j => `Job #${j.id}: ${j.start_date} -> ${j.end_date} | ${j.status}`).join('\n');
  ctx.reply(`5 Jobs gần nhất:\n${text}`);
});

bot.on(message("text"), async (ctx) => {
  const userId = ctx.from.id;
  const state = userState[userId];

  if (!state) return;

  const text = ctx.message.text;

  switch (state.step) {
    case "start_date":
      state.startDate = text;
      state.step = "end_date";
      await ctx.reply("Vui lòng nhập ngày kết thúc (Định dạng: DD/MM/YYYY):");
      break;
    case "end_date":
      state.endDate = text;
      state.step = "max_pages";
      await ctx.reply("Nhập số trang muốn tải (Nhập 0 hoặc 'all' để tải tất cả):");
      break;
    case "max_pages":
      const pages = parseInt(text, 10);
      state.maxPages = isNaN(pages) || pages <= 0 ? null : pages;
      state.step = "scheduled_time";
      await ctx.reply("Nhập thời gian chạy hàng ngày (Định dạng HH:MM, vd: 14:30). Nhập 'now' để chạy ngay bây giờ:");
      break;
    case "scheduled_time":
      state.scheduledTime = text.toLowerCase() === "now" ? null : text;
      
      // Save job to DB
      const stmt = db.prepare(
        "INSERT INTO jobs (telegram_user_id, start_date, end_date, max_pages, scheduled_time, status) VALUES (?, ?, ?, ?, ?, ?)"
      );
      const info = stmt.run(
        userId.toString(),
        state.startDate,
        state.endDate,
        state.maxPages,
        state.scheduledTime,
        state.scheduledTime ? "pending" : "running"
      );

      delete userState[userId];
      
      if (state.scheduledTime) {
        await ctx.reply(`✅ Đã lên lịch thành công! ID: ${info.lastInsertRowid}. Sẽ chạy vào lúc ${state.scheduledTime} mỗi ngày.`);
        scheduleJob(Number(info.lastInsertRowid));
      } else {
        await ctx.reply(`✅ Đang bắt đầu chạy ngay lập tức! ID: ${info.lastInsertRowid}`);
        // Run immediately without blocking the bot thread completely
        runJob(Number(info.lastInsertRowid)).catch(console.error);
      }
      break;
  }
});

async function runJob(jobId: number) {
  const job = db.prepare("SELECT * FROM jobs WHERE id = ?").get(jobId) as any;
  if (!job) return;

  db.prepare("UPDATE jobs SET status = 'running' WHERE id = ?").run(jobId);
  bot.telegram.sendMessage(job.telegram_user_id, `🚀 Đang bắt đầu tiến trình cào dữ liệu cho Job #${jobId}...`);

  // Get active proxy
  const proxy = db.prepare("SELECT * FROM proxies WHERE is_active = 1 ORDER BY last_used ASC LIMIT 1").get() as any;
  if (proxy) {
    db.prepare("UPDATE proxies SET last_used = CURRENT_TIMESTAMP WHERE id = ?").run(proxy.id);
  }

  try {
    const downloadDir = await runScraper({
      startDate: job.start_date,
      endDate: job.end_date,
      maxPages: job.max_pages,
      proxyUrl: proxy?.url,
      jobId: job.id
    });

    bot.telegram.sendMessage(job.telegram_user_id, `✅ Đã cào xong Job #${jobId}. Đang chuẩn bị gửi file...`);

    // Send files
    const files = fs.readdirSync(downloadDir).filter(f => f.endsWith('.pdf'));
    if (files.length === 0) {
      bot.telegram.sendMessage(job.telegram_user_id, `Job #${jobId}: Không tìm thấy file PDF nào.`);
    } else {
      for (const file of files) {
        const filePath = path.join(downloadDir, file);
        await bot.telegram.sendDocument(job.telegram_user_id, {
          source: filePath,
          filename: file
        });
        await new Promise(r => setTimeout(r, 1000)); // avoid flood limits
      }
      bot.telegram.sendMessage(job.telegram_user_id, `🎉 Đã gửi toàn bộ ${files.length} file cho Job #${jobId}.`);
    }

  } catch (error: any) {
    bot.telegram.sendMessage(job.telegram_user_id, `❌ Job #${jobId} thất bại: ${error.message}`);
  }
}

function scheduleJob(jobId: number) {
  const job = db.prepare("SELECT * FROM jobs WHERE id = ?").get(jobId) as any;
  if (!job || !job.scheduled_time) return;

  const [hour, minute] = job.scheduled_time.split(":");
  const cronExpr = `${minute} ${hour} * * *`;
  
  cron.schedule(cronExpr, () => {
    runJob(jobId);
  });
  console.log(`Scheduled Job ${jobId} at ${cronExpr}`);
}

export function loadSchedules() {
  const jobs = db.prepare("SELECT * FROM jobs WHERE scheduled_time IS NOT NULL AND status != 'failed'").all() as any[];
  for (const job of jobs) {
    scheduleJob(job.id);
  }
}

export default bot;
