# 5. Webhooks

Los webhooks son **el mecanismo principal** para conocer el desenlace de todo lo que
es asincrónico: un documento que se ancla, una revocación que llega a la cadena, un
holder que canjea una oferta, una wallet que presenta una credencial. La llamada HTTP
que iniciás devuelve un QR o un acuse; el resultado llega acá.

La alternativa es consultar el `GET` correspondiente, pero es una alternativa: el
webhook es el único aviso que Sovra empuja.

## Tabla de contenidos

1. [Endpoints y suscripciones](#endpoints-y-suscripciones)
2. [Estructura del payload](#estructura-del-payload)
3. [Cabeceras](#cabeceras)
4. [Validar la firma HMAC](#validar-la-firma-hmac)
5. [Entrega y reintentos](#entrega-y-reintentos)
6. [Orden y consistencia](#orden-y-consistencia)
7. [Catálogo de eventos](#catálogo-de-eventos)
8. [Buenas prácticas](#buenas-prácticas)
9. [Implementación de referencia](#implementación-de-referencia)

---

## Endpoints y suscripciones

Los endpoints se administran en el dashboard, en
**Settings → Workspaces → *(tu workspace)* → Webhooks**. Pide rol de
**administrador del workspace**, y se maneja sólo desde ahí: **no hay endpoints de
API key** para crear ni listar webhooks.

| | |
|---|---|
| **Cuántos** | Hasta **10 endpoints** por workspace. |
| **Qué tiene cada uno** | Su URL, su propia lista de eventos suscritos, un flag `enabled`, y **su propio secreto de firma**. |
| **La URL** | Tiene que cumplir `^https?://` y medir como máximo 2048 caracteres. |
| **El secreto** | `whsec_…`. Se muestra **una sola vez**: al crear el endpoint, y otra vez al rotarlo. |

**La suscripción es una lista explícita y no vacía** de tipos de evento. **No hay
comodín**: un tipo de evento que se agregue al catálogo más adelante le llega a un
endpoint recién cuando alguien lo suscribe.

Rotar el secreto lo reemplaza de inmediato, **incluso para las entregas que están
esperando un reintento**.

Si integrás varios workspaces contra el mismo endpoint, usá el `workspace_id` del
payload para elegir con qué secreto validar.

> ⚠️ **Lo que todavía no hay:** acción de "enviar un evento de prueba", historial de
> entregas, y reenvío manual. Si perdés un evento, la única forma de recuperar el
> estado es consultar la API.

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
| `id` | uuid | Único por evento. **El mismo en todos los endpoints y en todos los reintentos de ese evento**, así que es el valor sobre el que deduplicar. |
| `timestamp` | string | Cuándo se emitió el evento (ISO 8601, UTC, con microsegundos). **No cambia entre reintentos.** |
| `workspace_id` | uuid | El workspace al que pertenece el evento. |
| `data` | object | El contenido específico del evento. |

Las fechas dentro de `data` son ISO 8601 UTC. La mayoría va truncada al segundo
(`2026-10-14T14:21:07Z`); **`status_at` lleva microsegundos**
(`2026-10-14T14:21:07.318402Z`).

**El orden de las claves del cuerpo crudo no es parte del contrato.** Los ejemplos de
esta guía ponen `event` primero porque se lee mejor.

## Cabeceras

```http
POST /tu-endpoint HTTP/1.1
content-type: application/json
x-sovra-event: document.status-changed
x-sovra-signature: sha256=6f1d0c…<64 caracteres hex>

{"data":{…},"event":"document.status-changed","id":"…","timestamp":"…","workspace_id":"…"}
```

| Header | Descripción |
|---|---|
| `x-sovra-event` | El tipo de evento, también presente en el cuerpo. Sirve para rutear sin parsear. |
| `x-sovra-signature` | `sha256=` + HMAC-SHA256 del **cuerpo crudo**, en hexadecimal minúscula. |

Los nombres de cabecera son insensibles a mayúsculas: tu framework las va a resolver
igual si las buscás como `X-Sovra-Signature`.

## Validar la firma HMAC

**Verificá la firma en cada entrega.** Sin eso, cualquiera que conozca tu URL puede
inventarte una emisión o una verificación aprobada.

La firma es
`HMAC-SHA256(clave = el secreto del endpoint como UTF-8, mensaje = el cuerpo crudo)`,
en hexadecimal minúscula y con el prefijo `sha256=`. **La clave es el secreto
completo, incluido el prefijo `whsec_`.**

El algoritmo:

1. Tomá el **cuerpo crudo** de la petición, byte por byte. **No lo parsees ni lo
   vuelvas a serializar**: `JSON.parse` + `JSON.stringify` reordena claves y cambia
   el espaciado, y la firma deja de coincidir.
2. Calculá `HMAC-SHA256(webhook_secret, cuerpo_crudo)`.
3. Codificalo en hexadecimal minúscula y anteponé `sha256=`.
4. Compará con `x-sovra-signature` usando una **comparación de tiempo constante**.

La firma cubre el cuerpo, y el cuerpo incluye `timestamp`, **pero no hay una cabecera
de timestamp aparte**. Para protegerte de una reinyección, deduplicá por `id`. También
podés rechazar sobres cuyo `timestamp` sea demasiado viejo, siempre que la ventana sea
más ancha que el calendario de reintentos (~1,5 horas).

### Node.js / Express

```js
import crypto from "node:crypto";
import express from "express";

const app = express();
const SECRET = process.env.SOVRA_WEBHOOK_SECRET; // el whsec_… completo

// express.raw() preserva el cuerpo intacto. express.json() NO sirve acá.
app.post(
  "/webhooks/sovra",
  express.raw({ type: "application/json" }),
  (req, res) => {
    const recibida = req.get("x-sovra-signature") ?? "";
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
SECRET = os.environ["SOVRA_WEBHOOK_SECRET"].encode()  # el whsec_… completo

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

## Entrega y reintentos

Cada evento es **un `POST` por cada endpoint suscrito y habilitado**. Cada intento
tiene un timeout de recepción de **5 segundos**, y el código de respuesta decide qué
pasa después:

| Respuesta de tu servidor | Qué hace Sovra |
|---|---|
| **2xx** | Entregado. Fin. |
| **4xx** (cualquiera, **incluidos `408` y `429`**) | **Se descarta y no se reintenta nunca.** Se interpreta como un bug del lado que recibe. |
| **5xx**, cualquier otro código, timeout o error de conexión | **Se reintenta.** |
| **3xx** | Se sigue el redirect. `301`/`302`/`303` se reenvían como **`GET`**; `307`/`308` conservan el `POST`. La respuesta final se clasifica con esta misma tabla. **Apuntá el endpoint a su URL final.** |

**Son 8 intentos en total**, con un backoff que crece rápido: entre el primero y el
último pasan en total **~1,5 horas**. Después de eso la entrega se descarta y el
evento no vuelve.

**Un endpoint caído más tiempo que eso pierde el evento**, y la única forma de
ponerse al día es consultar la API.

Devolvé `2xx` apenas guardaste el evento, y procesalo después. Devolvé `5xx` ante un
problema transitorio de tu lado; **nunca `4xx`**.

⚠️ La consecuencia práctica del 4xx: si tu validación de firma devuelve `401` porque
el secreto está mal configurado, **perdés el evento para siempre**. Durante la puesta
en marcha, registrá los payloads que rechaces por firma inválida.

### Qué se vuelve a leer en cada intento

El endpoint se busca de nuevo **en cada intento**:

- Un endpoint borrado, deshabilitado o desuscrito del evento **después** de que el
  evento se emitió se saltea en silencio.
- Una URL cambiada la usan los reintentos pendientes.
- Un secreto rotado firma los reintentos pendientes.

Un endpoint deshabilitado **en el momento en que el evento se emite** no recibe ese
evento nunca, aunque después se vuelva a habilitar.

## Orden y consistencia

**No hay garantía de orden.** Las entregas corren en paralelo y cada endpoint
reintenta con su propio calendario. Ordená por `timestamp`, o por `status_at` donde
exista.

Un evento **se emite en la misma transacción de base de datos que el cambio de estado
que anuncia**, con la excepción de `identity.did-generated`, que se emite
inmediatamente después. Es decir: si recibiste el evento, el estado que describe ya
está confirmado, y un `GET` sobre el recurso devuelve ese mismo estado o uno
posterior.

Cuando **nada** está suscrito a un evento, el evento no se guarda ni se encola en
ningún lado.

## Catálogo de eventos

| Evento | Cuándo se dispara |
|---|---|
| [`identity.did-generated`](#identitydid-generated) | El workspace terminó de aprovisionarse y su DID quedó registrado on-chain. |
| [`credential.issued`](#credentialissued) | Un holder canjeó una oferta y recibió la credencial. |
| [`credential.revoked`](#credentialrevoked--credentialsuspended--credentialunsuspended) | Una revocación pedida llegó a la lista de estado on-chain. |
| [`credential.suspended`](#credentialrevoked--credentialsuspended--credentialunsuspended) | Una suspensión pedida llegó a la lista de estado on-chain. |
| [`credential.unsuspended`](#credentialrevoked--credentialsuspended--credentialunsuspended) | El levantamiento de una suspensión llegó a la lista de estado on-chain. |
| [`credential.status-change-failed`](#credentialstatus-change-failed) | Un cambio de estado pedido se dio por vencido en su último intento; el estado **no** cambió. |
| [`document.status-changed`](#documentstatus-changed) | Un documento firmado quedó anclado on-chain, o el anclaje se dio por vencido. |
| [`presentation.verified`](#presentationverified) | Una presentación pasó todas las validaciones. |
| [`presentation.failed`](#presentationfailed) | Una presentación fue rechazada. |

### `identity.did-generated`

**Se dispara cuando** el aprovisionamiento del workspace completó todos sus pasos:
clave de firma, dirección de relayer (EOA), DID `did:sovra:<eoa>`, registro del DID
on-chain, registro del emisor, y los bitmaps de revocación y de suspensión. El
workspace queda en `done` y el evento se emite una sola vez.

**No se dispara** si el aprovisionamiento falla: no hay evento de falla. Un
aprovisionamiento reintentado desde el dashboard emite el evento cuando tiene éxito.

> Los endpoints se buscan **en el momento en que el evento se emite**, y un workspace
> recién creado no tiene ninguno. En la práctica, este evento solo le llega a un
> endpoint que ya existía mientras el aprovisionamiento corría, o antes del reintento
> de uno que había fallado.

| Campo de `data` | Tipo | Nulo | Descripción |
|---|---|---|---|
| `did` | string | no | El DID del workspace: `did:sovra:0x` + 40 hex en minúscula. |
| `provisioned_at` | string | no | Cuándo terminó el aprovisionamiento. |

```json
{
  "event": "identity.did-generated",
  "id": "5b0e7c1e-2f0a-4a51-9a39-0f6f2d3c8e11",
  "timestamp": "2026-08-26T04:10:02.482913Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "did": "did:sovra:0xcda79e1ee5612c230a6faf68236e3679b0579bae",
    "provisioned_at": "2026-08-26T04:10:01Z"
  }
}
```

A partir de acá el workspace puede crear esquemas, ofertas y verificaciones.

### `credential.issued`

**Se dispara cuando** la wallet del holder canjea una oferta por OID4VCI y la
credencial queda construida y guardada. Se emite en la misma transacción que registra
la credencial y marca la oferta como canjeada.

**No se dispara** cuando se crea la oferta (`POST /credential-offer` solo devuelve la
oferta), cuando una oferta vence sin canjearse, ni cuando el canje falla (prueba
inválida, oferta vencida, oferta ya canjeada).

| Campo de `data` | Tipo | Nulo | Descripción |
|---|---|---|---|
| `credential_id` | uuid | no | El mismo que devolvió `POST /credential-offer`. Sirve para `GET /api/v1/issuer/credentials/{id}` y para los endpoints de estado. |
| `holder_did` | string | no | El DID del holder, tomado de la prueba de la wallet. |
| `schema_type` | string | no | El `credential_type` del esquema. |
| `format` | string | no | `vc+sd-jwt` o `mso_mdoc`. |
| `doc_type` | string | — | **Solo cuando `format` es `mso_mdoc`.** El docType del mdoc. |
| `issued_at` | string | no | Momento de la emisión. |
| `expires_at` | string | sí | El vencimiento que lleva la credencial. `null` si el esquema no tiene `validity_period` y la oferta no fijó `valid_until`. |
| `credential` | string | no | **La credencial tal como se le entregó al holder.** En `vc+sd-jwt`, el SD-JWT `<jwt>~<disclosure>~…~`. En `mso_mdoc`, el `IssuerSigned` en CBOR, base64url sin padding. **Lleva todos los valores: tratalo como dato personal.** |
| `attachment` | object | — | **Solo cuando la credencial lleva un adjunto** (ver abajo). |

```json
{
  "event": "credential.issued",
  "id": "4f3379d8-0fab-4631-975c-c36a68528bd4",
  "timestamp": "2026-08-26T04:41:33.108371Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "credential_id": "b662aaad-d940-48fb-be9e-7194c76210ff",
    "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
    "schema_type": "DrivingLicence",
    "format": "vc+sd-jwt",
    "issued_at": "2026-08-26T04:41:32Z",
    "expires_at": "2027-08-26T04:41:32Z",
    "credential": "eyJhbGciOiJFUzI1NiIsInR5cCI6InZjK3NkLWp3dCJ9…~WyJxN0NfZ0x…~"
  }
}
```

> 🔴 **Única entrega.** El SD-JWT no se guarda del lado servidor ni se puede volver
> a pedir. Persistilo acá si tu sistema lo necesita.

**Los adjuntos no viajan en el webhook.** Un esquema `vc+sd-jwt` puede declarar un
claim `attachment`, que lleva un PDF como data URI de hasta 560.000 bytes. En la
entrega:

- la disclosure del adjunto **se saca** del `credential`;
- su digest **queda** en el array `_sd` firmado, así que la firma sigue verificando y
  el claim se lee como retenido;
- `attachment` describe lo que se sacó:

| Campo de `attachment` | Tipo | Descripción |
|---|---|---|
| `claim` | string | La clave del claim que se sacó. |
| `type` | string | Siempre `attachment`. |
| `media_type` | string | El tipo de medio del archivo, p. ej. `application/pdf`. |
| `bytes` | integer | El tamaño del archivo decodificado, en bytes. |

```json
"attachment": { "claim": "titulo_pdf", "type": "attachment",
                "media_type": "application/pdf", "bytes": 184213 }
```

La credencial que tiene la wallet **sí incluye el adjunto**: no se le saca a ella, se
le saca a la copia que viaja por el webhook.

En mDoc, `doc_type` es un campo aditivo y `credential` es el `IssuerSigned`:

```json
{
  "event": "credential.issued",
  "id": "e0b1c2d3-4f5a-4b6c-8d7e-9f0a1b2c3d4e",
  "timestamp": "2026-09-28T13:02:11.114208Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "credential_id": "9d8c7b6a-5f4e-4d3c-8b2a-1f0e9d8c7b6a",
    "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
    "schema_type": "DrivingLicence",
    "format": "mso_mdoc",
    "doc_type": "org.iso.18013.5.1.mDL",
    "issued_at": "2026-09-28T13:02:11Z",
    "expires_at": "2036-09-28T13:02:11Z",
    "credential": "ompuYW1lU3BhY2VzoXFvcmcuaXNvLjE4MDEzLjUuMYbYGFhT…"
  }
}
```

### `credential.revoked` / `credential.suspended` / `credential.unsuspended`

**Se disparan cuando un cambio de estado pedido llega a la cadena.** La secuencia es:

1. El pedido llega por `PUT /api/v1/issuer/credentials/{id}/status/{status}` —con
   `{status}` en `revoked`, `suspended` o `issued` para levantar una suspensión— o
   desde el dashboard.
2. El pedido devuelve **`202`** al instante, con el destino en `pending_status`.
3. Un job escribe el cambio en la lista de estado on-chain del workspace.
4. Cuando la transacción confirma, el `status` de la credencial cambia y **este evento
   se emite en la misma transacción**.

> ⚠️ **Hasta entonces, los verificadores —que leen la lista de estado— siguen viendo
> el estado anterior.**

| Evento | Transición |
|---|---|
| `credential.revoked` | `issued` → `revoked`, o `suspended` → `revoked`. La revocación es permanente. |
| `credential.suspended` | `issued` → `suspended` |
| `credential.unsuspended` | `suspended` → `issued` |

Una credencial admite **un cambio pendiente a la vez**.

**No se disparan** cuando el cambio se pide, ni en un intento fallido que va a
reintentarse —los intentos fallan en silencio hasta el quinto y último—, ni cuando el
último intento falla: en ese caso se envía
[`credential.status-change-failed`](#credentialstatus-change-failed).

El payload **no trae un campo `status`**, porque el nombre del evento es el estado
nuevo.

| Campo de `data` | Tipo | Nulo | Descripción |
|---|---|---|---|
| `credential_id` | uuid | no | La credencial. |
| `holder_did` | string | no | El DID del holder. |
| `schema_type` | string | no | El `credential_type` del esquema. |
| `status_at` | string (µs) | no | Cuándo se aplicó el estado nuevo. |

```json
{
  "event": "credential.revoked",
  "id": "71e4a0c9-3b2d-4f8e-a6c5-0d9b8e7f6a52",
  "timestamp": "2026-08-26T05:02:11.640277Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "credential_id": "b662aaad-d940-48fb-be9e-7194c76210ff",
    "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
    "schema_type": "DrivingLicence",
    "status_at": "2026-08-26T05:02:11.638904Z"
  }
}
```

Se disparan tanto si el cambio se hizo por API como desde el dashboard, así que sirven
para mantener sincronizados sistemas que no originaron la acción.

### `credential.status-change-failed`

**Se dispara cuando el quinto y último intento de un cambio de estado falla.**
Normalmente es un problema de la cadena o del relayer, y el job registra el motivo.

El `status` de la credencial queda como estaba, su `pending_status` se limpia, y el
motivo queda en `status_change_error`, que devuelve
`GET /api/v1/issuer/credentials/{id}`. **Recién entonces se puede volver a pedir el
cambio.**

| Campo de `data` | Tipo | Nulo | Descripción |
|---|---|---|---|
| `credential_id` | uuid | no | La credencial. |
| `holder_did` | string | no | El DID del holder. |
| `schema_type` | string | no | El `credential_type` del esquema. |
| `requested_status` | string | no | El estado que se había pedido: `revoked`, `suspended` o `issued`. |
| `status` | string | no | El estado de la credencial, **sin cambios**: `issued` o `suspended`. |
| `status_at` | string (µs) | no | Cuándo se dio por vencido el cambio. |

```json
{
  "event": "credential.status-change-failed",
  "id": "0f9e8d7c-6b5a-4c3d-9e2f-1a0b9c8d7e6f",
  "timestamp": "2026-08-26T05:09:52.002914Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "credential_id": "b662aaad-d940-48fb-be9e-7194c76210ff",
    "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
    "schema_type": "DrivingLicence",
    "requested_status": "revoked",
    "status": "issued",
    "status_at": "2026-08-26T05:09:52.001377Z"
  }
}
```

> 🔴 **Suscribite a este evento si revocás por API.** Sin él, una revocación que no
> llegó a la cadena se ve igual que una que sí llegó: el `202` ya lo recibiste.

### `document.status-changed`

**Se dispara cuando el anclaje de un documento firmado se resuelve:**

- `anchored`: la firma del emisor quedó registrada en el registro de firmas on-chain;
- `failed`: el anclaje se dio por vencido.

La secuencia es: la emisión (`POST /api/v1/issuer/documents`, o el dashboard)
responde al instante con el documento en `signed`; un job manda la transacción y
espera su recibo; el estado nuevo y este evento se confirman juntos.

Lo que eso significa para una integración:

- **Un documento `signed` todavía no se puede usar.** Un verificador lo rechaza hasta
  que esté `anchored`. **Este evento es la señal de que el documento se puede
  entregar.**
- **`failed` llega recién después de que falló el quinto intento.** Los anteriores se
  reintentan en silencio. Un documento `failed` hay que volver a emitirlo.
- **Ningún documento falla por tiempo.** Uno que espera en una cola larga sigue en
  `signed` hasta que su job corre; nada lo marca `failed` por tardar. El anclaje tiene
  un techo de throughput —del orden de 5 documentos por bloque y por nodo—, así que un
  lote grande puede tardar varios minutos en resolverse.

Cada documento emite este evento **una sola vez**, con su estado final.

| Campo de `data` | Tipo | Nulo | Descripción |
|---|---|---|---|
| `document_id` | uuid | no | El documento, tal como lo devolvió la emisión. |
| `status` | string | no | `anchored` o `failed`. |
| `schema_type` | string | no | El `credential_type` del esquema de documento. |
| `tx_hash` | string | sí | La transacción del anclaje, `0x` + 64 hex. `null` cuando es `failed`. |
| `anchored_at` | string | sí | Cuándo quedó registrado. `null` cuando es `failed`. |
| `status_at` | string (µs) | no | Cuándo cambió el estado en Sovra. |

```json
{
  "event": "document.status-changed",
  "id": "d2c3b4a5-9687-4e1f-a0b9-c8d7e6f5a4b3",
  "timestamp": "2026-08-21T14:12:08.401552Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "document_id": "63813a91-6986-46bf-9f23-128a6598cd82",
    "status": "anchored",
    "schema_type": "Document",
    "tx_hash": "0x9c93c1eec04ae7e4566b4ee394ac518b33c36b0ffd324276289e998eed72f8ca",
    "anchored_at": "2026-08-21T14:12:08Z",
    "status_at": "2026-08-21T14:12:08.399871Z"
  }
}
```

El caso `failed`:

```json
{
  "event": "document.status-changed",
  "id": "8a7b6c5d-4e3f-4a2b-9c1d-0e9f8a7b6c5d",
  "timestamp": "2026-08-21T14:19:44.770318Z",
  "workspace_id": "bfe85e0d-c2da-4ccd-bd99-d26827f7e45e",
  "data": {
    "document_id": "1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d",
    "status": "failed",
    "schema_type": "Document",
    "tx_hash": null,
    "anchored_at": null,
    "status_at": "2026-08-21T14:19:44.768902Z"
  }
}
```

### `presentation.verified`

**Se dispara cuando** una wallet manda una presentación (OID4VP `direct_post`) a una
sesión `pending` y **pasa todos los controles**: la firma del emisor, el binding del
holder, el nonce y la audiencia, las disclosures o los digests de valor, el
vencimiento, el estado de revocación y suspensión, y la coincidencia con el DCQL. La
sesión pasa a `completed` en la misma transacción.

**No se dispara** cuando una sesión vence sin que nadie presente —pasa a `expired` sin
webhook—, cuando la presentación va a una sesión inexistente o ya resuelta, ni cuando
hay un error interno durante la verificación.

| Campo de `data` | Tipo | Nulo | Descripción |
|---|---|---|---|
| `verification_id` | uuid | no | La sesión. Es el mismo valor que el `session_id` que devolvió `POST /api/v1/verifier/verifications`. |
| `holder_did` | string | no | El DID del holder que presentó. |
| `success` | boolean | no | Siempre `true`. |
| `credentials` | array | no | Una entrada por consulta de credencial del DCQL. |
| `completed_at` | string | no | Cuándo se completó la sesión. |

Cada entrada de `credentials`:

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | string | El `id` de la consulta DCQL: la etiqueta que elegiste. |
| `format` | string | El formato que pidió la consulta: `dc+sd-jwt`, `vc+sd-jwt` o `mso_mdoc`. |
| `claims` | object | **Solo los claims que la consulta pidió**, indexados por path. SD-JWT: el `path` del DCQL unido con `.`, p. ej. `address.locality`. mdoc: `<namespace>.<element_identifier>`, p. ej. `org.iso.18013.5.1.family_name`. En mdoc, los byte strings van en base64url y las fechas como string ISO 8601. |

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
        "format": "dc+sd-jwt",
        "claims": { "given_name": "Ana", "employer.name": "Acme S.A." }
      }
    ]
  }
}
```

### `presentation.failed`

**Se dispara cuando** una wallet manda una presentación a una sesión `pending` y
**falla algún control**. La sesión pasa a `failed` en la misma transacción, y `error`
dice qué control falló. Valen las mismas exclusiones que en
[`presentation.verified`](#presentationverified), con un agregado: **una presentación
que llega a una sesión todavía `pending` pero ya pasada de su vencimiento sí dispara
este evento**, con `session_expired`.

| Campo de `data` | Tipo | Nulo | Descripción |
|---|---|---|---|
| `verification_id` | uuid | no | La sesión. |
| `holder_did` | string | sí | El DID del holder, si se llegó a establecer antes de la falla; `null` si no. |
| `success` | boolean | no | Siempre `false`. |
| `error` | string | no | La etiqueta de la falla (tabla abajo). Algunas llevan un detalle después de `:`. |
| `completed_at` | string | no | Cuándo falló la sesión. |

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

| `error` | Qué significa |
|---|---|
| `session_expired` | Presentó después del `expires_at` de la sesión. |
| `invalid_dcql_query` | El DCQL de la sesión no sirve para esta presentación. |
| `malformed_vp_token` | El `vp_token` no es una respuesta DCQL ni una presentación bien formada. |
| `unknown_issuer` | El emisor de la credencial no es un emisor conocido y confiable. |
| `credential_signature_invalid` | La firma del emisor no verifica, o el docType de un mdoc no coincide con su MSO. |
| `invalid_disclosure` | Una disclosure de SD-JWT, o un digest de valor de mdoc, no coincide con lo que el emisor firmó. |
| `missing_mandatory_element:<namespace>[.<elemento>]` | Al mdoc le falta un elemento que Sovra exige. |
| `credential_expired` | La credencial venció. |
| `credential_revoked` | El emisor la revocó. |
| `credential_suspended` | El emisor la suspendió. |
| `status_check_failed` | El estado on-chain no pudo leerse. Es un problema del lado de Sovra; la credencial puede ser válida. |
| `holder_did_missing` | La credencial no nombra holder. |
| `holder_did_unresolvable` | El DID del holder no pudo resolverse. Problema del lado de Sovra, o un holder no registrado. |
| `holder_did_mismatch` | El holder de la credencial y el del KB-JWT no coinciden. |
| `holder_key_mismatch` | La presentación no la firmó la clave del holder. |
| `holder_nonce_mismatch` | El KB-JWT no es para el nonce de esta sesión. |
| `holder_audience_mismatch` | El KB-JWT está dirigido a otro verificador. |
| `holder_iat_out_of_range` | El `iat` del KB-JWT está desfasado más de 5 minutos. |
| `sd_hash_mismatch` | El KB-JWT no se compromete con el SD-JWT presentado. |
| `device_mac_unsupported` | El mdoc usó autenticación de dispositivo por MAC, que no está soportada. |
| `unsupported_device_auth_alg` | El algoritmo de la firma de dispositivo del mdoc no está soportado. |
| `format_mismatch[:<formato>]` | La credencial presentada no está en el formato que pidió la consulta. |
| `doctype_mismatch` | El docType del mdoc no es el que pidió la consulta. |
| `missing_required_claim:<path>` | La credencial no lleva un claim que la consulta pidió. |

Ver también la [tabla completa de errores](08-errores-y-troubleshooting.md#errores-de-presentación).

## Buenas prácticas

**Respondé rápido, procesá después.** Devolvé `200` apenas validás la firma y hacé el
trabajo pesado en segundo plano. El presupuesto son 5 segundos.

**Sé idempotente.** Los reintentos entregan el mismo evento con el mismo `id`.
Guardalo y descartá repetidos.

**Nunca devuelvas 4xx por un problema tuyo.** Un `4xx` descarta el evento para
siempre. Si tu base está caída, devolvé `5xx` y aprovechá los 8 intentos.

**Suscribite a los eventos de falla.** `credential.status-change-failed` y el
`document.status-changed` con `status: "failed"` son la única forma de enterarte de
que una operación que respondió `202`/`201` terminó mal.

**Correlacioná por el identificador de negocio.** `credential_id` para emisiones,
`document_id` para documentos, `verification_id` para verificaciones. Guardalos
cuando creás la oferta, el documento o la sesión, antes de mostrar el QR.

**No confíes en el orden.** Los eventos se entregan en paralelo y con reintentos; un
`credential.revoked` puede llegar antes que un `credential.issued` demorado. Usá
`timestamp`, o `status_at`, para resolver conflictos.

**Validá siempre la firma.** También en los entornos de test.

**Registrá todo.** Guardar el payload crudo, la firma y tu veredicto ahorra horas de
depuración — y es lo único que tenés, porque no hay historial de entregas del lado de
Sovra.

**Tené un plan B.** Si tu endpoint estuvo caído más de ~1,5 horas, el evento se
perdió: reconciliá con `GET /api/v1/issuer/credentials`,
`GET /api/v1/issuer/documents` y `GET /api/v1/verifier/verifications`.

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
    if (!firmaValida(req.body, req.get("x-sovra-signature"))) {
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
        sd_jwt: data.credential, // única entrega: guardalo
      });
      break;

    case "credential.revoked":
    case "credential.suspended":
    case "credential.unsuspended":
      await db.tramites.updateBySovraId(data.credential_id, {
        estado: event.split(".")[1], // ya confirmado on-chain
      });
      break;

    case "credential.status-change-failed":
      // El 202 que recibiste no se concretó: el estado quedó como estaba.
      await db.tramites.updateBySovraId(data.credential_id, {
        estado: data.status,
        error_cambio_estado: data.requested_status,
      });
      break;

    case "document.status-changed":
      // Solo `anchored` es entregable; `failed` hay que volver a emitirlo.
      await db.documentos.updateBySovraId(data.document_id, {
        estado: data.status,
        tx_hash: data.tx_hash,
        entregable: data.status === "anchored",
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
