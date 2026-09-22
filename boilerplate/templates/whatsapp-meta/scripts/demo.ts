/**
 * Simula la conversación completa sin tocar Meta ni Sovra.
 *
 *   npm run demo
 *
 * Reemplaza `fetch` por un stub, así podés ver el flujo entero —y romperlo a
 * propósito— antes de tener número de WhatsApp o API key.
 */

export {};

const outbox: any[] = [];
const SESSION_ID = "e2d16450-42e8-46a9-ae92-d72af51a7435";

/** El wa_id con el que "escribe" el ciudadano en el demo. */
const PHONE = "5491122223333";

globalThis.fetch = (async (url: any, init: any) => {
  const target = String(url);
  const body = init?.body ? JSON.parse(init.body) : {};

  if (target.includes("graph.facebook.com")) {
    outbox.push(body);
    return new Response(JSON.stringify({ messages: [{ id: "wamid.OUT" }] }), {
      status: 200,
    });
  }

  if (target.includes("/verifier/verifications")) {
    outbox.push({ _dcql: body.dcql_query });
    return new Response(
      JSON.stringify({
        session_id: SESSION_ID,
        authorization_request_uri:
          "openid4vp://?client_id=verifier%3Aabc&response_type=vp_token&…",
        authorization_request_uri_ref: "openid4vp://?request_uri=https%3A%2F%2F…",
        status: "pending",
        expires_at: new Date(Date.now() + 10 * 60_000).toISOString(),
      }),
      { status: 201 },
    );
  }

  throw new Error(`fetch inesperado: ${target}`);
}) as any;

const flow = await import("../src/flow.js");
const store = await import("../src/store.js");

const BOLD = "\x1b[1m";
const DIM = "\x1b[90m";
const OFF = "\x1b[0m";

let counter = 0;

function render() {
  for (const message of outbox) {
    if (message._dcql) {
      const paths = message._dcql.credentials[0].claims.map((c: any) => c.path[0]);
      console.log(`  \x1b[36m→ Sovra${OFF}  DCQL pide: ${paths.join(", ")}`);
    } else if (message.type === "text") {
      const body = message.text.body.replace(/\n/g, `\n${" ".repeat(11)}`);
      console.log(`  \x1b[32m← bot${OFF}    ${body}`);
    } else if (message.type === "image") {
      console.log(`  \x1b[32m← bot${OFF}    [imagen] ${message.image.link}`);
    }
  }
  outbox.length = 0;
  console.log(`  ${DIM}estado: ${store.getConversation(PHONE).step}${OFF}`);
}

/** Un mensaje del ciudadano, con su respuesta. */
async function turn(title: string, text: string) {
  console.log(`\n${BOLD}${title}${OFF}`);
  console.log(`  \x1b[33m→ vos${OFF}    ${text}`);
  await flow.handleMessage({
    from: PHONE,
    id: `wamid.DEMO${++counter}`,
    timestamp: "1",
    type: "text",
    text: { body: text },
  } as any);
  render();
}

/** Un evento de Sovra, sin mensaje previo del ciudadano. */
async function event(title: string, run: () => Promise<void>) {
  console.log(`\n${BOLD}${title}${OFF}`);
  await run();
  render();
}

/** Deja la conversación esperando la presentación, sin imprimir nada. */
async function fastForward() {
  await flow.handleMessage({
    from: PHONE,
    id: `wamid.FF${++counter}`,
    timestamp: "1",
    type: "text",
    text: { body: "hola" },
  } as any);
  outbox.length = 0;
}

const verified = (claims: Record<string, string>) => () =>
  flow.handleVerified({
    verification_id: SESSION_ID,
    holder_did: "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
    success: true,
    completed_at: new Date().toISOString(),
    credentials: [{ id: "identidad", format: "vc+sd-jwt", claims }],
  } as any);

/* ─── El camino completo: dos mensajes y listo ────────────────────────────── */

await turn("1 · Primer contacto → deep link, sin preguntar nada", "Quiero hacer el trámite para la licencia");
await turn("2 · Insiste mientras esperamos la presentación", "¿ya está?");
await event(
  "3 · webhook presentation.verified → sesión iniciada",
  // El teléfono viene con '+' y espacios; el wa_id no. samePhone los concilia.
  verified({
    cuil: "27339186605",
    nombre: "Gustavo",
    apellido: "Giorgetti",
    phone: "+54 9 11 2222-3333",
  }),
);

/* ─── Alguien reenvió el link y lo presentó otra persona ──────────────────── */

await fastForward();
await event(
  "4 · Credencial válida, pero de otro teléfono",
  verified({
    cuil: "20123456786",
    nombre: "Otra",
    apellido: "Persona",
    phone: "+54 9 11 9999-0000",
  }),
);

/* ─── Presentación fallida ────────────────────────────────────────────────── */

await fastForward();
await event("5 · webhook presentation.failed", () =>
  flow.handleFailed({
    verification_id: SESSION_ID,
    holder_did: "did:sovra:0x90",
    success: false,
    error: "credential_revoked",
    completed_at: new Date().toISOString(),
  } as any),
);

/* ─── Cancelar ────────────────────────────────────────────────────────────── */

await fastForward();
await turn("6 · El ciudadano se arrepiente", "cancelar");
