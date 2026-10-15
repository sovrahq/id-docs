# 7. Referencia de la API

Referencia completa de la API de documentos firmados: **los cuatro endpoints del
documento en sí, más los de esquemas** que hacen falta para crear el molde con el que
se firma.

La referencia completa de `/api/v1` —credenciales, verificaciones, diseño de la
tarjeta en la wallet— está en
[`../credentials/07-referencia-api.md`](../credentials/07-referencia-api.md).

## Tabla de contenidos

1. [Convenciones](#convenciones)
2. [Autenticación](#autenticación)
3. [Los endpoints](#los-endpoints)
4. [Esquemas de kind `document`](#esquemas-de-kind-document)
5. [`POST /api/v1/issuer/documents`](#post-apiv1issuerdocuments)
6. [`PUT /api/v1/issuer/documents/{id}/visibility/{visibility}`](#put-apiv1issuerdocumentsidvisibilityvisibility)
7. [`GET /api/v1/issuer/documents`](#get-apiv1issuerdocuments)
8. [`GET /api/v1/documents/{id}`](#get-apiv1documentsid)
9. [Lo que no existe](#lo-que-no-existe)
10. [Límites](#límites)
11. [Objetos comunes](#objetos-comunes)
12. [Colección de Postman](#colección-de-postman)

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

**Formato.** Todo es JSON (`Content-Type: application/json`). El cuerpo tiene un techo
de **8 MB**; por encima, `413`.

**La cabecera `Accept` importa.** Toda ruta JSON exige un `Accept` que admita
`application/json` — `*/*`, el default de curl, lo admite. Cualquier otra cosa es un
`406` **antes de llegar al endpoint**.

**Fechas.** ISO 8601 en UTC (`2026-08-21T14:12:43Z`). Dentro del `credential`, `iat` y
`exp` son segundos Unix.

**Identificadores.** UUID, salvo `schema_id`, que es el slug del esquema.

**`x-request-id`.** Toda respuesta la trae. Citala cuando reportes un problema.

**Errores.** Los errores del endpoint comparten un sobre:

```json
{ "error": "missing_claims", "details": ["content", "idDocument"] }
```

`details` es opcional, y según el error es un string, una lista de strings o un objeto
campo → mensajes. **Los errores del framework usan otro sobre**, porque se producen
antes de que el endpoint corra: `{"errors": {"detail": "Bad Request"}}` — un cuerpo
JSON malformado (`400`), ninguna ruta que coincida (`404`), un `Accept` incompatible
(`406`), un cuerpo de más de 8 MB (`413`).

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
- Un documento o un esquema **de otro workspace responde `404`**, nunca `403`: la API
  no revela su existencia.
- Después de validar la clave, el request se rechaza con `403 workspace_inactive` o
  `403 organization_inactive` si el tenant está desactivado. **Desactivar un tenant no
  revoca sus claves**: este control es lo que las detiene.
- La zona pública responde `Access-Control-Allow-Origin: *`. La zona emisor **no**:
  el preflight no devuelve `Allow-Origin`, así que el navegador la bloquea. Hace falta
  un proxy del lado servidor.

## Los endpoints

| Método | Ruta | Qué hace | Auth |
|---|---|---|---|
| `POST` | `/api/v1/issuer/schemas` | Crea el esquema de kind `document` | API key |
| `GET` | `/api/v1/issuer/schemas` | Lista los esquemas y su estado de registro | API key |
| `PUT` | `/api/v1/issuer/schemas/{schema_id}` | Revisa el esquema (nueva versión) | API key |
| `DELETE` | `/api/v1/issuer/schemas/{id}` | Borra el esquema y todas sus versiones | API key |
| `POST` | `/api/v1/issuer/documents` | Emite: valida, firma y **encola el anclaje** | API key |
| `PUT` | `/api/v1/issuer/documents/{id}/visibility/{visibility}` | Publica o despublica | API key |
| `GET` | `/api/v1/issuer/documents` | Lista todo el workspace | API key |
| `GET` | `/api/v1/documents/{id}` | Devuelve el documento en JSON | — |

**No hay más** para documentos. En particular: no hay endpoints para el **layout** de
la hoja (eso sigue siendo dashboard), ni para buscar, ni para verificar, ni para
revocar — ver [lo que no existe](#lo-que-no-existe).

---

## Esquemas de kind `document`

Un documento se firma contra un esquema de `kind: "document"`. **Ese esquema ya se
puede crear por API**, con los mismos endpoints que un esquema de credencial: la
diferencia está en el campo `kind`.

La referencia completa de los cuatro endpoints —definición de claim, objeto Schema,
versionado con `base_version`, errores— está en
[`../credentials/07-referencia-api.md#esquemas`](../credentials/07-referencia-api.md#esquemas).
Acá va lo que cambia para documentos.

### Crear el esquema

```bash
curl -s -X POST "$BASE_URL/api/v1/issuer/schemas" \
  -H "Authorization: Bearer $SOVRA_API_KEY" -H 'Content-Type: application/json' \
  -d '{
    "schema_id": "constancia_de_estudios",
    "credential_type": "EnrollmentCertificate",
    "name": "Constancia de estudios",
    "kind": "document",
    "validity_period": null,
    "claims": [
      {"key": "nombre",  "type": "string", "required": true,  "label": "Nombre"},
      {"key": "carrera", "type": "string", "required": true,  "label": "Carrera"},
      {"key": "fecha",   "type": "date",   "required": false, "label": "Fecha"}
    ]
  }'
```

Cuatro cosas específicas de un esquema de documento:

| | |
|---|---|
| `kind` | **`"document"`.** Sin este campo el esquema nace `credential`, y emitir con él devuelve `422 wrong_schema_kind`. |
| `format` | Queda en `vc+sd-jwt`, el default. **`mso_mdoc` no admite `kind: document`.** |
| `validity_period` | La clave **tiene que estar presente**, aunque sea en `null` — omitirla es un `422`. `null` emite documentos que no vencen, que es lo habitual en una constancia. |
| `disclosable` | No tiene efecto: un documento no tiene holder que negocie divulgación. Todos los claims van a la vista. |

**`201 Created`** — el [objeto Schema](#schema), con `registration_status: "pending"`.

> 🔴 **No se puede emitir hasta que el esquema esté `registered`.** El tipo de
> credencial se registra on-chain en segundo plano; mientras eso corre,
> `POST /documents` devuelve `409 schema_registration_pending`. **No hay webhook de
> registro**: consultá `GET /api/v1/issuer/schemas` y mirá `registration_status`.

### El `layout` sigue siendo del dashboard

El `layout` —la geometría de la hoja, lo que la convierte en un documento imprimible y
no en un JSON— **no se puede definir por API**. Se arma en el dashboard y queda
asociado a la versión del esquema; las versiones nuevas lo heredan.

`GET /api/v1/issuer/schemas` lo devuelve en el campo `layout` (solo en el listado), y
`GET /api/v1/documents/{id}` lo devuelve para que puedas dibujar la hoja → ver
[5. Renderizado](05-renderizado.md).

El **diseño** (`PUT /schemas/{schema_id}/design`) es otra cosa: es cómo se dibuja una
tarjeta en la wallet, y **no aplica a un documento**, que no vive en ninguna wallet.

---

## `POST /api/v1/issuer/documents`

Emite un documento a partir de un esquema existente. La llamada valida los claims y
firma con la clave del emisor; **el anclaje on-chain se encola**.

**Auth:** `Authorization: Bearer <API_KEY>` · **Content-Type:** `application/json`

**Body**

| Campo | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `schema_id` | `string` | ✅ | El identificador legible del esquema (el `schema.schema_id`), no el UUID interno. Se usa su última versión. **Omitirlo es un `500`.** |
| `claims` | `object` | — | Un par `key: valor` por cada claim del esquema. Los `required` no pueden faltar. **Las claves que el esquema no declara se descartan antes de firmar**, y los valores se firman tal como los mandás: `"42"` y `42` son documentos distintos. **Tiene que ser un objeto**, o el request es un `500`. |
| `visibility` | `string` | — | `"private"` (default) o `"public"`. |
| `valid_until` | `string` | — | ISO 8601, a futuro. Se firma como `exp` y pisa el `validity_period` del esquema. **`null` se rechaza.** |

```bash
curl -s -X POST "$BASE_URL/api/v1/issuer/documents" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{
    "schema_id": "document_example",
    "claims": { "date": "20-08-2026", "idDocument": 1234567,
                "content": "Contenido de Prueba", "isConfidential": false },
    "visibility": "private"
  }'
```

**`201 Created`** — el objeto [Document](#document), con **`status: "signed"` y
`tx_hash: null`**.

> 🔴 **La emisión devuelve al instante; el anclaje, no.** El anclaje corre en un job y
> el documento pasa después a `anchored` —con `tx_hash` y `anchored_at`— o a `failed`,
> después de 5 intentos. Cada transición emite
> [`document.status-changed`](../credentials/05-webhooks.md#documentstatus-changed).
> **Un verificador rechaza un documento que no esté `anchored`**, así que esperá el
> webhook, o consultá `GET /issuer/documents`, antes de entregarlo. Ver
> [3. Emisión](03-emision-de-documentos.md#el-anclaje-es-asincrónico).

**Errores**

| Respuesta | Causa |
|---|---|
| `401 invalid_api_key` | Key desconocida, malformada o revocada, o el esquema no era literalmente `Bearer`. |
| `404 schema_not_found` | No hay esquema con ese `schema_id` en este workspace. |
| `409 workspace_not_provisioned` | El workspace todavía no tiene DID ni claves. |
| `409 schema_registration_pending` | El tipo de credencial se sigue registrando on-chain. Reintentá en unos segundos. |
| `409 schema_registration_failed` | El tipo no pudo registrarse: con ese esquema no se puede emitir nada. |
| `422 wrong_schema_kind` | El esquema es de kind `credential`, no `document`. |
| `422 missing_claims` | Falta un claim requerido. `details` lista cuáles. |
| `422 invalid_visibility` | `visibility` no es `public` ni `private`. |
| `422 invalid_valid_until` | `valid_until` es `null`, está malformado o quedó en el pasado. |
| `422 validation_error` | El documento no pudo guardarse. |
| `503 chain_not_configured` | No hay registro de firmas configurado: **se niega a firmar algo que no podría anclar**. Intencional. |

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
visibilidad **no es parte de la firma**, así que esto no invalida nada: el `digest`, el
`credential` y el `tx_hash` no se tocan y el documento no se reemite.

**Despublicar cierra el enlace, no las copias.** Un `credential` que ya entregaste
sigue siendo verificable por quien lo tenga.

**Errores**

| Respuesta | Causa |
|---|---|
| `401 invalid_api_key` | Key inválida, o el esquema no era literalmente `Bearer`. |
| `404 document_not_found` | Id inexistente, malformado, o de otro workspace. |
| `422 invalid_visibility` | El último segmento del path no es `public` ni `private`. |

Ver [4. Visibilidad y entrega](04-visibilidad-y-entrega.md).

---

## `GET /api/v1/issuer/documents`

Devuelve todos los documentos del workspace, **en todos los estados** y **del más
reciente al más antiguo**.

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
emisor, **y la alternativa al webhook** para saber si un documento ya quedó
`anchored`.

**Errores**

| Respuesta | Causa |
|---|---|
| `401 invalid_api_key` | Key inválida, o el esquema no era literalmente `Bearer`. |
| `409 workspace_not_provisioned` | El workspace todavía no tiene DID ni claves. |

---

## `GET /api/v1/documents/{id}`

Devuelve el documento a quien recibió el enlace. Es el endpoint que consume el
destinatario, no el emisor. **Sirve la credencial, nunca un veredicto:** la firma y el
ancla on-chain los comprueba quien lee.

**Auth:** ninguna para documentos públicos; **sesión del dashboard** para los
privados — la cookie `document_session`, o `Authorization: Bearer <token de sesión>`.
Responde `Access-Control-Allow-Origin: *`, así que un navegador puede llamarlo, pero
sin credenciales.

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
| `401 authentication_required` | El documento es privado y no hay sesión válida. Se devuelve **incluso cuando el header traía una API key**: una integración lee sus propios documentos por `GET /api/v1/issuer/documents`. |
| `404 document_not_found` | Id inexistente, malformado, o de otro workspace. No distingue entre los tres casos, a propósito. |

Ver [4. Visibilidad y entrega](04-visibilidad-y-entrega.md#leer-un-documento-la-zona-pública).

---

## Lo que no existe

Vale la pena listarlo, porque cada ausencia es deliberada y cambia el diseño de la
integración:

| No hay | Por qué / qué usar en cambio |
|---|---|
| **Endpoint de verificación** | Un veredicto habría que creerlo. La verificación corre del lado de quien verifica, contra la cadena → [6. Verificación](06-verificacion.md). |
| **Endpoints de layout** | La geometría de la hoja se define en el dashboard, con sesión. El **esquema** sí se crea por API → [arriba](#esquemas-de-kind-document). |
| **Búsqueda ni filtros** | `?digest=`, `/by-digest/` y `/search` no existen; los query params se ignoran. Indexá del lado tuyo a partir del listado. |
| **Paginación** | El listado devuelve todo. |
| **Endpoint de revocación** | `revoked_at` y `status: "revoked"` existen, pero **ningún endpoint los setea**: la baja se gestiona desde el dashboard → [guía 3](03-emision-de-documentos.md#revocación). |
| **Un `GET` de un documento propio por la zona emisor** | El listado es el único camino del lado emisor; para uno solo, la zona pública. |
| **Reintento del anclaje** | Un documento `failed` no se puede reanclar: hay que volver a emitirlo. |

## Límites

| Recurso | Límite |
|---|---|
| Latencia de la emisión | la respuesta no espera a la cadena: vuelve `signed` y el anclaje sigue en segundo plano |
| Cuerpo de un request | 8 MB |
| Intentos de anclaje de un documento | 5 |
| Throughput del anclaje | del orden de 5 documentos por bloque y por nodo |
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
              "version": 1, "claims": [ /* … */ ] }
}
```

Ese ejemplo es el de un documento **ya anclado**. Recién emitido, los mismos campos
vienen con `status: "signed"`, `tx_hash: null` y `anchored_at: null`.

`status`: `signed`, `anchored`, `failed` o `revoked`. **Solo `anchored` es un estado
entregable.** El `id` coincide con el claim `jti` de adentro del `credential`, y el
`digest` —el SHA-256 del signing input— es la clave bajo la cual la firma queda
anclada. El significado de cada campo, y si está o no cubierto por la firma, en
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
`credential`, **que es lo único que se sostiene por sí mismo**: el resto es contexto
para mostrar, no para confiar. Ver
[las diferencias](04-visibilidad-y-entrega.md#diferencias-con-la-respuesta-de-emisión).

### Schema

Lo que devuelven los endpoints de esquemas. Los campos relevantes para documentos:

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | `uuid` | El id de **esta fila de versión**. Es el que toma `DELETE /schemas/{id}`. |
| `schema_id` | `string` | Tu identificador estable, el que se manda al emitir. |
| `credential_type` | `string` | Viaja como `vct` dentro del JWT firmado. |
| `name` | `string` | Nombre para mostrar. |
| `claims` | `array` | Ver [Claim definition](#claim-definition). |
| `validity_period` | `string` \| `null` | `1y`, `6m`, `30d`; `null` si no vence. |
| `version` | `integer` | Arranca en 1. **Ausente en la respuesta de creación.** |
| `kind` | `string` | `document` para estos esquemas. |
| `format` | `string` | `vc+sd-jwt`. **Inmutable de por vida.** |
| `registration_status` | `string` | `pending`, `registered` o `failed`. **Solo con `registered` se puede emitir.** |
| `registration_error` | `string` \| `null` | Por qué falló el registro. |
| `layout` | `object` \| `null` | **Solo en el listado:** el layout de la hoja, definido en el dashboard. |

La tabla completa está en
[`../credentials/07-referencia-api.md#schema`](../credentials/07-referencia-api.md#schema).

### Claim definition

Cada entrada de `schema.claims[]`:

| Campo | Tipo | Descripción |
|---|---|---|
| `key` | `string` | Nombre del campo dentro de `claims`. |
| `label` | `string` | Etiqueta legible, para UI y layout. |
| `type` | `string` | `date`, `number`, `string` o `boolean` para un documento. En `vc+sd-jwt` el tipo es indicativo: no se rechaza un valor por no coincidir. |
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

> La colección **todavía no incluye los endpoints de esquemas**: para crear el esquema
> por API, copiá el `curl` de [más arriba](#crear-el-esquema).

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

> ⚠️ El test de emisión verifica el `201`, **no que el documento esté anclado**.
> Recién emitido va a estar en `status: "signed"`: para verlo `anchored`, volvé a
> correr el listado unos segundos después.

---

**Anterior:** [← 6. Verificación](06-verificacion.md) · **Siguiente:** [8. Errores y troubleshooting →](08-errores-y-troubleshooting.md)
