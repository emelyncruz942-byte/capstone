import { createClient } from "npm:@supabase/supabase-js@2";
import webpush from "npm:web-push@3.6.7";

type PushPayload = {
  title?: string;
  body?: string;
  url?: string;
  tag?: string;
  user_ids?: string[];
};

const MAX_REQUEST_BYTES = 32 * 1024;
const MAX_CLOCK_SKEW_SECONDS = 300;
const DEFAULT_RATE_LIMIT = 60;
const RATE_WINDOW_SECONDS = 60;
const SIGNATURE_SCOPE = "send-admin-push";
// A fixed protocol identifier avoids gateway path rewriting differences.
const SIGNATURE_PATH = "/send-admin-push";

Deno.serve(async (request: Request) => {
  if (request.method !== "POST") {
    return json({ message: "Method not allowed." }, 405);
  }

  const supabaseUrl = Deno.env.get("SUPABASE_URL") ?? "";
  const serviceKey = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY") ?? "";
  const vapidPublicKey = Deno.env.get("VAPID_PUBLIC_KEY") ?? "";
  const vapidPrivateKey = Deno.env.get("VAPID_PRIVATE_KEY") ?? "";
  const vapidSubject = Deno.env.get("VAPID_SUBJECT") ?? "";
  const adminPushSecret = Deno.env.get("ADMIN_PUSH_SECRET") ?? "";
  const allowedPushHosts = (Deno.env.get("WEB_PUSH_ALLOWED_HOSTS") ??
    "fcm.googleapis.com,updates.push.services.mozilla.com,push.services.mozilla.com,web.push.apple.com,*.notify.windows.com")
    .split(",")
    .map((host) => host.trim().toLowerCase())
    .filter(Boolean);

  if (
    !supabaseUrl ||
    !serviceKey ||
    !vapidPublicKey ||
    !vapidPrivateKey ||
    !vapidSubject ||
    adminPushSecret.length < 32
  ) {
    return json({ message: "Web Push secrets are incomplete." }, 503);
  }
  const declaredLength = Number(request.headers.get("content-length") ?? 0);
  if (Number.isFinite(declaredLength) && declaredLength > MAX_REQUEST_BYTES) {
    return json({ message: "Request payload is too large." }, 413);
  }

  let rawBody = "";
  try {
    const body = await readLimitedText(request.body, MAX_REQUEST_BYTES);
    if (body === null) {
      return json({ message: "Request payload is too large." }, 413);
    }
    rawBody = body;
  } catch (_error) {
    return json({ message: "Request body is not valid UTF-8." }, 400);
  }

  const timestampHeader = request.headers.get("x-mathverse-timestamp") ?? "";
  const nonce = request.headers.get("x-mathverse-nonce") ?? "";
  const providedSignature = (
    request.headers.get("x-mathverse-signature") ?? ""
  ).toLowerCase();
  const timestamp = /^[0-9]{10}$/.test(timestampHeader)
    ? Number(timestampHeader)
    : Number.NaN;
  if (
    !Number.isSafeInteger(timestamp) ||
    Math.abs(Math.floor(Date.now() / 1000) - timestamp) >
      MAX_CLOCK_SKEW_SECONDS ||
    !/^[A-Za-z0-9_-]{32,128}$/.test(nonce) ||
    !/^[a-f0-9]{64}$/.test(providedSignature)
  ) {
    return json({ message: "Unauthorized." }, 401);
  }

  const expectedSignature = await hmacSignature(
    rawBody,
    adminPushSecret,
    SIGNATURE_SCOPE,
    request.method.toUpperCase(),
    SIGNATURE_PATH,
    timestamp,
    nonce,
  );
  if (!safeEqual(providedSignature, expectedSignature)) {
    return json({ message: "Unauthorized." }, 401);
  }

  const supabase = createClient(supabaseUrl, serviceKey, {
    auth: { persistSession: false, autoRefreshToken: false },
  });
  const configuredLimit = Number(Deno.env.get("ADMIN_PUSH_RATE_LIMIT") ?? "");
  const rateLimit = Number.isSafeInteger(configuredLimit) &&
      configuredLimit >= 1 && configuredLimit <= 1000
    ? configuredLimit
    : DEFAULT_RATE_LIMIT;
  const { data: claimed, error: claimError } = await supabase.rpc(
    "claim_machine_request",
    {
      p_scope: SIGNATURE_SCOPE,
      p_nonce: nonce,
      p_timestamp: new Date(timestamp * 1000).toISOString(),
      p_limit: rateLimit,
      p_window_seconds: RATE_WINDOW_SECONDS,
    },
  );
  if (claimError) {
    return json({ message: "Request verification is temporarily unavailable." }, 503);
  }
  if (claimed !== true) {
    return json({ message: "Request was already used or the rate limit was reached." }, 429);
  }

  let payload: PushPayload;
  try {
    const decoded = JSON.parse(rawBody);
    if (!decoded || typeof decoded !== "object" || Array.isArray(decoded)) {
      return json({ message: "The JSON payload must be an object." }, 422);
    }
    payload = decoded as PushPayload;
  } catch (_error) {
    return json({ message: "Invalid JSON payload." }, 422);
  }

  const targetUserIds = normalizeUserIds(payload.user_ids);
  if (payload.user_ids !== undefined && targetUserIds === null) {
    return json({ message: "user_ids must contain 1 to 100 UUIDs." }, 422);
  }

  const notification = JSON.stringify({
    title: String(payload.title ?? "MathVerse Notification").slice(0, 100),
    body: String(payload.body ?? "A new item needs your attention.").slice(
      0,
      240,
    ),
    url: safeAppPath(payload.url),
    tag: String(payload.tag ?? "mathverse-notification").slice(0, 100),
  });

  let subscriptionQuery = supabase
    .from("push_subscriptions")
    .select("id,user_id,endpoint,p256dh,auth,profiles!inner(role)");

  // No recipient list intentionally retains the original all-admin behavior
  // used by teacher-registration and quiz-report alerts.
  subscriptionQuery = targetUserIds === null
    ? subscriptionQuery.eq("profiles.role", "admin")
    : subscriptionQuery.in("user_id", targetUserIds);

  const { data: subscriptions, error } = await subscriptionQuery;

  if (error) {
    console.error("MathVerse push subscription lookup failed", {
      code: error.code,
    });
    return json({ message: "Push subscriptions could not be loaded." }, 500);
  }

  webpush.setVapidDetails(vapidSubject, vapidPublicKey, vapidPrivateKey);

  let sent = 0;
  let failed = 0;
  let expired = 0;
  for (const subscription of subscriptions ?? []) {
    if (!isAllowedPushEndpoint(subscription.endpoint, allowedPushHosts)) {
      await supabase
        .from("push_subscriptions")
        .delete()
        .eq("id", subscription.id);
      expired++;
      continue;
    }

    try {
      await webpush.sendNotification(
        {
          endpoint: subscription.endpoint,
          keys: {
            p256dh: subscription.p256dh,
            auth: subscription.auth,
          },
        },
        notification,
        { TTL: 3600 },
      );
      sent++;
    } catch (pushError) {
      const statusCode = Number(
        (pushError as { statusCode?: number })?.statusCode ?? 0,
      );
      if (statusCode === 404 || statusCode === 410) {
        await supabase
          .from("push_subscriptions")
          .delete()
          .eq("id", subscription.id);
        expired++;
      } else {
        failed++;
        console.error("MathVerse browser push rejected", {
          subscriptionId: subscription.id,
          statusCode,
          errorType: pushError instanceof Error
            ? pushError.constructor.name
            : "UnknownPushError",
        });
      }
    }
  }

  return json({ sent, failed, expired, total: subscriptions?.length ?? 0 });
});

async function readLimitedText(
  stream: ReadableStream<Uint8Array> | null,
  maximumBytes: number,
): Promise<string | null> {
  if (stream === null) {
    return "";
  }

  const reader = stream.getReader();
  const chunks: Uint8Array[] = [];
  let totalBytes = 0;

  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) {
        break;
      }

      totalBytes += value.byteLength;
      if (totalBytes > maximumBytes) {
        await reader.cancel("Request payload is too large.");
        return null;
      }
      chunks.push(value);
    }
  } finally {
    reader.releaseLock();
  }

  const bytes = new Uint8Array(totalBytes);
  let offset = 0;
  for (const chunk of chunks) {
    bytes.set(chunk, offset);
    offset += chunk.byteLength;
  }

  return new TextDecoder("utf-8", { fatal: true }).decode(bytes);
}

function safeEqual(left: string, right: string): boolean {
  const leftBytes = new TextEncoder().encode(left);
  const rightBytes = new TextEncoder().encode(right);
  if (leftBytes.length !== rightBytes.length) return false;

  let difference = 0;
  for (let index = 0; index < leftBytes.length; index++) {
    difference |= leftBytes[index] ^ rightBytes[index];
  }
  return difference === 0;
}

async function hmacSignature(
  body: string,
  secret: string,
  scope: string,
  method: string,
  path: string,
  timestamp: number,
  nonce: string,
): Promise<string> {
  const encoder = new TextEncoder();
  const bodyDigest = await crypto.subtle.digest("SHA-256", encoder.encode(body));
  const canonical = `v2:${scope}:${method}:${path}:${timestamp}:${nonce}:${toHex(bodyDigest)}`;
  const key = await crypto.subtle.importKey(
    "raw",
    encoder.encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"],
  );
  return toHex(await crypto.subtle.sign("HMAC", key, encoder.encode(canonical)));
}

function toHex(value: ArrayBuffer): string {
  return [...new Uint8Array(value)]
    .map((byte) => byte.toString(16).padStart(2, "0"))
    .join("");
}

function normalizeUserIds(value: unknown): string[] | null {
  if (value === undefined) return null;
  if (!Array.isArray(value) || value.length < 1 || value.length > 100) {
    return null;
  }

  const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
  const ids = [...new Set(value.map((item) => String(item)))];

  return ids.every((item) => uuid.test(item)) ? ids : null;
}

function isAllowedPushEndpoint(value: unknown, allowedHosts: string[]): boolean {
  try {
    const endpoint = new URL(String(value ?? ""));
    if (
      endpoint.protocol !== "https:" ||
      endpoint.username !== "" ||
      endpoint.password !== "" ||
      (endpoint.port !== "" && endpoint.port !== "443") ||
      endpoint.pathname === "/" ||
      endpoint.hash !== ""
    ) {
      return false;
    }

    const host = endpoint.hostname.toLowerCase().replace(/\.$/, "");
    return allowedHosts.some((allowed) =>
      host === allowed ||
      (allowed.startsWith("*.") &&
        host !== allowed.slice(2) &&
        host.endsWith(allowed.slice(1)))
    );
  } catch (_error) {
    return false;
  }
}

function safeAppPath(value: unknown): string {
  const path = String(value ?? "/").trim();
  if (!path.startsWith("/") || path.startsWith("//") || path.length > 2048) {
    return "/";
  }

  let decoded = path;
  try {
    for (let pass = 0; pass < 3; pass++) {
      const next = decodeURIComponent(decoded);
      if (next === decoded) break;
      decoded = next;
    }
  } catch (_error) {
    return "/";
  }

  if (decoded.startsWith("//") || decoded.includes("\\") || /[\u0000-\u001f\u007f]/.test(decoded)) {
    return "/";
  }

  try {
    const base = new URL("https://mathverse.invalid/");
    const candidate = new URL(path, base);
    return candidate.origin === base.origin ? path : "/";
  } catch (_error) {
    return "/";
  }
}

function json(payload: Record<string, unknown>, status = 200): Response {
  return new Response(JSON.stringify(payload), {
    status,
    headers: {
      "cache-control": "no-store",
      "content-type": "application/json; charset=utf-8",
      "x-content-type-options": "nosniff",
    },
  });
}
