import axios from "axios";
import { RECAPTCHA_SITE_KEY } from "../utils/contants.js";

export async function solveCaptcha(pageUrl: string): Promise<string> {
  console.log("🔄 Creating captcha task...");
  const API_KEY = process.env.CAP_SOLVER_KEY || process.env.CAP_SOLVER_API_KEY;

  if (!API_KEY) {
    throw new Error("CAP_SOLVER_KEY is required!");
  }

  const { data: task } = await axios.post(
    "https://api.capsolver.com/createTask",
    {
      clientKey: API_KEY,
      task: {
        type: "ReCaptchaV2TaskProxyLess",
        websiteURL: pageUrl,
        websiteKey: RECAPTCHA_SITE_KEY,
        isInvisible: false,
      },
    }
  );

  if (!task.taskId) {
    throw new Error("Cannot create captcha task");
  }

  const taskId = task.taskId;

  for (let i = 0; i < 40; i++) {
    await new Promise((r) => setTimeout(r, 3500));

    const { data: result } = await axios.post(
      "https://api.capsolver.com/getTaskResult",
      {
        clientKey: API_KEY,
        taskId,
      }
    );

    if (result.status === "ready") {
      console.log("✅ Captcha solved");
      return result.solution.gRecaptchaResponse;
    }

    if (result.status === "failed") {
      throw new Error(result.errorDescription || "Captcha failed");
    }
  }

  throw new Error("Captcha timeout");
}