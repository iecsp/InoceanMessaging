/**
 * Optional Nuxt server route: server/routes/inocean/messaging/unsubscribe/[token].ts
 * Private runtimeConfig: smsUnsubscribeUpstream: ""
 * Local server environment: NUXT_SMS_UNSUBSCRIBE_UPSTREAM=http://127.0.0.1:81
 * This is a reviewable integration example; it has not been installed in the POS frontend.
 */
import {
  createError,
  defineEventHandler,
  getMethod,
  getRouterParam,
  readBody,
  setResponseHeader,
  setResponseStatus,
} from "h3";

export default defineEventHandler(async (event) => {
  const method = getMethod(event);
  if (method !== "GET" && method !== "POST") {
    throw createError({ statusCode: 405, statusMessage: "Method not allowed" });
  }
  const token = getRouterParam(event, "token") ?? "";
  if (token.length > 2048 || !/^[A-Za-z0-9_-]+$/.test(token)) {
    throw createError({ statusCode: 400, statusMessage: "Invalid unsubscribe link" });
  }
  // Server-only config. Never derive the upstream from request headers or query parameters.
  const base = String(useRuntimeConfig(event).smsUnsubscribeUpstream ?? "");
  let upstream: URL;
  try {
    upstream = new URL(base);
    if (!new Set(["http:", "https:"]).has(upstream.protocol) || upstream.username || upstream.password || upstream.search || upstream.hash) throw new Error();
  } catch {
    throw createError({ statusCode: 503, statusMessage: "SMS unsubscribe service is not configured" });
  }
  const headers: Record<string, string> = { Accept: "text/html" };
  let body: URLSearchParams | undefined;
  if (method === "POST") {
    const form = await readBody<Record<string, unknown>>(event);
    if (form?.confirm !== "yes") {
      throw createError({ statusCode: 400, statusMessage: "Confirmation required" });
    }
    headers["Content-Type"] = "application/x-www-form-urlencoded";
    body = new URLSearchParams({ confirm: "yes" });
  }
  // Do not forward frontend cookies, OAuth credentials, or unrelated customer data.
  const response = await fetch(`${base.replace(/\/$/, "")}/inocean/messaging/unsubscribe/${token}`, {
    method,
    headers,
    body,
    redirect: "error",
    signal: AbortSignal.timeout(10000),
  });
  if (!response.headers.get("content-type")?.startsWith("text/html")) {
    throw createError({ statusCode: 502, statusMessage: "Invalid unsubscribe service response" });
  }
  setResponseStatus(event, response.status);
  for (const header of ["content-type", "content-security-policy", "referrer-policy", "x-frame-options", "x-robots-tag"]) {
    const value = response.headers.get(header);
    if (value) setResponseHeader(event, header, value);
  }
  setResponseHeader(event, "Cache-Control", "no-store, private");
  return response.text();
});
