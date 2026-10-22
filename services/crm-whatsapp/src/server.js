import crypto from "node:crypto";
import fs from "node:fs/promises";
import express from "express";
import QRCode from "qrcode";
import whatsappWeb from "whatsapp-web.js";

const { Client, LocalAuth } = whatsappWeb;

const app = express();
app.use(express.json({ limit: "1mb" }));

const port = Number(process.env.PORT || 3100);
const webhookUrl = process.env.CRM_WHATSAPP_WEBHOOK_URL;
const webhookSecret = process.env.CRM_WHATSAPP_WEBHOOK_SECRET;
const sessions = new Map();
const recentInboundMessages = new Map();

function authPathFor(tenantId) {
  const basePath = process.env.WHATSAPP_AUTH_PATH || "/data/auth";
  return `${basePath}/session-tenant-${tenantId}`;
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
    headers: {
      "content-type": "application/json",
      "x-crm-service-secret": webhookSecret,
    },
    body: JSON.stringify({ tenant_id: tenantId, event, ...payload }),
  });
  if (!response.ok) {
    const body = await response.text();
    throw new Error(`CRM webhook returned ${response.status}${body ? `: ${body.slice(0, 300)}` : ""}`);
  }
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

async function deliverInboundMessage(tenantId, session, message) {
  if (message.fromMe || !message.from || message.from === "status@broadcast") return;

  const key = inboundKey(message);
  if (recentInboundMessages.has(key)) return;
  recentInboundMessages.set(key, Date.now());
  setTimeout(() => recentInboundMessages.delete(key), 60_000).unref();

  const payload = {
    external_id: message.from,
    body: message.body || (message.hasMedia ? "[Media]" : "[Pesan tanpa teks]"),
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

function createSession(tenantId) {
  const existing = sessions.get(tenantId);
  if (existing && !["error", "auth_failed", "disconnected"].includes(existing.status)) {
    return existing;
  }
  if (existing) {
    existing.client.destroy().catch(() => undefined);
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
    authStrategy: new LocalAuth({ clientId: `tenant-${tenantId}`, dataPath: process.env.WHATSAPP_AUTH_PATH || "/data/auth" }),
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
  // Some WhatsApp clients emit message_create before message. The in-memory key
  // keeps the fallback from creating a duplicate conversation message.
  client.on("message_create", (message) => void deliverInboundMessage(tenantId, session, message));
  client.initialize().catch(async (error) => {
    session.status = "error";
    session.message = error instanceof Error ? error.message : String(error);
    session.updatedAt = new Date().toISOString();
    console.error(`Unable to initialize WhatsApp session for ${tenantId}`, error);
    await notify(tenantId, "error", { message: session.message }).catch(console.error);
  });

  return session;
}

app.post("/sessions/:tenantId", requireServiceSecret, (req, res) => {
  const session = createSession(req.params.tenantId);
  res.status(202).json(publicSession(session));
});

app.get("/sessions/:tenantId", requireServiceSecret, (req, res) => {
  const session = sessions.get(req.params.tenantId);
  if (!session) return res.status(404).json({ message: "Session tidak ditemukan." });
  res.json(publicSession(session));
});

app.post("/sessions/:tenantId/messages", requireServiceSecret, async (req, res) => {
  const session = sessions.get(req.params.tenantId);
  if (!session || session.status !== "connected") return res.status(409).json({ message: "WhatsApp belum terhubung." });
  const to = String(req.body.to || "").replace(/\D/g, "");
  const body = String(req.body.body || "").trim();
  if (!to || !body) return res.status(422).json({ message: "Nomor tujuan dan pesan wajib diisi." });
  const message = await session.client.sendMessage(`${to}@c.us`, body);
  res.status(201).json({ message_id: message.id?._serialized, status: "sent" });
});

app.delete("/sessions/:tenantId", requireServiceSecret, async (req, res) => {
  const session = sessions.get(req.params.tenantId);
  if (session) {
    await session.client.logout().catch(() => undefined);
    await session.client.destroy().catch(() => undefined);
    sessions.delete(req.params.tenantId);
  }
  await fs.rm(authPathFor(req.params.tenantId), { recursive: true, force: true });
  res.status(204).end();
});

app.get("/health", (_, res) => res.json({ status: "ok", sessions: sessions.size }));

async function restoreSessions() {
  const authPath = process.env.WHATSAPP_AUTH_PATH || "/data/auth";
  const entries = await fs.readdir(authPath, { withFileTypes: true }).catch(() => []);
  for (const entry of entries) {
    if (!entry.isDirectory() || !entry.name.startsWith("session-tenant-")) continue;
    const tenantId = entry.name.replace("session-tenant-", "");
    createSession(tenantId);
  }
}

app.listen(port, async () => {
  console.log(`CRM WhatsApp service listening on :${port}`);
  await restoreSessions().catch(console.error);
});
