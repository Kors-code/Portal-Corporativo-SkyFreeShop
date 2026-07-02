import "dotenv/config";
import fs from "node:fs/promises";
import { createReadStream } from "node:fs";
import path from "node:path";
import axios from "axios";
import FormData from "form-data";
import cron from "node-cron";
import { google } from "googleapis";

const SCOPES = ["https://www.googleapis.com/auth/gmail.readonly"];
const ALLOWED_EXTENSIONS = new Set([".xlsx", ".xls", ".xlsm", ".csv"]);

const config = {
  backendUrl: trimRight(process.env.BACKEND_URL || "http://127.0.0.1:8000", "/"),
  token: process.env.IMPORT_AUTOMATION_TOKEN || "",
  credentialsPath: process.env.GMAIL_CREDENTIALS_PATH || "./credentials.json",
  tokenPath: process.env.GMAIL_TOKEN_PATH || "./token.json",
  downloadDir: process.env.GMAIL_DOWNLOAD_DIR || "./downloads",
  statePath: process.env.STATE_PATH || "./state.json",
  runOnStart: String(process.env.RUN_ON_START || "true").toLowerCase() === "true",
  cronSchedule: process.env.CRON_SCHEDULE || "0 7 * * *",
  timezone: process.env.TIMEZONE || "America/Bogota",
  catalogEnabled: String(process.env.CATALOG_ENABLED || "true").toLowerCase() === "true",
  catalogQuery: process.env.CATALOG_QUERY || "",
  catalogEndpoint: process.env.CATALOG_ENDPOINT || "/api/automation/import-catalog",
  inventoryEndpoint: process.env.INVENTORY_ENDPOINT || "/api/v1/inventory/import-automation",
  inventoryRules: parseInventoryRules(process.env.INVENTORY_RULES_JSON || "[]"),
};

async function main() {
  const command = process.argv[2] || "start";

  if (command === "auth-url") {
    const auth = await createAuthClient(false);
    console.log(auth.generateAuthUrl({ access_type: "offline", prompt: "consent", scope: SCOPES }));
    return;
  }

  if (command === "auth-code") {
    const code = process.env.AUTH_CODE || process.argv[3];
    if (!code) {
      throw new Error("Pasa el codigo como AUTH_CODE=... npm run auth-code o npm run auth-code -- CODIGO");
    }

    const auth = await createAuthClient(false);
    const { tokens } = await auth.getToken(code);
    await fs.writeFile(config.tokenPath, JSON.stringify(tokens, null, 2));
    console.log(`Token guardado en ${config.tokenPath}`);
    return;
  }

  validateConfig();

  if (command === "once") {
    await runOnce();
    return;
  }

  if (config.runOnStart) {
    await runOnce();
  }

  cron.schedule(config.cronSchedule, () => {
    runOnce().catch((error) => {
      console.error("Ejecucion programada fallo:", error.message);
    });
  }, { timezone: config.timezone });

  console.log(`Gmail import service activo. Cron: ${config.cronSchedule} (${config.timezone})`);
}

async function runOnce() {
  const gmail = google.gmail({ version: "v1", auth: await createAuthClient(true) });
  const state = await readState();
  const jobs = buildJobs();

  await fs.mkdir(config.downloadDir, { recursive: true });

  for (const job of jobs) {
    await processJob(gmail, state, job);
  }

  await writeState(state);
}

function buildJobs() {
  const jobs = [];

  if (config.catalogEnabled && config.catalogQuery) {
    jobs.push({
      kind: "catalog",
      name: "catalog",
      query: config.catalogQuery,
      endpoint: config.catalogEndpoint,
      fields: {},
    });
  }

  for (const rule of config.inventoryRules) {
    if (!rule.query || !rule.storeId) {
      continue;
    }

    jobs.push({
      kind: "inventory",
      name: rule.name || `store-${rule.storeId}`,
      query: rule.query,
      endpoint: config.inventoryEndpoint,
      fields: { store_id: String(rule.storeId) },
    });
  }

  return jobs;
}

async function processJob(gmail, state, job) {
  console.log(`Buscando ${job.kind}:${job.name} con query: ${job.query}`);

  const response = await gmail.users.messages.list({
    userId: "me",
    q: job.query,
    maxResults: 10,
  });

  const messages = response.data.messages || [];
  if (messages.length === 0) {
    console.log(`Sin correos para ${job.name}`);
    return;
  }

  for (const messageRef of messages.reverse()) {
    const message = await gmail.users.messages.get({
      userId: "me",
      id: messageRef.id,
      format: "full",
    });

    const attachments = findSpreadsheetAttachments(message.data.payload);
    for (const attachment of attachments) {
      const stateKey = `${job.kind}:${job.name}:${messageRef.id}:${attachment.attachmentId}`;
      if (state.processed[stateKey]) {
        continue;
      }

      const filePath = await downloadAttachment(gmail, messageRef.id, attachment);
      const result = await postFile(job, filePath, attachment.filename);
      state.processed[stateKey] = {
        at: new Date().toISOString(),
        filename: attachment.filename,
        endpoint: job.endpoint,
        response: result,
      };
      console.log(`Importado ${attachment.filename} en ${job.endpoint}`);
    }
  }
}

function findSpreadsheetAttachments(payload) {
  const found = [];
  const walk = (part) => {
    if (!part) return;

    const filename = part.filename || "";
    const extension = path.extname(filename).toLowerCase();
    const attachmentId = part.body?.attachmentId;

    if (filename && attachmentId && ALLOWED_EXTENSIONS.has(extension)) {
      found.push({ filename, attachmentId, mimeType: part.mimeType || "application/octet-stream" });
    }

    for (const child of part.parts || []) {
      walk(child);
    }
  };

  walk(payload);
  return found;
}

async function downloadAttachment(gmail, messageId, attachment) {
  const response = await gmail.users.messages.attachments.get({
    userId: "me",
    messageId,
    id: attachment.attachmentId,
  });

  const bytes = Buffer.from(response.data.data || "", "base64url");
  const safeName = `${Date.now()}-${attachment.filename.replace(/[^\w.\-]+/g, "_")}`;
  const filePath = path.join(config.downloadDir, safeName);
  await fs.writeFile(filePath, bytes);
  return filePath;
}

async function postFile(job, filePath, filename) {
  const form = new FormData();
  form.append("file", createReadStream(filePath), filename);

  for (const [key, value] of Object.entries(job.fields || {})) {
    form.append(key, value);
  }

  const response = await axios.post(`${config.backendUrl}${job.endpoint}`, form, {
    headers: {
      ...form.getHeaders(),
      "X-Automation-Token": config.token,
      Accept: "application/json",
    },
    maxBodyLength: Infinity,
    maxContentLength: Infinity,
    timeout: 120000,
  });

  return response.data;
}

async function createAuthClient(requireToken) {
  const credentials = JSON.parse(await fs.readFile(config.credentialsPath, "utf8"));
  const installed = credentials.installed || credentials.web;
  if (!installed) {
    throw new Error("credentials.json debe tener formato OAuth de Google: installed o web.");
  }

  const auth = new google.auth.OAuth2(
    installed.client_id,
    installed.client_secret,
    (installed.redirect_uris || ["http://localhost"])[0],
  );

  if (requireToken) {
    const token = JSON.parse(await fs.readFile(config.tokenPath, "utf8"));
    auth.setCredentials(token);
  }

  return auth;
}

async function readState() {
  try {
    const data = JSON.parse(await fs.readFile(config.statePath, "utf8"));
    return { processed: data.processed || {} };
  } catch {
    return { processed: {} };
  }
}

async function writeState(state) {
  await fs.writeFile(config.statePath, JSON.stringify(state, null, 2));
}

function parseInventoryRules(value) {
  try {
    const parsed = JSON.parse(value);
    return Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    throw new Error(`INVENTORY_RULES_JSON invalido: ${error.message}`);
  }
}

function validateConfig() {
  if (!config.token) {
    throw new Error("IMPORT_AUTOMATION_TOKEN es obligatorio.");
  }

  if (!config.catalogQuery && config.inventoryRules.length === 0) {
    throw new Error("Configura CATALOG_QUERY o INVENTORY_RULES_JSON.");
  }
}

function trimRight(value, char) {
  let output = value;
  while (output.endsWith(char)) {
    output = output.slice(0, -1);
  }
  return output;
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
