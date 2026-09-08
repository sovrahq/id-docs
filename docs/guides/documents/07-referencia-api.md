# 7. Referencia de la API

Referencia completa de la API de documentos firmados. **Son cuatro endpoints, y no
hay más.**

## Tabla de contenidos

1. [Convenciones](#convenciones)
2. [Autenticación](#autenticación)
3. [Los cuatro endpoints](#los-cuatro-endpoints)
4. [`POST /api/v1/issuer/documents`](#post-apiv1issuerdocuments)
5. [`PUT /api/v1/issuer/documents/{id}/visibility/{visibility}`](#put-apiv1issuerdocumentsidvisibilityvisibility)
6. [`GET /api/v1/issuer/documents`](#get-apiv1issuerdocuments)
7. [`GET /api/v1/documents/{id}`](#get-apiv1documentsid)
8. [Lo que no existe](#lo-que-no-existe)
9. [Límites](#límites)
10. [Colección de Postman](#colección-de-postman)

---

## Convenciones

**Base URL**

| Entorno | URL |
|---|---|
| Test | `https://test-api-sovra.flagonsa.com` |
| Producción | `https://api.sovra.io` |

```bash
export BASE_URL="https://test-api-sovra.flagonsa.com"
export SOVRA_API_KEY="sovra_sk_..."
```

**Formato.** Todo es JSON (`Content-Type: application/json`).

**Fechas.** ISO 8601 en UTC (`2026-08-21T14:12:43Z`). Dentro del `credential`, `iat` y
`exp` son segundos Unix.

**Identificadores.** UUID v4, salvo `schema_id`, que es el slug del esquema.

**Errores.** Siempre el mismo sobre:

```json
{ "error": "codigo_de_error", "details": { } }
```

`details` es opcional y aparece solo en errores de validación.

**Especificación OpenAPI en vivo:** `GET {baseUrl}/openapi/api`.

## Autenticación

**Dos zonas**, y la diferencia está en el path:

| Zona | Path | Autenticación |
|---|---|---|
| **Emisor** | `/api/v1/issuer/…` | `Authorization: Bearer sovra_sk_...` |
| **Pública** | `/api/v1/documents/…` | Ninguna. Para documentos públicos, tener el `id` es toda la autorización. |

- El esquema `Bearer` es **sensible a mayúsculas**: `bearer` devuelve
  `401 invalid_api_key`.
- El workspace se deduce de la clave: **no se envía `workspace_id`** en ningún request.
- **Una API key no sustituye una sesión.** Un documento privado leído por la zona
  pública devuelve `401 authentication_required` incluso con la key en el header.
- La zona pública responde `Access-Control-Allow-Origin: *`. La zona emisor **no**:
  el preflight no devuelve `Allow-Origin`, así que el navegador la bloquea. Hace falta
  un proxy del lado servidor.

## Los cuatro endpoints

| Método | Ruta | Qué hace | Auth |
|---|---|---|---|
| `POST` | `/api/v1/issuer/documents` | Emite: valida, firma y ancla | API key |
| `PUT` | `/api/v1/issuer/documents/{id}/visibility/{visibility}` | Publica o despublica | API key |
| `GET` | `/api/v1/issuer/documents` | Lista todo el workspace | API key |
| `GET` | `/api/v1/documents/{id}` | Devuelve el documento en JSON | — |

**No hay más.** En particular: no hay endpoints para crear esquemas o layouts (eso es
dashboard), ni para buscar, ni para verificar — ver
[lo que no existe](#lo-que-no-existe).

---

## `POST /api/v1/issuer/documents`

Emite un documento a partir de un esquema existente. Una sola llamada valida los
claims, firma con la clave del emisor y ancla la firma on-chain.

**Auth:** `Authorization: Bearer <API_KEY>` · **Content-Type:** `application/json`

**Body**

| Campo | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `schema_id` | `string` | ✅ | El identificador legible del esquema (el `schema.schema_id`), no el UUID interno. |
| `claims` | `object` | ✅ | Un par `key: valor` por cada claim del esquema. Los `required` no pueden faltar. |
| `visibility` | `string` | — | `"private"` (default) o `"public"`. |

```bash
curl -s -X POST "$BASE_URL/api/v1/issuer/documents" \
  --max-time 120 \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{
    "schema_id": "document_example",
    "claims": { "date": "20-08-2026", "idDocument": 1234567,
                "content": "Contenido de Prueba", "isConfidential": false },
    "visibility": "private"
  }'
```

**`201 Created`** — el objeto [Document](#document).

⏱ **Puede tardar hasta ~90 segundos.** Poné el timeout del cliente en 120 s. En test
suele responder en ~3 s, pero el techo depende de la congestión de la cadena.

**Errores**

| Respuesta | Causa |
|---|---|
| `401 invalid_api_key` | Key desconocida, malformada o revocada, o el esquema no era literalmente `Bearer`. |
| `409 workspace_not_provisioned` | El workspace todavía no tiene DID ni claves. |
| `422 wrong_schema_kind` | El esquema es de kind `credential`, no `document`. |
| `422 missing_claims` | Falta un claim requerido. `details` lista cuáles. |
| `422 invalid_visibility` | `visibility` no es `public` ni `private`. |
| `503 chain_not_configured` | No hay registro de firmas configurado: se niega a firmar. Intencional. |

Ver [3. Emisión de documentos](03-emision-de-documentos.md).

---

## `PUT /api/v1/issuer/documents/{id}/visibility/{visibility}`

Cambia la visibilidad. **Sin body y sin `Content-Type`:** los dos valores viajan en el
path.

**Auth:** `Authorization: Bearer <API_KEY>`

| Parámetro | Posición | Tipo | Descripción |
|---|---|---|---|
| `id` | path | `uuid` | El `id` que devolvió la emisión. |
| `visibility` | path | `string` | `public` o `private`. |

```bash
curl -s -X PUT -H "Authorization: Bearer $SOVRA_API_KEY" \
  "$BASE_URL/api/v1/issuer/documents/$DOCUMENT_ID/visibility/public"
```

**`200 OK`** — el mismo objeto [Document](#document), con `visibility` actualizada. La
firma, el `digest`, el `credential` y el `tx_hash` **no se tocan**: el documento no se
reemite.

**Errores**

| Respuesta | Causa |
|---|---|
| `401 invalid_api_key` | Key inválida, o el esquema no era literalmente `Bearer`. |
| `404 document_not_found` | Id inexistente, malformado, o de otro workspace. |
| `422 invalid_visibility` | El último segmento del path no es `public` ni `private`. |

Ver [4. Visibilidad y entrega](04-visibilidad-y-entrega.md).

---

## `GET /api/v1/issuer/documents`

Devuelve todos los documentos del workspace, **del más reciente al más antiguo**.

**Auth:** `Authorization: Bearer <API_KEY>` · **Sin parámetros, sin body.**

```bash
curl -s "$BASE_URL/api/v1/issuer/documents" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

**`200 OK`**

```json
{
  "documents": [
    { "id": "63813a91-…", "status": "anchored", "credential": "eyJhbGci…~", "…": "…" }
  ]
}
```

| Campo | Tipo | Descripción |
|---|---|---|
| `documents` | `array<object>` | Una entrada por documento, de la más nueva a la más vieja. |
| `documents[]` | `object` | Cada entrada tiene **exactamente la misma forma** que la respuesta de emisión — objeto [Document](#document), con el `credential` completo y los `claims` decodificados. |

**Sin paginación** (no hay `?page`, `?limit` ni cursor; los query params se ignoran) y
**sin filtros ni búsqueda**. En la práctica es el único punto de consulta del lado
emisor.

**Errores**

| Respuesta | Causa |
|---|---|
| `401 invalid_api_key` | Key inválida, o el esquema no era literalmente `Bearer`. |
| `409 workspace_not_provisioned` | El workspace todavía no tiene DID ni claves. |

---

## `GET /api/v1/documents/{id}`

Devuelve el documento a quien recibió el enlace. Es el endpoint que consume el
destinatario, no el emisor.

**Auth:** ninguna para documentos públicos; **sesión del dashboard** para los
privados. Responde `Access-Control-Allow-Origin: *`.

| Parámetro | Posición | Tipo | Descripción |
|---|---|---|---|
| `id` | path | `uuid` | El id del documento. |

```bash
curl -s "$BASE_URL/api/v1/documents/$DOCUMENT_ID"
```

**`200 OK`** — el objeto [PublicDocument](#publicdocument).

**Errores**

| Respuesta | Causa |
|---|---|
| `401 authentication_required` | El documento es privado y no hay sesión. Se devuelve **incluso cuando el header traía una API key**. |
| `404 document_not_found` | Id inexistente, malformado, o de otro workspace. No distingue entre los tres casos, a propósito. |

Ver [4. Visibilidad y entrega](04-visibilidad-y-entrega.md#leer-un-documento-la-zona-pública).

---

## Lo que no existe

Vale la pena listarlo, porque cada ausencia es deliberada y cambia el diseño de la
integración:

| No hay | Por qué / qué usar en cambio |
|---|---|
| **Endpoint de verificación** | Un veredicto habría que creerlo. La verificación corre del lado de quien verifica, contra la cadena → [6. Verificación](06-verificacion.md). |
| **Endpoints de esquemas ni de layouts** | Se definen en el dashboard, con sesión → [2. Primeros pasos](02-primeros-pasos.md). |
| **Búsqueda ni filtros** | `?digest=`, `/by-digest/` y `/search` no existen; los query params se ignoran. Indexá del lado tuyo a partir del listado. |
| **Paginación** | El listado devuelve todo. |
| **Webhooks de documentos** | El resultado llega como respuesta del `POST`. No hay eventos. |
| **Endpoint de revocación** | `revoked_at` y `status: "revoked"` existen, pero la baja se gestiona desde el dashboard → [guía 3](03-emision-de-documentos.md#revocación). |
| **Un `GET` de un documento propio por la zona emisor** | El listado es el único camino del lado emisor; para uno solo, la zona pública. |

## Límites

| Recurso | Límite |
|---|---|
| Latencia de la emisión | hasta ~90 s (timeout del cliente recomendado: 120 s) |
| Layout de un documento | 3 MB |
| Techo de un QR con el `credential` (nivel L) | ~2953 bytes |
| Paginación del listado | no hay: devuelve todo |

## Objetos comunes

### Document

Lo que devuelven `POST /issuer/documents`, `PUT …/visibility/…` y cada entrada de
`GET /issuer/documents`.

```json
{
  "id": "63813a91-6986-46bf-9f23-128a6598cd82",
  "status": "anchored",
  "visibility": "private",
  "credential": "eyJhbGciOiJFUzI1NiIsInR5cCI6InZjK3Nk…~",
  "claims": { "date": "20-08-2026", "idDocument": 1234567,
              "content": "Contenido de Prueba", "isConfidential": false },
  "digest": "1043…",
  "tx_hash": "0x9c93c1eec04ae7e4566b4ee394ac518b33c36b0ffd324276289e998eed72f8ca",
  "signed_at": "2026-08-21T14:12:34Z",
  "anchored_at": "2026-08-21T14:12:43Z",
  "revoked_at": null,
  "issuer": { "did": "did:sovra:0xb516d2…", "name": "Lotería de San Juan",
              "address": "0xb516d2…" },
  "schema": { "id": "3f2b…", "schema_id": "document_example",
              "credential_type": "Document", "name": "Document Example",
              "version": "v1", "claims": [ /* … */ ] }
}
```

`status`: `signed`, `anchored`, `failed` o `revoked`. **Solo `anchored` es un estado
entregable.** El significado de cada campo, y si está o no cubierto por la firma, en
[la tabla de la guía 3](03-emision-de-documentos.md#la-respuesta-campo-por-campo).

### PublicDocument

Lo que devuelve `GET /api/v1/documents/{id}`. Es un recorte del anterior **más el
`layout`**:

```json
{
  "id": "63813a91-6986-46bf-9f23-128a6598cd82",
  "credential": "eyJhbGciOiJFUzI1NiIsInR5cCI6InZjK3Nk…~",
  "issuer": { "did": "did:sovra:0xb516d2…", "name": "Lotería de San Juan",
              "address": "0xb516d2…" },
  "schema": { "name": "Document Example", "claims": [ /* … */ ] },
  "layout": { "page": { "width": 794, "height": 1123 }, "elements": [ /* … */ ] },
  "anchor": { "status": "anchored", "tx_hash": "0x9c93…",
              "anchored_at": "2026-08-21T14:12:43Z" }
}
```

No trae `claims` decodificados, ni `visibility`, ni `signed_at`/`revoked_at`, ni
`schema.schema_id`/`credential_type`. Los valores de los campos salen del
`credential`. Ver
[las diferencias](04-visibilidad-y-entrega.md#diferencias-con-la-respuesta-de-emisión).

### Claim definition

Cada entrada de `schema.claims[]`:

| Campo | Tipo | Descripción |
|---|---|---|
| `key` | `string` | Nombre del campo dentro de `claims`. |
| `label` | `string` | Etiqueta legible, para UI y layout. |
| `type` | `string` | `date`, `number`, `string` o `boolean`. |
| `required` | `boolean` | Si falta al emitir → `422 missing_claims`. |
| `disclosable` | `boolean` | Divulgación selectiva bajo SD-JWT. No aplica a documentos: no hay holder. |

## Colección de Postman

[`sovra-documentos-firmados.postman_collection.json`](../../resources/sovra-documentos-firmados.postman_collection.json)
(formato v2.1.0).

**Variables de colección**

| Variable | Valor | Notas |
|---|---|---|
| `base_url` | URL base de la API, **sin slash final** | |
| `api_key` | La key del workspace | Solo la usan los requests de `/issuer`. **Vaciala antes de exportar o compartir la colección.** |
| `schema_id` | Id del esquema de kind `document` | |
| `document_id` | Id del documento | Se autocompleta al emitir. |

**Requests**

*Carpeta "Issuer (requiere API key)"* — todos con `Authorization: Bearer {{api_key}}`:

| Request | Llamada |
|---|---|
| Emitir documento | `POST {{base_url}}/api/v1/issuer/documents` |
| Cambiar visibilidad → public | `PUT …/issuer/documents/{{document_id}}/visibility/public` |
| Cambiar visibilidad → private | `PUT …/issuer/documents/{{document_id}}/visibility/private` |
| Listar documentos del workspace | `GET {{base_url}}/api/v1/issuer/documents` |

*Carpeta "Público (sin API key)"* — sin headers de autenticación:

| Request | Llamada |
|---|---|
| Obtener documento (JSON) | `GET {{base_url}}/api/v1/documents/{{document_id}}` |

**Auto-encadenado.** El request de emisión trae un test script: si la respuesta es
`201`, guarda `body.id` en la variable `document_id`. Por eso el orden natural de uso
es **emitir → cambiar visibilidad → leer**, sin copiar ids a mano.

**Tests incluidos**

| Request | Verifica |
|---|---|
| Emitir documento | `201 Created`, y que `credential` sea un string. |
| Visibilidad → public / private | `200 OK`, y que `visibility` quedó en el valor pedido. |
| Listar documentos | `200 OK`, y que `documents` sea un array. |
| Obtener documento (JSON) | Que el código sea `200` **o** `401` —según si el documento está publicado— y, si es `200`, que traiga `credential`. |

Ese último test acepta los dos códigos a propósito: **un `401` acá no es una falla de
la integración, es la respuesta correcta para un documento privado.**

---

**Anterior:** [← 6. Verificación](06-verificacion.md) · **Siguiente:** [8. Errores y troubleshooting →](08-errores-y-troubleshooting.md)
