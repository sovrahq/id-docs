# 7. Referencia de la API

Referencia completa de la API servidor-a-servidor de Sovra ID (`/api/v1`).

## Tabla de contenidos

1. [Convenciones](#convenciones)
2. [Autenticación](#autenticación)
3. [Emisor](#emisor)
4. [Verificador](#verificador)
5. [Endpoints públicos](#endpoints-públicos)
6. [Documentos firmados](#documentos-firmados)
7. [Límites y cuotas](#límites-y-cuotas)
8. [Objetos comunes](#objetos-comunes)

---

## Convenciones

**Base URL**

| Entorno | URL |
|---|---|
| Test | `https://test-api-sovra.flagonsa.com` |
| Producción | `https://api.sovra.io` |

**Formato.** Todo es JSON (`Content-Type: application/json`), salvo una excepción que
se indica explícitamente: la lista de estado (`application/jwt`).

**Fechas.** ISO 8601 en UTC (`2026-08-26T04:41:32Z`). Dentro del SD-JWT, `iat` y
`exp` son segundos Unix.

**Identificadores.** UUID v4, salvo `schema_id`, que es el slug del esquema.

**Errores.** Siempre el mismo sobre:

```json
{ "error": "codigo_de_error", "details": { } }
```

`details` es opcional y aparece solo en errores de validación.

**Especificación OpenAPI en vivo:** `GET {baseUrl}/openapi/api`.

## Autenticación

Todos los endpoints bajo `/api/v1/issuer` y `/api/v1/verifier` requieren una **API
key de workspace**:

```http
Authorization: Bearer sovra_sk_...
```

El esquema `Bearer` es sensible a mayúsculas. El workspace se deduce de la clave: no
se envía `workspace_id` en ningún request.

Fallo de autenticación → `401`:

```json
{ "error": "invalid_api_key" }
```

Las claves se crean en el dashboard (**Settings → API keys**) y se muestran una sola
vez.

Los endpoints bajo `/api/v1/status` y `/api/v1/documents` son **públicos**.

---

## Emisor

### `POST /api/v1/issuer/credential-offer`

Crea una oferta OID4VCI pre-autorizada. Ver [3. Emisión](03-emision-de-credenciales.md).

**Body**

| Campo | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `schema_id` | string | ✅ | El slug del esquema. Se usa su última versión. |
| `claims` | object | — | Valores `key` → valor. Default `{}`. |

**Respuesta `201`**

| Campo | Tipo | Descripción |
|---|---|---|
| `credential_id` | uuid | Identificador estable de todo el ciclo de vida. |
| `offer_uri` | string | URI `openid-credential-offer://…` para el QR. |
| `pre_authorized_code` | string | Código de un solo uso (ya embebido en `offer_uri`). |
| `expires_at` | string | Vencimiento de la oferta: 24 h. |
| `credential` | object | Vista previa **sin firmar** del payload. |

**Errores:** `401 invalid_api_key`, `404 schema_not_found`, `404 workspace_not_found`,
`409 workspace_not_provisioned`, `422 missing_claims`, `422 wrong_schema_kind`.

**Rate limit:** 120 req/min por API key.

---

### `GET /api/v1/issuer/credentials`

Lista las credenciales **efectivamente emitidas** por el workspace. Las ofertas
pendientes no aparecen.

**Respuesta `200`**

```json
{
  "credentials": [
    {
      "id": "b662aaad-d940-48fb-be9e-7194c76210ff",
      "holder_did": "did:sovra:0x9083…",
      "status": "issued",
      "issued_at": "2026-08-26T04:41:32Z",
      "expires_at": "2027-08-26T04:41:32Z",
      "schema": {
        "id": "…",
        "schema_id": "licencia_de_conducir",
        "credential_type": "VerifiableCredential",
        "name": "Licencia de Conducir"
      }
    }
  ]
}
```

---

### `GET /api/v1/issuer/credentials/{id}`

Metadatos de una credencial. Funciona desde que se crea la oferta.

**Parámetros de ruta:** `id` — el `credential_id`.

**Respuesta `200`** — [objeto Credential](#credential). Si todavía no fue canjeada,
`status` es `pending` (o `expired`), con `holder_did` e `issued_at` en `null`.

**Errores:** `401 invalid_api_key`, `404 credential_not_found`.

> El SD-JWT firmado **no** se devuelve acá. Solo llega en el webhook
> `credential.issued`.

---

### `PUT /api/v1/issuer/credentials/{id}/status/{status}`

Cambia el estado. Ver [6. Ciclo de vida](06-ciclo-de-vida-y-revocacion.md).

**Parámetros de ruta**

| Parámetro | Valores |
|---|---|
| `id` | El `credential_id`. |
| `status` | `revoked` (permanente), `suspended` (reversible), `issued` (reactivar). |

**Respuesta `200`**

```json
{ "id": "b662aaad-d940-48fb-be9e-7194c76210ff", "status": "revoked" }
```

**Errores:** `400 unknown_status`, `401 invalid_api_key`, `404 credential_not_found`,
`409 workspace_not_provisioned`, `422 credential_not_yet_issued`,
`422 invalid_status_transition`.

Dispara `credential.revoked`, `credential.suspended` o `credential.unsuspended`.

---

## Verificador

### `POST /api/v1/verifier/verifications`

Crea una sesión OID4VP. Ver [4. Verificación](04-verificacion-de-credenciales.md).

**Body**

| Campo | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `dcql_query` | object | ✅ | Consulta DCQL. Debe traer `credentials` con al menos un elemento. |

**Respuesta `201`**

| Campo | Tipo | Descripción |
|---|---|---|
| `session_id` | uuid | Es el `verification_id` de los webhooks. |
| `authorization_request_uri` | string | URI `openid4vp://…` autocontenida (paso por valor). |
| `authorization_request_uri_ref` | string | URI corta que apunta a la solicitud completa (paso por referencia). |
| `status` | string | Siempre `pending`. |
| `expires_at` | string | Vencimiento: 10 minutos. |

**Errores:** `400 missing_dcql_query`, `400 invalid_dcql_query`, `401 invalid_api_key`,
`403 workspace_inactive`, `403 organization_inactive`, `404 workspace_not_found`,
`409 workspace_not_provisioned`.

---

### `GET /api/v1/verifier/verifications`

Lista las sesiones del workspace, de la más nueva a la más vieja.

**Query params**

| Parámetro | Tipo | Default | Descripción |
|---|---|---|---|
| `status` | string | — | `pending`, `completed`, `failed`, `expired`. |
| `limit` | integer | 50 | Máximo 200. |
| `offset` | integer | 0 | |

**Respuesta `200`**

```json
{ "sessions": [ /* objetos Session */ ], "limit": 50, "offset": 0 }
```

---

### `GET /api/v1/verifier/verifications/{id}`

**Respuesta `200`** — [objeto Session](#session).

**Errores:** `401 invalid_api_key`, `403 workspace_inactive`,
`403 organization_inactive`, `404 session_not_found`.

> Las sesiones de otro workspace responden `404`, no `403`: la API no revela su
> existencia.

---

## Endpoints públicos

### `GET /api/v1/status/{issuer_did}/{purpose}`

La Bitstring Status List firmada del emisor. Sin autenticación.

**Parámetros:** `issuer_did` (`did:sovra:0x…`), `purpose` (`revocation` | `suspension`).

**Respuesta `200`:** `application/jwt` — un VC-JWT firmado con ES256.
Trae `ETag` y `Cache-Control`; con `If-None-Match` podés recibir `304`.

**Errores:** `400` (purpose inválido), `404` (emisor no encontrado).

> Ruta **inmutable**: las credenciales emitidas la llevan embebida.

---

### `GET /api/v1/documents/{id}`

Devuelve la credencial de un documento firmado, para que un verificador la
compruebe. Nunca devuelve un veredicto. Tener el id es la autorización (salvo que el
documento sea `private`).

---

## Documentos firmados

> 📑 **Resumen.** La documentación completa de documentos firmados —emisión,
> visibilidad, renderizado, verificación, errores— está en
> [`../documents/`](../documents/), y la referencia endpoint por endpoint en
> [`../documents/07-referencia-api.md`](../documents/07-referencia-api.md).

Un **documento** es una variante sin wallet: el emisor firma los datos directamente,
sin holder y sin divulgación selectiva, y ancla la firma on-chain. Sirve para
certificados, constancias y actas — cosas que se comparten por enlace, no desde una
wallet.

Requiere un esquema de kind `document`. Los endpoints viven en la misma superficie de
API key:

| Método | Ruta | Descripción |
|---|---|---|
| `POST` | `/api/v1/issuer/documents` | Valida, firma, guarda y ancla, en una sola llamada. |
| `GET` | `/api/v1/issuer/documents` | Lista los documentos del workspace. |
| `PUT` | `/api/v1/issuer/documents/{id}/visibility/{visibility}` | Cambia entre `public` y `private`. |

**`POST /api/v1/issuer/documents`**

```json
{
  "schema_id": "constancia_de_estudios",
  "claims": { "nombre": "Ada Lovelace", "carrera": "Matemática" },
  "visibility": "public"
}
```

**Respuesta `201`** — incluye `id`, `status`, `visibility`, `credential` (el SD-JWT
sin disclosures, `<jwt>~`), `claims`, `digest`, `tx_hash`, `signed_at`,
`anchored_at`, `issuer` y `schema`.

`status` puede ser `signed`, `anchored`, `failed` o `revoked`. **Solo `anchored` es
un estado entregable**: `failed` significa que el documento se firmó pero su
transacción de anclaje no confirmó, y un verificador lo va a rechazar.

**Errores:** `404` (esquema o workspace), `409 workspace_not_provisioned`,
`422` (kind incorrecto, claims faltantes, visibilidad desconocida),
`503` (sin registro de firmas configurado — no se puede anclar y la emisión se rechaza).

---

## Límites y cuotas

| Recurso | Límite |
|---|---|
| `POST /api/v1/issuer/credential-offer` | 120 req/min por API key |
| Vigencia de una oferta | 24 horas |
| Vigencia de una sesión de verificación | 10 minutos |
| `limit` en el listado de verificaciones | 200 |
| Timeout de entrega de webhook | 5 segundos |
| Reintentos de webhook | 8 intentos, con backoff |
| Desfasaje horario tolerado en el KB-JWT | ±5 minutos |
| Layout de un documento | 3 MB |

## Objetos comunes

### Credential

```json
{
  "id": "b662aaad-d940-48fb-be9e-7194c76210ff",
  "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
  "status": "issued",
  "issued_at": "2026-08-26T04:41:32Z",
  "expires_at": "2027-08-26T04:41:32Z",
  "schema": {
    "id": "3f2b…",
    "schema_id": "licencia_de_conducir",
    "credential_type": "VerifiableCredential",
    "name": "Licencia de Conducir"
  }
}
```

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | uuid | El `credential_id`. |
| `holder_did` | string \| null | `null` mientras esté `pending`. |
| `status` | string | `pending`, `expired`, `issued`, `suspended`, `revoked`. |
| `issued_at` | string \| null | `null` mientras esté `pending`. |
| `expires_at` | string | Vencimiento de la credencial (o de la oferta, si está `pending`). |
| `schema` | object | Esquema con el que se emitió. |

### Session

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

| Campo | Tipo | Descripción |
|---|---|---|
| `session_id` | uuid | Identificador de la sesión. |
| `status` | string | `pending`, `completed`, `failed`, `expired`. |
| `dcql_query` | object | La consulta con la que se creó. |
| `holder_did` | string \| null | Se conoce recién cuando el holder responde. |
| `presentation_data` | object \| null | Los datos divulgados; solo si `completed`. |
| `error` | string \| null | El código de error; solo si `failed`. |
| `expires_at` | string | Vencimiento (10 min desde la creación). |
| `completed_at` | string \| null | Cuándo terminó. |
| `inserted_at` | string | Cuándo se creó. |

### DCQL query

```json
{
  "credentials": [
    {
      "id": "etiqueta_libre",
      "format": "vc+sd-jwt",
      "claims": [
        { "path": ["claim_plano"] },
        { "path": ["objeto", "claim_anidado"] }
      ]
    }
  ]
}
```

Ver [las reglas de escritura](04-verificacion-de-credenciales.md#escribir-la-consulta-dcql).

---

**Anterior:** [← 6. Ciclo de vida y revocación](06-ciclo-de-vida-y-revocacion.md) · **Siguiente:** [8. Errores y troubleshooting →](08-errores-y-troubleshooting.md)
