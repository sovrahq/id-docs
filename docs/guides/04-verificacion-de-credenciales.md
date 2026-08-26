# 4. Verificación de credenciales

Verificar también son tres movimientos: **creás una sesión** con la consulta DCQL,
**mostrás un QR**, y **recibís un webhook** con el veredicto.

## Tabla de contenidos

1. [Paso 1 — Crear la sesión de verificación](#paso-1--crear-la-sesión-de-verificación)
2. [Escribir la consulta DCQL](#escribir-la-consulta-dcql)
3. [La respuesta, campo por campo](#la-respuesta-campo-por-campo)
4. [Paso 2 — Mostrar el QR](#paso-2--mostrar-el-qr)
5. [Paso 3 — Recibir el resultado](#paso-3--recibir-el-resultado)
6. [Qué valida Sovra exactamente](#qué-valida-sovra-exactamente)
7. [Consultar sesiones](#consultar-sesiones)
8. [Errores al crear la sesión](#errores-al-crear-la-sesión)
9. [Ejemplo completo en Node.js](#ejemplo-completo-en-nodejs)

---

## Paso 1 — Crear la sesión de verificación

```http
POST /api/v1/verifier/verifications
Authorization: Bearer sovra_sk_...
Content-Type: application/json
```

```json
{
  "dcql_query": {
    "credentials": [
      {
        "id": "licencia",
        "format": "vc+sd-jwt",
        "claims": [
          { "path": ["full_name"] },
          { "path": ["age_over_18"] }
        ]
      }
    ]
  }
}
```

```bash
curl -X POST "$BASE_URL/api/v1/verifier/verifications" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"dcql_query":{"credentials":[{"id":"licencia","format":"vc+sd-jwt","claims":[{"path":["full_name"]}]}]}}'
```

## Escribir la consulta DCQL

`dcql_query` sigue el **DCQL** de OID4VP. Es la parte donde se cometen casi todos
los errores, así que vale la pena leerla con detalle.

```json
{
  "credentials": [
    {
      "id": "licencia",
      "format": "vc+sd-jwt",
      "claims": [
        { "path": ["full_name"] },
        { "path": ["direccion", "ciudad"] }
      ]
    }
  ]
}
```

| Campo | Obligatorio | Qué significa |
|---|---|---|
| `credentials` | ✅ | Lista **no vacía** de credenciales pedidas. Si falta o está vacía → `400 invalid_dcql_query`. |
| `credentials[].id` | ✅ | **Una etiqueta tuya**, libre. Sovra la devuelve tal cual en el resultado para que sepas qué credencial es cuál. No filtra ni valida nada. |
| `credentials[].format` | ✅ | `vc+sd-jwt` (o `dc+sd-jwt`). Cualquier otro valor falla con `format_mismatch`. |
| `credentials[].claims` | — | Los campos que pedís. Cada uno es `{ "path": [...] }`. |
| `claims[].path` | ✅ | Ruta al claim. Un solo elemento para claims planos (`["email"]`), varios para anidados (`["direccion","ciudad"]`). |

### Las tres reglas que evitan el 90 % de los fallos

**1. `path` debe coincidir *exactamente* con el `key` del esquema.**
Sensible a mayúsculas, sin normalizaciones, sin sinónimos. Si el esquema declaró
`fist_name` (con errata), pedir `first_name` falla con
`missing_required_claim:first_name` — aunque el nombre esté en la credencial.
Consultá los keys reales en **Schemas** del dashboard, o decodificando un SD-JWT
emitido.

**2. Todos los claims pedidos son obligatorios.** DCQL en Sovra no tiene campos
opcionales: si el holder no divulga uno solo de los `path` que pediste, **toda la
presentación falla**. Pedí el mínimo indispensable — es mejor para la privacidad y
para la tasa de éxito.

**3. El `id` no selecciona la credencial.** Sovra valida el `format` y la presencia
de los `path`; no compara el `id` contra el `vct` ni contra el esquema. Si necesitás
asegurarte de que la credencial sea de un tipo determinado, **pedí un claim que solo
ese tipo tenga**, y en tu servidor comprobá los valores que llegan.

### Ejemplos

**Solo mayoría de edad** — el caso que mejor muestra la divulgación selectiva. El
verificador confirma que la persona es mayor sin enterarse de su nombre ni de su
fecha de nacimiento:

```json
{ "credentials": [ { "id": "edad", "format": "vc+sd-jwt",
  "claims": [ { "path": ["age_over_18"] } ] } ] }
```

**Identificación completa:**

```json
{ "credentials": [ { "id": "identidad", "format": "vc+sd-jwt",
  "claims": [
    { "path": ["full_name"] },
    { "path": ["document_number"] },
    { "path": ["birth_date"] }
  ] } ] }
```

**Solo presencia de la credencial**, sin pedir ningún dato (`claims` vacío): igual se
valida firma del emisor, prueba del holder, expiración y revocación.

```json
{ "credentials": [ { "id": "cualquiera", "format": "vc+sd-jwt", "claims": [] } ] }
```

## La respuesta, campo por campo

`201 Created`:

```json
{
  "session_id": "e2d16450-42e8-46a9-ae92-d72af51a7435",
  "authorization_request_uri": "openid4vp://?client_id=verifier%3Abfe85e0d…&response_type=vp_token&…",
  "authorization_request_uri_ref": "openid4vp://?client_id=verifier%3Abfe85e0d…&request_uri=https%3A%2F%2Fapi.sovra.io%2Fwallet%2Fverifier%2Frequest%2Fe2d16450…",
  "status": "pending",
  "expires_at": "2026-08-26T04:57:31Z"
}
```

| Campo | Descripción |
|---|---|
| `session_id` | Identificador de la sesión. Es el `verification_id` que verás en el webhook. Guardalo. |
| `authorization_request_uri` | **Paso por valor**: la URI lleva toda la solicitud embebida (incluido el DCQL). Autocontenida. |
| `authorization_request_uri_ref` | **Paso por referencia**: URI corta que apunta a un endpoint donde la wallet descarga la solicitud completa. |
| `status` | Siempre `pending` al crearse. |
| `expires_at` | La sesión vive **10 minutos**. |

### ¿Cuál de las dos URIs uso?

Empezá con `authorization_request_uri`. Es autocontenida y no requiere que la wallet
haga una llamada extra.

Cambiá a `authorization_request_uri_ref` cuando el DCQL crezca: al ir embebido en la
URI, un query grande produce un **QR muy denso**, difícil de escanear en pantallas
chicas o con poca luz. La versión `_ref` es corta y constante sin importar el tamaño
de la consulta.

## Paso 2 — Mostrar el QR

```js
import QRCode from "qrcode";
const dataUrl = await QRCode.toDataURL(session.authorization_request_uri);
```

Al escanear, el ciudadano ve **exactamente qué campos le estás pidiendo** y los
aprueba o rechaza **uno por uno**. La wallet arma el `vp_token` — la credencial con
las disclosures elegidas, más un **KB-JWT** firmado en el momento con su passkey — y
lo envía a Sovra.

Como la sesión dura 10 minutos, mostrá una cuenta regresiva en pantalla y ofrecé
regenerar el QR cuando venza. Una sesión vencida sin uso queda en estado `expired`
(sin webhook); si el holder intenta responder tarde, falla con `session_expired`.

## Paso 3 — Recibir el resultado

### ✅ `presentation.verified`

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

| Campo de `data` | Descripción |
|---|---|
| `verification_id` | El `session_id` de la sesión que creaste. Correlacioná por acá. |
| `holder_did` | DID del ciudadano que presentó. |
| `success` | `true`. |
| `credentials[]` | Una entrada por cada credencial pedida en el DCQL. |
| `credentials[].id` | La etiqueta que vos elegiste en el DCQL, devuelta tal cual. |
| `credentials[].claims` | **Los valores divulgados**, indexados por el `path` unido con puntos (`["direccion","ciudad"]` → `"direccion.ciudad"`). |

Recibir este evento significa que Sovra ya validó **todo**: firma del emisor, prueba
de posesión del holder, vigencia, revocación y suspensión. Los valores de `claims`
son confiables.

### ❌ `presentation.failed`

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

`error` dice **exactamente** en qué paso se cortó. En el ejemplo de arriba, el DCQL
pidió `first_name` pero la credencial tiene el claim como `fist_name` — un error de
tipeo en el esquema, no un problema de la credencial ni del ciudadano.

La lista completa de valores de `error`, con causa y solución de cada uno, está en
[8. Errores y troubleshooting](08-errores-y-troubleshooting.md#errores-de-presentación).

> `holder_did` puede venir `null` cuando la falla ocurre antes de poder identificar
> al holder (por ejemplo `malformed_vp_token` o `session_expired`).

## Qué valida Sovra exactamente

Al recibir el `vp_token`, la validación corre en cascada y **se detiene en el primer
fallo**:

| # | Comprobación | Error si falla |
|---|---|---|
| 1 | El `vp_token` tiene la forma que exige OID4VP para DCQL | `malformed_vp_token` |
| 2 | El SD-JWT parsea correctamente | `malformed_vp_token` |
| 3 | El emisor existe y la firma ES256 es válida | `unknown_issuer`, `credential_signature_invalid` |
| 4 | La credencial declara un holder (`sub`) | `holder_did_missing` |
| 5 | El DID del holder resuelve a llaves on-chain | `holder_did_unresolvable` |
| 6 | El KB-JWT está firmado por una llave del holder | `holder_key_mismatch` |
| 7 | El `iss` del KB-JWT coincide con el `sub` de la credencial | `holder_did_mismatch` |
| 8 | `nonce`, `aud`, `iat` (±5 min) y `sd_hash` del KB-JWT | `holder_nonce_mismatch`, `holder_audience_mismatch`, `holder_iat_out_of_range`, `sd_hash_mismatch` |
| 9 | Cada disclosure corresponde a un digest `_sd` del JWT | `invalid_disclosure` |
| 10 | La credencial no expiró (`exp`) | `credential_expired` |
| 11 | El bit de revocación / suspensión on-chain | `credential_revoked`, `credential_suspended`, `status_check_failed` |
| 12 | Todos los `path` del DCQL están presentes | `missing_required_claim:<path>` |

Los pasos 6 a 8 son los que hacen que **una credencial robada no sirva**: cada
presentación se firma en vivo con la passkey del ciudadano, contra un `nonce` y una
`aud` que Sovra generó para *esa* sesión. No se puede reproducir ni reutilizar.

## Consultar sesiones

Los webhooks son el camino recomendado, pero también podés consultar por API — útil
para pantallas de estado o como red de contención si tu endpoint estuvo caído.

**Una sesión:**

```bash
curl -s "$BASE_URL/api/v1/verifier/verifications/$SESSION_ID" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

```json
{
  "session_id": "e2d16450-42e8-46a9-ae92-d72af51a7435",
  "status": "completed",
  "dcql_query": { "credentials": [ /* … */ ] },
  "holder_did": "did:sovra:0x9083…",
  "presentation_data": {
    "credentials": [
      { "id": "training_v2", "format": "vc+sd-jwt", "claims": { "email": "…" } }
    ]
  },
  "error": null,
  "expires_at": "2026-08-26T04:57:31Z",
  "completed_at": "2026-08-26T04:47:31Z",
  "inserted_at": "2026-08-26T04:47:31Z"
}
```

Estados: `pending`, `completed`, `failed`, `expired`.

**Listado, con filtros y paginación:**

```bash
curl -s "$BASE_URL/api/v1/verifier/verifications?status=completed&limit=50&offset=0" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

| Query param | Valores | Default |
|---|---|---|
| `status` | `pending`, `completed`, `failed`, `expired` | sin filtro |
| `limit` | 1–200 | 50 |
| `offset` | ≥ 0 | 0 |

Ordenado de más nuevo a más viejo.

## Errores al crear la sesión

| HTTP | `error` | Causa |
|---|---|---|
| 400 | `missing_dcql_query` | No mandaste `dcql_query`. |
| 400 | `invalid_dcql_query` | `dcql_query` no es un objeto, o `credentials` falta / está vacío. |
| 401 | `invalid_api_key` | Header de autorización ausente o inválido. |
| 403 | `workspace_inactive` / `organization_inactive` | El workspace o la organización están desactivados. |
| 404 | `workspace_not_found` | La API key no resuelve a un workspace. |
| 409 | `workspace_not_provisioned` | El DID del workspace todavía no está listo. |

## Ejemplo completo en Node.js

```js
async function pedirVerificacion(claims) {
  const res = await fetch(`${BASE_URL}/api/v1/verifier/verifications`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${API_KEY}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      dcql_query: {
        credentials: [
          {
            id: "identidad",
            format: "vc+sd-jwt",
            claims: claims.map((path) => ({ path: [path] })),
          },
        ],
      },
    }),
  });

  if (!res.ok) throw new Error(`Sovra ${res.status}: ${(await res.json()).error}`);

  const session = await res.json();

  await db.verificaciones.create({
    sovra_session_id: session.session_id,
    estado: "pendiente",
    expira: session.expires_at,
  });

  return session; // renderizá session.authorization_request_uri como QR
}
```

```js
app.post("/webhooks/sovra", verificarFirmaSovra, async (req, res) => {
  const { event, data } = req.body;

  switch (event) {
    case "presentation.verified": {
      const datos = data.credentials[0].claims; // { full_name: "…", age_over_18: true }
      await db.verificaciones.updateBySovraId(data.verification_id, {
        estado: "aprobada",
        holder_did: data.holder_did,
        datos,
      });
      break;
    }

    case "presentation.failed":
      await db.verificaciones.updateBySovraId(data.verification_id, {
        estado: "rechazada",
        motivo: data.error,
      });
      break;
  }

  res.sendStatus(200);
});
```

---

**Anterior:** [← 3. Emisión de credenciales](03-emision-de-credenciales.md) · **Siguiente:** [5. Webhooks →](05-webhooks.md)
