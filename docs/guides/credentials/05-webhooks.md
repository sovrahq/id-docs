# 5. Webhooks

Los webhooks son **el mecanismo principal** para conocer el resultado de una emisión
o de una verificación. Ambos flujos son asincrónicos: la llamada HTTP que iniciás
devuelve un QR, y el desenlace llega acá.

## Tabla de contenidos

1. [Configuración](#configuración)
2. [Estructura del payload](#estructura-del-payload)
3. [Cabeceras](#cabeceras)
4. [Validar la firma HMAC](#validar-la-firma-hmac)
5. [Catálogo de eventos](#catálogo-de-eventos)
6. [Entrega y reintentos](#entrega-y-reintentos)
7. [Buenas prácticas](#buenas-prácticas)
8. [Implementación de referencia](#implementación-de-referencia)

---

## Configuración

En el dashboard, **Settings → Workspaces → *(tu workspace)* → Webhooks**. Pide rol
de **administrador del workspace**, y se maneja sólo desde ahí: no hay endpoints de
API key para crear ni listar webhooks.

Un workspace tiene **hasta 10 endpoints**. Cada uno se configura con:

| Campo | Descripción |
|---|---|
| **URL** | El endpoint HTTPS de tu servidor. |
| **Eventos** | Qué eventos recibe. **Lista explícita, sin comodín** — ver abajo. |
| **Enabled** | Pausa las entregas sin borrar el endpoint ni rotar su secreto. |
| **Secreto** | El secreto HMAC con el que se firma cada entrega de **ese** endpoint. Se muestra **una sola vez**, al crearlo o rotarlo. |

> ⚠️ **La suscripción a eventos es explícita.** Un endpoint recibe únicamente los
> eventos que marcaste: si no está suscripto a uno, ese evento **no se le entrega**
> y no hay ningún error que lo delate. Es deliberado — un evento que se agregue al
> [catálogo](#catálogo-de-eventos) más adelante no llega a un receptor que no fue
> escrito para su payload. Cuando «no me llegan los webhooks», esto es lo primero
> que hay que mirar.

Sin ningún endpoint configurado —o con todos deshabilitados, o ninguno suscripto al
evento— **no se envía nada**: los eventos simplemente se descartan.

Cada endpoint tiene **su propio secreto** y reintenta por su cuenta: si uno falla,
los demás no se frenan. Podés rotar un secreto en cualquier momento desde la
fila del endpoint; la rotación es inmediata, las entregas siguientes se firman con
el nuevo y el anterior deja de validar al instante.

Si integrás varios workspaces contra el mismo endpoint, usá el `workspace_id` del
payload para elegir con qué secreto validar.

## Estructura del payload

Todos los eventos comparten el mismo sobre:

```json
{
  "event": "credential.issued",
  "id": "4f3379d8-0fab-4631-975c-c36a68528bd4",
  "timestamp": "2026-08-26T04:41:33.108371Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": { }
}
```

| Campo | Tipo | Descripción |
|---|---|---|
| `event` | string | Tipo de evento (ver el [catálogo](#catálogo-de-eventos)). |
| `id` | uuid | Identificador **del evento**. Es tu clave de idempotencia: lo comparten todos los reintentos de una entrega y todos los endpoints suscritos, así que un receptor que ya lo vio puede responder 200 sin volver a procesar. |
| `timestamp` | string | Momento en que se generó el evento (ISO 8601, UTC). |
| `workspace_id` | uuid | Workspace que originó el evento. |
| `data` | object | Contenido específico del evento. |

## Cabeceras

```http
POST /tu-endpoint HTTP/1.1
Content-Type: application/json
X-Sovra-Event: credential.issued
X-Sovra-Signature: sha256=9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08
```

| Header | Descripción |
|---|---|
| `X-Sovra-Event` | El tipo de evento, también presente en el cuerpo. Sirve para rutear sin parsear. |
| `X-Sovra-Signature` | `sha256=` + HMAC-SHA256 del **cuerpo crudo**, en hexadecimal minúscula. |

## Validar la firma HMAC

**Verificá la firma en cada entrega.** Sin eso, cualquiera que conozca tu URL puede
inventarte una emisión o una verificación aprobada.

El algoritmo:

1. Tomá el **cuerpo crudo** de la petición, byte por byte. **No lo parsees ni lo
   vuelvas a serializar**: `JSON.parse` + `JSON.stringify` reordena claves y cambia
   el espaciado, y la firma deja de coincidir.
2. Calculá `HMAC-SHA256(webhook_secret, cuerpo_crudo)`.
3. Codificalo en hexadecimal minúscula y anteponé `sha256=`.
4. Compará con `X-Sovra-Signature` usando una **comparación de tiempo constante**.

### Node.js / Express

```js
import crypto from "node:crypto";
import express from "express";

const app = express();
const SECRET = process.env.SOVRA_WEBHOOK_SECRET;

// express.raw() preserva el cuerpo intacto. express.json() NO sirve acá.
app.post(
  "/webhooks/sovra",
  express.raw({ type: "application/json" }),
  (req, res) => {
    const recibida = req.get("X-Sovra-Signature") ?? "";
    const esperada =
      "sha256=" + crypto.createHmac("sha256", SECRET).update(req.body).digest("hex");

    const a = Buffer.from(recibida);
    const b = Buffer.from(esperada);
    if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
      return res.sendStatus(401);
    }

    const payload = JSON.parse(req.body.toString("utf8"));
    manejarEvento(payload).catch(console.error); // procesá en segundo plano
    res.sendStatus(200);                          // respondé rápido
  },
);
```

### Python / FastAPI

```python
import hmac, hashlib, os
from fastapi import FastAPI, Request, HTTPException

app = FastAPI()
SECRET = os.environ["SOVRA_WEBHOOK_SECRET"].encode()

@app.post("/webhooks/sovra")
async def sovra_webhook(request: Request):
    crudo = await request.body()
    esperada = "sha256=" + hmac.new(SECRET, crudo, hashlib.sha256).hexdigest()
    recibida = request.headers.get("x-sovra-signature", "")

    if not hmac.compare_digest(esperada, recibida):
        raise HTTPException(status_code=401, detail="firma inválida")

    payload = await request.json()
    await manejar_evento(payload)
    return {"ok": True}
```

## Catálogo de eventos

| Evento | Cuándo se dispara |
|---|---|
| [`identity.did-generated`](#identitydid-generated) | El workspace terminó de aprovisionarse y ya tiene DID. |
| [`credential.issued`](#credentialissued) | El ciudadano escaneó la oferta y la credencial quedó en su wallet. |
| [`credential.revoked`](#credentialrevoked--credentialsuspended--credentialunsuspended) | Se revocó una credencial (permanente). |
| [`credential.suspended`](#credentialrevoked--credentialsuspended--credentialunsuspended) | Se suspendió una credencial (reversible). |
| [`credential.unsuspended`](#credentialrevoked--credentialsuspended--credentialunsuspended) | Se reactivó una credencial suspendida. |
| [`credential.status-change-failed`](#credentialstatus-change-failed) | Una revocación, suspensión o reactivación **no pudo registrarse on-chain**. |
| [`document.status-changed`](#documentstatus-changed) | Un documento quedó anclado on-chain, o el anclaje falló. |
| [`presentation.verified`](#presentationverified) | Una presentación pasó todas las validaciones. |
| [`presentation.failed`](#presentationfailed) | Una presentación falló en algún paso. |

### `identity.did-generated`

```json
{
  "event": "identity.did-generated",
  "id": "…",
  "timestamp": "2026-08-26T04:10:02.000000Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "did": "did:sovra:0xcda79e1ee5612c230a6faf68236e3679b0579bae",
    "provisioned_at": "2026-08-26T04:10:01Z"
  }
}
```

A partir de acá el workspace puede crear esquemas, ofertas y verificaciones.

### `credential.issued`

```json
{
  "event": "credential.issued",
  "id": "4f3379d8-0fab-4631-975c-c36a68528bd4",
  "timestamp": "2026-08-26T04:41:33.108371Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "credential_id": "b662aaad-d940-48fb-be9e-7194c76210ff",
    "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
    "schema_type": "VerifiableCredential",
    "format": "vc+sd-jwt",
    "issued_at": "2026-08-26T04:41:32Z",
    "expires_at": "2027-08-26T04:41:32Z",
    "credential": "eyJhbGciOiJFUzI1NiIsInR5cCI6InZjK3NkLWp3dCJ9…~"
  }
}
```

| Campo de `data` | Tipo | Descripción |
|---|---|---|
| `credential_id` | uuid | El mismo que devolvió `POST /credential-offer`. |
| `holder_did` | string | DID del ciudadano. |
| `schema_type` | string | El `credential_type` (`vct`) del esquema. |
| `format` | string | `vc+sd-jwt` o `mso_mdoc`, según el esquema. **Es el campo por el que conviene bifurcar.** |
| `doc_type` | string | **Sólo en `mso_mdoc`.** El docType ISO del mDoc; ver [emitir mDocs](../credentials-mdoc/03-emision-de-mdocs.md#paso-3--recibir-credentialissued). |
| `issued_at` / `expires_at` | string | ISO 8601. `expires_at` es `null` si el esquema no declara vencimiento. |
| `credential` | string | **El SD-JWT firmado.** |
| `attachment` | object | Sólo si el esquema declara un claim de tipo adjunto: `{ "type": "attachment", "media_type": "application/pdf", "bytes": 148231, "claim": "<key>" }`. |

> 🔴 **Única entrega.** El SD-JWT no se guarda del lado servidor ni se puede volver
> a pedir. Persistilo acá si tu sistema lo necesita.

> ℹ️ **Si el esquema tiene un claim de adjunto**, la copia que viaja en el webhook
> **omite esa disclosure** y en su lugar llega `attachment`, que la describe. El JWT
> y el resto de las disclosures no cambian, así que la credencial sigue siendo
> válida; la cartera sí recibe el adjunto completo. Es para no mandar un PDF de
> cientos de KB en cada entrega.

### `credential.revoked` / `credential.suspended` / `credential.unsuspended`

Los tres comparten forma:

```json
{
  "event": "credential.revoked",
  "id": "…",
  "timestamp": "2026-08-26T05:02:11.000000Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "credential_id": "b662aaad-d940-48fb-be9e-7194c76210ff",
    "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
    "schema_type": "VerifiableCredential",
    "status_at": "2026-08-26T05:02:11Z"
  }
}
```

Se disparan tanto si el cambio se hizo por API como desde el dashboard, así que sirven
para mantener sincronizados sistemas que no originaron la acción.

**Llegan cuando la transacción aterriza**, no cuando se pide el cambio: el
`PUT .../status/{status}` responde `202` y el estado se mueve después. Estos eventos
son la confirmación de que efectivamente se movió; si no aterriza, llega
[`credential.status-change-failed`](#credentialstatus-change-failed).

### `credential.status-change-failed`

El cambio de estado se escribe en la lista on-chain **en segundo plano**: el
`PUT .../status/{status}` responde `202` y el estado se mueve recién cuando la
transacción aterriza. Si nunca aterriza, llega este evento y la credencial **queda
como estaba**.

```json
{
  "event": "credential.status-change-failed",
  "id": "…",
  "timestamp": "2026-08-26T05:09:44.000000Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "credential_id": "b662aaad-d940-48fb-be9e-7194c76210ff",
    "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
    "schema_type": "VerifiableCredential",
    "requested_status": "revoked",
    "status": "issued",
    "status_at": "2026-08-26T05:09:44Z"
  }
}
```

| Campo de `data` | Descripción |
|---|---|
| `requested_status` | El estado que se había pedido (`revoked`, `suspended` o `issued`). |
| `status` | El estado que la credencial **sigue teniendo**. |

> 🔴 **Una revocación que falla deja la credencial vigente.** Si revocar es una
> obligación legal o de seguridad, suscribite a este evento y reintentá el `PUT`.
> Ver [6. Ciclo de vida](06-ciclo-de-vida-y-revocacion.md).

### `document.status-changed`

Un documento firmado vuelve del `POST /api/v1/issuer/documents` como `signed` y su
firma se ancla on-chain en segundo plano. Este evento anuncia el desenlace.

```json
{
  "event": "document.status-changed",
  "id": "…",
  "timestamp": "2026-08-21T14:12:43.000000Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "document_id": "63813a91-1f2e-4a3c-9d7b-0f5c2e8a4b11",
    "status": "anchored",
    "schema_type": "ActaDeNacimiento",
    "tx_hash": "0x9c93…",
    "anchored_at": "2026-08-21T14:12:43Z",
    "status_at": "2026-08-21T14:12:43Z"
  }
}
```

| Campo de `data` | Descripción |
|---|---|
| `status` | `anchored` (el único estado entregable) o `failed`. |
| `tx_hash` | La transacción del anclaje. `null` si falló. |
| `anchored_at` | Cuándo quedó anclado. `null` si falló. |

> ⚠️ **Un `failed` significa que el documento no sirve**: un verificador rechaza un
> documento sin anclar, así que hay que volver a emitirlo. Ver
> [emitir documentos](../documents/03-emision-de-documentos.md).

### `presentation.verified`

```json
{
  "event": "presentation.verified",
  "id": "1157c346-dfc9-4e5d-9dd0-5c28d7f24ce9",
  "timestamp": "2026-08-26T04:47:31.165773Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "verification_id": "e2d16450-42e8-46a9-ae92-d72af51a7435",
    "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
    "success": true,
    "completed_at": "2026-08-26T04:47:31Z",
    "credentials": [
      {
        "id": "training_v2",
        "format": "vc+sd-jwt",
        "claims": { "email": "quinterosm.daniel@gmail.com" }
      }
    ]
  }
}
```

`verification_id` es el `session_id` que devolvió `POST /verifications`.
`credentials[].id` es la etiqueta que elegiste en el DCQL.

### `presentation.failed`

```json
{
  "event": "presentation.failed",
  "id": "e678e20a-be4c-459a-b0a7-4b04783cb8d3",
  "timestamp": "2026-08-26T04:45:07.584992Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "verification_id": "183ee078-69b7-4fa5-9a6f-b40ddbb59ce5",
    "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
    "success": false,
    "error": "missing_required_claim:first_name",
    "completed_at": "2026-08-26T04:45:07Z"
  }
}
```

`error` identifica el paso exacto que falló → [tabla completa de errores](08-errores-y-troubleshooting.md#errores-de-presentación).
`holder_did` puede ser `null` si la falla ocurrió antes de identificar al holder.

## Entrega y reintentos

| Respuesta de tu servidor | Qué hace Sovra |
|---|---|
| **2xx** | Entrega exitosa. Fin. |
| **4xx** | **Se descarta sin reintentar.** Un 4xx se interpreta como error del consumidor: reintentar no lo va a arreglar. |
| **5xx**, timeout o error de transporte | **Se reintenta** con backoff exponencial, hasta **8 intentos**. |

**Timeout: 5 segundos.** Si tu endpoint tarda más, la entrega cuenta como fallida y
entra en la cola de reintentos.

El backoff crece rápido: los 8 intentos se agotan en **~1,5 horas** en total. Pasado
eso la entrega se descarta y el evento no vuelve. Si tu receptor puede estar caído
más tiempo que eso, no alcanza con el webhook: reconciliá consultando la API.

⚠️ La consecuencia práctica del 4xx: si tu validación de firma devuelve `401` porque
el secreto está mal configurado, **perdés el evento para siempre**. Durante la puesta
en marcha, registrá los payloads que rechaces por firma inválida.

## Buenas prácticas

**Respondé rápido, procesá después.** Devolvé `200` apenas validás la firma y hacé el
trabajo pesado en segundo plano. El presupuesto son 5 segundos.

**Sé idempotente.** Los reintentos pueden entregar el mismo evento más de una vez.
Guardá el `id` de cada entrega procesada y descartá repetidos.

**Correlacioná por el identificador de negocio.** `credential_id` para emisiones,
`verification_id` para verificaciones. Guardalos cuando creás la oferta o la sesión,
antes de mostrar el QR.

**No confíes en el orden.** Los eventos se entregan en paralelo y con reintentos; un
`credential.revoked` puede llegar antes que un `credential.issued` demorado. Usá
`timestamp` para resolver conflictos.

**Validá siempre la firma.** También en los entornos de test.

**Registrá todo.** Guardar el payload crudo, la firma y tu veredicto ahorra horas de
depuración.

**Tené un plan B.** Si tu endpoint estuvo caído más allá de los 8 reintentos,
reconciliá con `GET /api/v1/issuer/credentials` y
`GET /api/v1/verifier/verifications`.

## Implementación de referencia

```js
import crypto from "node:crypto";
import express from "express";

const app = express();
const SECRET = process.env.SOVRA_WEBHOOK_SECRET;
const procesados = new Set(); // en producción: Redis o una tabla

function firmaValida(crudo, recibida) {
  const esperada =
    "sha256=" + crypto.createHmac("sha256", SECRET).update(crudo).digest("hex");
  const a = Buffer.from(recibida ?? "");
  const b = Buffer.from(esperada);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

app.post(
  "/webhooks/sovra",
  express.raw({ type: "application/json" }),
  async (req, res) => {
    if (!firmaValida(req.body, req.get("X-Sovra-Signature"))) {
      console.warn("Webhook con firma inválida", req.body.toString("utf8"));
      return res.sendStatus(401);
    }

    const evento = JSON.parse(req.body.toString("utf8"));

    // Idempotencia: los reintentos repiten el mismo `id`.
    if (procesados.has(evento.id)) return res.sendStatus(200);
    procesados.add(evento.id);

    res.sendStatus(200);           // primero acusar recibo…
    manejar(evento).catch(console.error); // …después trabajar
  },
);

async function manejar({ event, data }) {
  switch (event) {
    case "credential.issued":
      await db.tramites.updateBySovraId(data.credential_id, {
        estado: "emitida",
        holder_did: data.holder_did,
        sd_jwt: data.credential,
      });
      break;

    case "credential.revoked":
    case "credential.suspended":
    case "credential.unsuspended":
      await db.tramites.updateBySovraId(data.credential_id, {
        estado: event.split(".")[1],
      });
      break;

    case "presentation.verified":
      await db.verificaciones.updateBySovraId(data.verification_id, {
        estado: "aprobada",
        datos: data.credentials[0]?.claims ?? {},
      });
      break;

    case "presentation.failed":
      await db.verificaciones.updateBySovraId(data.verification_id, {
        estado: "rechazada",
        motivo: data.error,
      });
      break;

    case "identity.did-generated":
      await db.workspaces.marcarListo(data.did);
      break;

    default:
      console.info("Evento no manejado:", event);
  }
}
```

---

**Anterior:** [← 4. Verificación de credenciales](04-verificacion-de-credenciales.md) · **Siguiente:** [6. Ciclo de vida y revocación →](06-ciclo-de-vida-y-revocacion.md)
