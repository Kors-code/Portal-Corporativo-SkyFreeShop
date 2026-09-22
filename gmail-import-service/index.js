import "dotenv/config";
import fs from "node:fs/promises";
import { createReadStream } from "node:fs";
import path from "node:path";
import axios from "axios";
import FormData from "form-data";
import cron from "node-cron";
import { google } from "googleapis";

const SCOPES = ["https://www.googleapis.com/auth/gmail.readonly"];
const SPREADSHEET_EXTENSIONS = [".xlsx", ".xls", ".xlsm", ".csv"];
const CV_EXTENSIONS = [".pdf", ".doc", ".docx"];

const config = {
  backendUrl: trimRight(process.env.BACKEND_URL || "http://127.0.0.1:8000", "/"),
  token: process.env.IMPORT_AUTOMATION_TOKEN || "",
  credentialsPath: process.env.GMAIL_CREDENTIALS_PATH || "./credentials.json",
  tokenPath: process.env.GMAIL_TOKEN_PATH || "./token.json",
  downloadDir: process.env.GMAIL_DOWNLOAD_DIR || "./downloads",
  statePath: process.env.STATE_PATH || "./state.json",
  runOnStart: String(process.env.RUN_ON_START || "true").toLowerCase() === "true",
  cronSchedule: process.env.CRON_SCHEDULE || "*/30 * * * *",
  timezone: process.env.TIMEZONE || "America/Bogota",
  catalogEnabled: String(process.env.CATALOG_ENABLED || "true").toLowerCase() === "true",
  catalogQuery: process.env.CATALOG_QUERY || "",
  catalogEndpoint: process.env.CATALOG_ENDPOINT || "/api/automation/import-catalog",
  inventoryEndpoint: process.env.INVENTORY_ENDPOINT || "/api/v1/inventory/import-automation",
  inventoryRules: parseInventoryRules(process.env.INVENTORY_RULES_JSON || "[]"),
  resumesEndpoint: process.env.RESUMES_ENDPOINT || "/api/automation/import-cvs",
  resumesAutoEndpoint: process.env.RESUMES_AUTO_ENDPOINT || "/api/automation/import-cvs/auto",
  resumeRules: parseResumeRules(process.env.RESUME_RULES_JSON || "[]"),
  resumeAutoEnabled: String(process.env.RESUME_AUTO_ENABLED || "false").toLowerCase() === "true",
  resumeAutoQuery: process.env.RESUME_AUTO_QUERY || "",
  gmailMaxResults: Number(process.env.GMAIL_MAX_RESULTS || 25),
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
      fileField: "file",
      allowedExtensions: SPREADSHEET_EXTENSIONS,
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
      fileField: "file",
      allowedExtensions: SPREADSHEET_EXTENSIONS,
    });
  }

  for (const rule of config.resumeRules) {
    if (!rule.query || !rule.vacanteSlug) {
      continue;
    }

    jobs.push({
      kind: "resume",
      name: rule.name || rule.vacanteSlug,
      query: rule.query,
      endpoint: config.resumesEndpoint,
      fields: { vacante_slug: String(rule.vacanteSlug) },
      fileField: "cv",
      allowedExtensions: CV_EXTENSIONS,
    });
  }

  if (config.resumeAutoEnabled && config.resumeAutoQuery) {
    jobs.push({
      kind: "resume_auto",
      name: "auto",
      query: config.resumeAutoQuery,
      endpoint: config.resumesAutoEndpoint,
      fields: {},
      fileField: "cv",
      allowedExtensions: CV_EXTENSIONS,
    });
  }

  return jobs;
}

async function processJob(gmail, state, job) {
  console.log(`Buscando ${job.kind}:${job.name} con query: ${job.query}`);

  const response = await gmail.users.messages.list({
    userId: "me",
    q: job.query,
    maxResults: config.gmailMaxResults,
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

    const attachments = findAttachments(message.data.payload, job.allowedExtensions);
    const sender = parseSender(getHeader(message.data.payload, "From"));
    const subject = getHeader(message.data.payload, "Subject");

    for (const attachment of attachments) {
      const globalAttachmentKey = `attachment:${messageRef.id}:${attachment.attachmentId}`;
      if (state.processed[globalAttachmentKey]) {
        continue;
      }

      const stateKey = `${job.kind}:${job.name}:${messageRef.id}:${attachment.attachmentId}`;
      if (state.processed[stateKey]) {
        continue;
      }

      const filePath = await downloadAttachment(gmail, messageRef.id, attachment);
      let result;
      try {
        result = await postFile(job, filePath, attachment.filename, {
          sender,
          subject,
          messageId: messageRef.id,
        });
      } catch (error) {
        console.error("No se pudo importar adjunto:", describeHttpError(error, {
          job: `${job.kind}:${job.name}`,
          endpoint: job.endpoint,
          filename: attachment.filename,
          messageId: messageRef.id,
        }));
        continue;
      }

      state.processed[stateKey] = {
        at: new Date().toISOString(),
        filename: attachment.filename,
        endpoint: job.endpoint,
        response: result,
      };
      state.processed[globalAttachmentKey] = {
        at: new Date().toISOString(),
        filename: attachment.filename,
        job: `${job.kind}:${job.name}`,
      };
      if (result?.duplicate) {
        console.log(`Duplicado detectado ${attachment.filename} (candidato_id=${result.candidato_id})`);
      } else {
        console.log(`Importado ${attachment.filename} en ${job.endpoint}`);
      }
    }
  }
}

function findAttachments(payload, allowedExtensions) {
  const extensions = new Set(allowedExtensions || []);
  const found = [];
  const walk = (part) => {
    if (!part) return;

    const filename = part.filename || "";
    const extension = path.extname(filename).toLowerCase();
    const attachmentId = part.body?.attachmentId;

    if (filename && attachmentId && extensions.has(extension)) {
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

async function postFile(job, filePath, filename, messageMeta) {
  const form = new FormData();
  form.append(job.fileField || "file", createReadStream(filePath), filename);

  for (const [key, value] of Object.entries(job.fields || {})) {
    form.append(key, value);
  }

  if (job.kind === "resume" || job.kind === "resume_auto") {
    const sender = messageMeta.sender || {};

    if (sender.email) {
      form.append("sender_email", sender.email);
    }
    if (sender.name) {
      form.append("sender_name", truncate(sender.name, 100));
    }
    if (messageMeta.subject) {
      form.append("email_subject", truncate(messageMeta.subject, 250));
    }
    if (messageMeta.messageId) {
      form.append("gmail_message_id", messageMeta.messageId);
    }
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

function parseResumeRules(value) {
  try {
    const parsed = JSON.parse(value);
    return Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    throw new Error(`RESUME_RULES_JSON invalido: ${error.message}`);
  }
}

function validateConfig() {
  if (!config.token) {
    throw new Error("IMPORT_AUTOMATION_TOKEN es obligatorio.");
  }

  if (
    !config.catalogQuery &&
    config.inventoryRules.length === 0 &&
    config.resumeRules.length === 0 &&
    !(config.resumeAutoEnabled && config.resumeAutoQuery)
  ) {
    throw new Error("Configura CATALOG_QUERY, INVENTORY_RULES_JSON, RESUME_RULES_JSON o RESUME_AUTO_QUERY.");
  }
}

function getHeader(payload, name) {
  const headers = payload?.headers || [];
  const header = headers.find((item) => String(item.name || "").toLowerCase() === name.toLowerCase());
  return header?.value || "";
}

function parseSender(value) {
  const raw = String(value || "").trim();
  const match = raw.match(/^(.*?)\s*<([^>]+)>$/);

  if (match) {
    return {
      name: match[1].replace(/^"|"$/g, "").trim(),
      email: match[2].trim(),
    };
  }

  return raw.includes("@") ? { name: "", email: raw } : { name: raw, email: "" };
}

function truncate(value, maxLength) {
  const text = String(value || "");
  return text.length > maxLength ? text.slice(0, maxLength) : text;
}

function describeHttpError(error, context = {}) {
  const response = error?.response;

  if (response) {
    return {
      ...context,
      status: response.status,
      statusText: response.statusText,
      message: response.data?.message || error.message,
    };
  }

  return {
    ...context,
    message: error?.message || String(error),
  };
}

function trimRight(value, char) {
  let output = value;
  while (output.endsWith(char)) {
    output = output.slice(0, -1);
  }
  return output;
}

main().catch((error) => {
  console.error("Gmail import service fallo:", describeHttpError(error));
  process.exit(1);
});
