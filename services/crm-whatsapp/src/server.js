import crypto from "node:crypto";
import fs from "node:fs/promises";
import path from "node:path";
import express from "express";
import QRCode from "qrcode";
import whatsappWeb from "whatsapp-web.js";

const { Client, RemoteAuth } = whatsappWeb;

const app = express();
// RemoteAuth stores a compressed Chromium profile. The encrypted copy is kept in CRM.
app.use(express.json({ limit: "24mb" }));

const port = Number(process.env.PORT || 3100);
const webhookUrl = process.env.CRM_WHATSAPP_WEBHOOK_URL;
const webhookSecret = process.env.CRM_WHATSAPP_WEBHOOK_SECRET;
const crmApiUrl = process.env.CRM_WHATSAPP_API_URL || webhookUrl?.replace(/\/integrations\/whatsapp\/events$/, "");
const remoteAuthPath = process.env.WHATSAPP_REMOTE_AUTH_PATH || "/tmp/whatsapp-remote-auth";
const sessions = new Map();
const sessionStarts = new Map();
const recentInboundMessages = new Map();
const recentOutboundMessages = new Map();

function remoteSessionName(tenantId) {
  return `RemoteAuth-tenant-${tenantId}`;
}

function isZipArchive(archive) {
  return archive?.length >= 4
    && archive[0] === 0x50
    && archive[1] === 0x4b
    && [0x03, 0x05, 0x07].includes(archive[2])
    && [0x04, 0x06, 0x08].includes(archive[3]);
}

/**
 * Preserves WhatsApp's canonical chat identifier for replies.
 * Incoming chats can use @c.us or the newer privacy-safe @lid identity format.
 */
function recipientId(value) {
  const raw = String(value || "").trim();
  if (/^\d+@(c\.us|lid)$/i.test(raw)) return raw;

  const digits = raw.replace(/\D/g, "");
  if (!digits) return null;
  const normalized = digits.startsWith("0") ? `62${digits.slice(1)}` : digits;
  return `${normalized}@c.us`;
}

function requireServiceSecret(req, res, next) {
  const received = req.get("x-crm-service-secret") || "";
  if (!webhookSecret || received.length !== webhookSecret.length || !crypto.timingSafeEqual(Buffer.from(received), Buffer.from(webhookSecret))) {
    return res.status(401).json({ message: "Unauthorized service request." });
  }
  next();
}

async function notify(tenantId, event, payload = {}) {
  if (!webhookUrl || !webhookSecret) return;
  const response = await fetch(webhookUrl, {
    method: "POST",
    headers: { "content-type": "application/json", "x-crm-service-secret": webhookSecret },
    body: JSON.stringify({ tenant_id: tenantId, event, ...payload }),
  });
  if (!response.ok) {
    const body = await response.text();
    throw new Error(`CRM webhook returned ${response.status}${body ? `: ${body.slice(0, 300)}` : ""}`);
  }
}

async function fetchSessionArchive(tenantId) {
  if (!crmApiUrl || !webhookSecret) return null;
  const response = await fetch(`${crmApiUrl}/integrations/whatsapp/session-backups/${tenantId}`, {
    headers: { "x-crm-service-secret": webhookSecret },
  });
  if (response.status === 404) return null;
  if (!response.ok) throw new Error(`CRM session backup returned ${response.status}`);
  const payload = await response.json();
  if (!payload.archive || typeof payload.archive !== "string") return null;

  const archive = Buffer.from(payload.archive, "base64");
  if (!archive.length || archive.length > 16 * 1024 * 1024) throw new Error("CRM session backup is invalid or too large.");
  return archive;
}

async function uploadSessionArchive(tenantId, archive) {
  if (!crmApiUrl || !webhookSecret) throw new Error("CRM session backup is not configured.");
  if (!isZipArchive(archive) || archive.length > 16 * 1024 * 1024) {
    throw new Error("WhatsApp RemoteAuth archive is invalid or too large.");
  }
  const response = await fetch(`${crmApiUrl}/integrations/whatsapp/session-backups`, {
    method: "POST",
    headers: { "content-type": "application/json", "x-crm-service-secret": webhookSecret },
    body: JSON.stringify({ tenant_id: tenantId, archive: archive.toString("base64") }),
  });
  if (!response.ok) throw new Error(`CRM session backup upload returned ${response.status}`);
}

/**
 * whatsapp-web.js RemoteAuth adapter. The local directory is intentionally temporary;
 * every recoverable session is encrypted at rest in the CRM database.
 */
class CrmRemoteAuthStore {
  constructor(tenantId) {
    this.tenantId = tenantId;
  }

  async sessionExists({ session }) {
    if (session !== remoteSessionName(this.tenantId)) return false;
    const archive = await fetchSessionArchive(this.tenantId);
    // LocalAuth backups are tgz files. Ignore them and ask for one final QR scan,
    // after which this method stores a portable RemoteAuth ZIP instead.
    return isZipArchive(archive);
  }

  async save({ session }) {
    if (session !== remoteSessionName(this.tenantId)) throw new Error("Unexpected WhatsApp session key.");
    const archive = await fs.readFile(path.join(remoteAuthPath, `${session}.zip`));
    await uploadSessionArchive(this.tenantId, archive);
    console.log(`Stored RemoteAuth WhatsApp session for ${this.tenantId}`);
  }

  async extract({ session, path: targetPath }) {
    if (session !== remoteSessionName(this.tenantId)) throw new Error("Unexpected WhatsApp session key.");
    const archive = await fetchSessionArchive(this.tenantId);
    if (!isZipArchive(archive)) throw new Error("RemoteAuth WhatsApp backup was not found.");
    await fs.mkdir(path.dirname(targetPath), { recursive: true });
    await fs.writeFile(targetPath, archive);
    console.log(`Restored RemoteAuth WhatsApp session for ${this.tenantId}`);
  }

  // Laravel deletes the encrypted archive only after the sidecar logout succeeds.
  // Keeping this no-op avoids a failed HTTP callback leaving a detached local session.
  async delete() {}
}

function publicSession(session) {
  return {
    tenant_id: session.tenantId,
    status: session.status,
    qr: session.qr ?? null,
    phone: session.phone ?? null,
    message: session.message ?? null,
    reason: session.reason ?? null,
    delivery_error: session.deliveryError ?? null,
    updated_at: session.updatedAt,
  };
}

function inboundKey(message) {
  return message.id?._serialized || `${message.from}:${message.timestamp}:${message.body || ""}`;
}

function outboundKey(tenantId, recipient, body) {
  return `${tenantId}:${recipientId(recipient) || recipient}:${String(body || "").trim()}`;
}

function rememberOutboundMessage(tenantId, message) {
  if (!message.fromMe || !message.to || !message.body) return;
  const key = outboundKey(tenantId, message.to, message.body);
  const receipt = { messageId: message.id?._serialized ?? null, observedAt: Date.now() };
  recentOutboundMessages.set(key, receipt);
  setTimeout(() => recentOutboundMessages.delete(key), 60_000).unref();
}

async function waitForOutboundReceipt(tenantId, recipient, body, timeoutMs = 2_500) {
  const key = outboundKey(tenantId, recipient, body);
  const deadline = Date.now() + timeoutMs;
  do {
    const receipt = recentOutboundMessages.get(key);
    if (receipt) return receipt;
    await new Promise((resolve) => setTimeout(resolve, 125));
  } while (Date.now() < deadline);
  return null;
}

function digits(value) {
  const result = String(value || "").replace(/\D/g, "");
  return result || null;
}

async function contactIdentity(message) {
  const contact = await message.getContact().catch(() => null);
  const phone = digits(contact?.number) || (String(message.from || "").endsWith("@c.us") ? digits(message.from) : null);
  const name = [contact?.pushname, contact?.name, contact?.shortName]
    .map((value) => String(value || "").trim())
    .find(Boolean) || null;
  return { phone, name };
}

async function inboundImage(message) {
  if (!message.hasMedia) return null;

  try {
    const media = await message.downloadMedia();
    const mimeType = String(media?.mimetype || "").toLowerCase();
    if (!/^image\/(jpeg|png|webp|gif)$/.test(mimeType) || !media?.data) return null;

    const data = Buffer.from(media.data, "base64");
    // Keep webhook, PHP, and database usage bounded. The full original remains on WhatsApp.
    if (!data.length || data.length > 5 * 1024 * 1024) return null;

    return {
      type: "image",
      mime_type: mimeType,
      filename: String(media.filename || "foto-whatsapp"),
      data: media.data,
    };
  } catch (error) {
    console.warn("Unable to download inbound WhatsApp media", error);
    return null;
  }
}

async function deliverInboundMessage(tenantId, session, message) {
  if (message.fromMe || !message.from || message.from === "status@broadcast") return;

  const key = inboundKey(message);
  if (recentInboundMessages.has(key)) return;
  recentInboundMessages.set(key, Date.now());
  setTimeout(() => recentInboundMessages.delete(key), 60_000).unref();

  const [identity, media] = await Promise.all([contactIdentity(message), inboundImage(message)]);
  const payload = {
    external_id: message.from,
    phone: identity.phone,
    customer_name: identity.name,
    body: message.body || (media ? "[Foto]" : message.hasMedia ? "[Media]" : "[Pesan tanpa teks]"),
    media,
    message_id: message.id?._serialized,
    received_at: new Date().toISOString(),
  };
  let deliveryError;
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    try {
      await notify(tenantId, "message", payload);
      session.deliveryError = null;
      return;
    } catch (error) {
      deliveryError = error;
      if (attempt < 3) await new Promise((resolve) => setTimeout(resolve, attempt * 500));
    }
  }
  session.deliveryError = deliveryError instanceof Error ? deliveryError.message : String(deliveryError);
  session.updatedAt = new Date().toISOString();
  console.error(`Unable to deliver inbound WhatsApp message for ${tenantId}`, deliveryError);
}

async function createSession(tenantId) {
  const existing = sessions.get(tenantId);
  if (existing && !["error", "auth_failed", "disconnected"].includes(existing.status)) return existing;
  const pendingStart = sessionStarts.get(tenantId);
  if (pendingStart) return pendingStart;

  const start = async () => {
    if (existing) {
      await existing.client.destroy().catch(() => undefined);
      sessions.delete(tenantId);
    }

    const session = {
      tenantId,
      status: "initializing",
      qr: null,
      phone: null,
      message: null,
      reason: null,
      deliveryError: null,
      updatedAt: new Date().toISOString(),
    };
    const client = new Client({
      authStrategy: new RemoteAuth({
        clientId: `tenant-${tenantId}`,
        dataPath: remoteAuthPath,
        store: new CrmRemoteAuthStore(tenantId),
        backupSyncIntervalMs: 60_000,
      }),
      puppeteer: {
        headless: true,
        executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || "/usr/bin/chromium",
        args: ["--no-sandbox", "--disable-setuid-sandbox", "--disable-dev-shm-usage", "--disable-gpu"],
      },
    });
    session.client = client;
    sessions.set(tenantId, session);

    client.on("qr", async (rawQr) => {
      try {
        session.status = "awaiting_scan";
        session.message = null;
        session.reason = null;
        session.qr = await QRCode.toDataURL(rawQr, { margin: 1, width: 320 });
        session.updatedAt = new Date().toISOString();
        await notify(tenantId, "qr", { qr: session.qr }).catch(console.error);
      } catch (error) {
        session.status = "error";
        session.message = error instanceof Error ? error.message : String(error);
        session.updatedAt = new Date().toISOString();
        console.error("Unable to generate WhatsApp QR", error);
        await notify(tenantId, "error", { message: session.message }).catch(console.error);
      }
    });
    client.on("authenticated", async () => {
      session.status = "initializing";
      session.qr = null;
      session.message = "Perangkat tersambung. Sesi sedang diamankan agar tidak perlu scan ulang.";
      session.updatedAt = new Date().toISOString();
      await notify(tenantId, "syncing", { phone: session.phone, message: session.message }).catch(console.error);
    });
    client.on("ready", async () => {
      session.status = "connected";
      session.qr = null;
      session.message = null;
      session.reason = null;
      session.deliveryError = null;
      session.phone = client.info?.wid?.user ?? null;
      session.updatedAt = new Date().toISOString();
      await notify(tenantId, "ready", { phone: session.phone }).catch(console.error);
    });
    client.on("auth_failure", async (message) => {
      session.status = "auth_failed";
      session.message = String(message || "Autentikasi WhatsApp gagal.");
      session.updatedAt = new Date().toISOString();
      await notify(tenantId, "auth_failed", { message: session.message }).catch(console.error);
    });
    client.on("disconnected", async (reason) => {
      session.status = "disconnected";
      session.qr = null;
      session.reason = String(reason || "Koneksi perangkat WhatsApp terputus.");
      session.updatedAt = new Date().toISOString();
      sessions.delete(tenantId);
      await notify(tenantId, "disconnected", { reason: session.reason }).catch(console.error);
    });
    client.on("message", (message) => void deliverInboundMessage(tenantId, session, message));
    client.on("message_create", (message) => {
      if (message.fromMe) {
        rememberOutboundMessage(tenantId, message);
        return;
      }
      void deliverInboundMessage(tenantId, session, message);
    });
    client.initialize().catch(async (error) => {
      session.status = "error";
      session.message = error instanceof Error ? error.message : String(error);
      session.updatedAt = new Date().toISOString();
      console.error(`Unable to initialize WhatsApp session for ${tenantId}`, error);
      await notify(tenantId, "error", { message: session.message }).catch(console.error);
    });

    return session;
  };

  const pending = start();
  sessionStarts.set(tenantId, pending);
  try {
    return await pending;
  } finally {
    sessionStarts.delete(tenantId);
  }
}

app.post("/sessions/:tenantId", requireServiceSecret, async (req, res) => {
  try {
    const session = await createSession(req.params.tenantId);
    res.status(202).json(publicSession(session));
  } catch (error) {
    console.error(`Unable to restore WhatsApp session for ${req.params.tenantId}`, error);
    res.status(500).json({ message: "Sesi WhatsApp tidak dapat dipulihkan dari backup." });
  }
});

app.get("/sessions/:tenantId", requireServiceSecret, (req, res) => {
  const session = sessions.get(req.params.tenantId);
  if (!session) return res.status(404).json({ message: "Session tidak ditemukan." });
  res.json(publicSession(session));
});

app.post("/sessions/:tenantId/messages", requireServiceSecret, async (req, res) => {
  const session = sessions.get(req.params.tenantId);
  if (!session || session.status !== "connected") {
    return res.status(409).json({
      code: "whatsapp_session_unavailable",
      message: "Sesi WhatsApp belum terhubung.",
      session: session ? publicSession(session) : { tenant_id: req.params.tenantId, status: "disconnected", reason: "Sesi tidak ditemukan di layanan WhatsApp." },
    });
  }

  const recipient = recipientId(req.body.to);
  const body = String(req.body.body || "").trim();
  if (!recipient || !body) return res.status(422).json({ message: "Nomor tujuan dan pesan wajib diisi." });

  try {
    const message = await session.client.sendMessage(recipient, body);
    session.deliveryError = null;
    session.updatedAt = new Date().toISOString();
    return res.status(201).json({ message_id: message.id?._serialized, status: "sent" });
  } catch (error) {
    // WhatsApp Web can complete the send in Chromium but reject the JavaScript
    // response afterwards. message_create is the authoritative acknowledgement
    // from the connected device, so do not show a false failure to the operator.
    const receipt = await waitForOutboundReceipt(req.params.tenantId, recipient, body);
    if (receipt) {
      session.deliveryError = null;
      session.updatedAt = new Date().toISOString();
      return res.status(201).json({
        message_id: receipt.messageId,
        status: "sent",
        confirmed_by: "device_event",
      });
    }

    const detail = error instanceof Error ? error.message : String(error);
    const sessionLost = /not connected|session (closed|not available)|target closed|execution context was destroyed/i.test(detail);
    session.deliveryError = detail;
    session.updatedAt = new Date().toISOString();

    if (sessionLost) {
      session.status = "disconnected";
      session.reason = "Sesi WhatsApp terputus saat mengirim pesan.";
      session.qr = null;
      await notify(req.params.tenantId, "disconnected", { reason: session.reason }).catch(console.error);
    }

    console.error(`Unable to send WhatsApp message for ${req.params.tenantId}`, error);
    return res.status(503).json({
      code: sessionLost ? "whatsapp_session_unavailable" : "whatsapp_send_failed",
      message: sessionLost ? "Sesi WhatsApp terputus saat mengirim pesan." : "WhatsApp tidak dapat mengirim pesan ke nomor tujuan.",
      session: publicSession(session),
    });
  }
});

app.delete("/sessions/:tenantId", requireServiceSecret, async (req, res) => {
  const session = sessions.get(req.params.tenantId);
  if (session) {
    await session.client.logout().catch(() => undefined);
    await session.client.destroy().catch(() => undefined);
    sessions.delete(req.params.tenantId);
  }
  const sessionName = remoteSessionName(req.params.tenantId);
  await Promise.allSettled([
    fs.rm(path.join(remoteAuthPath, sessionName), { recursive: true, force: true }),
    fs.rm(path.join(remoteAuthPath, `${sessionName}.zip`), { force: true }),
  ]);
  res.status(204).end();
});

app.get("/health", (_, res) => res.json({ status: "ok", sessions: sessions.size, auth: "remote" }));

app.listen(port, async () => {
  await fs.mkdir(remoteAuthPath, { recursive: true });
  console.log(`CRM WhatsApp service listening on :${port} with RemoteAuth`);
});
