import express from "express";
import qrcode from "qrcode-terminal";
import pino from "pino";
import makeWASocket, {
  DisconnectReason,
  fetchLatestBaileysVersion,
  useMultiFileAuthState,
} from "@whiskeysockets/baileys";

const PORT = Number(process.env.PORT || 3001);
const SERVICE_TOKEN = process.env.SERVICE_TOKEN || "";

if (!SERVICE_TOKEN) {
  console.error("SERVICE_TOKEN es obligatorio. Ejemplo: SERVICE_TOKEN=token-largo npm start");
  process.exit(1);
}

const app = express();
app.use(express.json({ limit: "15mb" }));

let sock = null;
let connectionState = "starting";
let reconnecting = false;

function requireToken(req, res, next) {
  if (req.header("x-api-token") !== SERVICE_TOKEN) {
    return res.status(401).json({ ok: false, message: "Token invalido" });
  }

  return next();
}

async function startWhatsApp() {
  const { state, saveCreds } = await useMultiFileAuthState("auth_info");
  const { version } = await fetchLatestBaileysVersion();

  sock = makeWASocket({
    auth: state,
    version,
    logger: pino({ level: "silent" }),
    printQRInTerminal: false,
  });

  sock.ev.on("creds.update", saveCreds);
  sock.ev.on("connection.update", async (update) => {
    const { connection, lastDisconnect, qr } = update;

    if (qr) {
      connectionState = "qr";
      console.log("Escanea este QR desde WhatsApp > Dispositivos vinculados:");
      qrcode.generate(qr, { small: true });
    }

    if (connection === "open") {
      connectionState = "connected";
      reconnecting = false;
      console.log("Conectado a WhatsApp correctamente.");
    }

    if (connection === "close") {
      const statusCode = lastDisconnect?.error?.output?.statusCode;
      const shouldReconnect = statusCode !== DisconnectReason.loggedOut;
      connectionState = shouldReconnect ? "reconnecting" : "logged_out";
      console.log("Conexion cerrada.", { statusCode, shouldReconnect });

      if (shouldReconnect && !reconnecting) {
        reconnecting = true;
        setTimeout(() => {
          reconnecting = false;
          void startWhatsApp();
        }, 5000);
      }
    }
  });
}

app.get("/health", requireToken, (_req, res) => {
  res.json({ ok: true, state: connectionState });
});

app.get("/groups", requireToken, async (_req, res) => {
  if (!sock || connectionState !== "connected") {
    return res.status(503).json({ ok: false, message: "WhatsApp no esta conectado", state: connectionState });
  }

  const groups = await sock.groupFetchAllParticipating();
  const rows = Object.values(groups)
    .map((group) => ({
      id: group.id,
      name: group.subject,
      participants: group.participants?.length ?? 0,
    }))
    .sort((a, b) => a.name.localeCompare(b.name));

  return res.json({ ok: true, groups: rows });
});

app.post("/send-image", requireToken, async (req, res) => {
  if (!sock || connectionState !== "connected") {
    return res.status(503).json({ ok: false, message: "WhatsApp no esta conectado", state: connectionState });
  }

  const { groupId, imageBase64, caption = "", mimeType = "image/png" } = req.body || {};

  if (!groupId || typeof groupId !== "string" || !groupId.endsWith("@g.us")) {
    return res.status(422).json({ ok: false, message: "groupId debe ser el id real del grupo y terminar en @g.us" });
  }

  if (!imageBase64 || typeof imageBase64 !== "string") {
    return res.status(422).json({ ok: false, message: "imageBase64 es obligatorio" });
  }

  const buffer = Buffer.from(imageBase64, "base64");

  await sock.sendMessage(groupId, {
    image: buffer,
    mimetype: mimeType,
    caption,
  });

  return res.json({ ok: true, groupId });
});

app.listen(PORT, () => {
  console.log(`WhatsApp service escuchando en http://127.0.0.1:${PORT}`);
});

void startWhatsApp();
