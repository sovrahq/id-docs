# 8. Referencia de la API

Referencia completa de la API servidor-a-servidor de Sovra ID (`/api/v1`): emisión de
credenciales y de documentos firmados, administración de esquemas y de su diseño en la
wallet, y verificaciones OID4VP.

Queda afuera la API del dashboard (`/dashboard/*`, con sesión) y la de la wallet
(`/wallet/*`).

**Buena parte de los resultados no llega en la respuesta, sino por webhook:** el
anclaje de un documento, la emisión o el cambio de estado de una credencial, el
desenlace de una verificación. Los eventos están en [6. Webhooks](06-webhooks.md).

## Tabla de contenidos

1. [Convenciones](#convenciones)
2. [Autenticación](#autenticación)
3. [Índice de endpoints](#índice-de-endpoints)
4. [Esquemas](#esquemas)
5. [Diseño de la credencial](#diseño-de-la-credencial)
6. [Emisor](#emisor)
7. [Verificador](#verificador)
8. [Endpoints públicos](#endpoints-públicos)
9. [Documentos firmados](#documentos-firmados)
10. [Límites y cuotas](#límites-y-cuotas)
11. [Objetos comunes](#objetos-comunes)

---

## Convenciones

**Base URL**

| Entorno | URL |
|---|---|
| Test | `https://test-api-sovra.flagonsa.com` |
| Producción | `https://api.sovra.io` |

Los ejemplos usan `$BASE_URL` y `$SOVRA_API_KEY`.

**Formato.** Todo es JSON (`Content-Type: application/json`), salvo una excepción que
se indica explícitamente: la lista de estado (`application/jwt`). El cuerpo tiene un
techo de **8 MB**; por encima, `413`.

**La cabecera `Accept` importa.** Toda ruta JSON exige un `Accept` que admita
`application/json` — `*/*`, el default de curl, lo admite. Cualquier otra cosa es un
`406` **antes de llegar al endpoint**. Eso incluye `GET /api/v1/status/…` con
`Accept: application/jwt`, aunque esa ruta responda con un JWT.

**Fechas.** ISO 8601 en UTC (`2026-08-26T04:41:32Z`). Dentro del SD-JWT, `iat` y
`exp` son segundos Unix.

**Identificadores.** UUID, salvo `schema_id`, que es el slug del esquema.

**`x-request-id`.** Toda respuesta la trae. Citala cuando reportes un problema.

**Errores.** Los errores del endpoint comparten un sobre:

```json
{ "error": "schema_not_found", "details": "…" }
```

`error` es un código estable, pensado para que lo lea tu código. `details` es
opcional, y según el error es un string, una lista de strings o un objeto campo →
mensajes. Las fallas de validación usan `validation_error` con mensajes por campo:

```json
{ "error": "validation_error", "details": { "validity_period": ["has invalid format"] } }
```

**Los errores del framework usan otro sobre**, porque se producen antes de que el
endpoint corra:

```json
{ "errors": { "detail": "Bad Request" } }
```

| Código | Causa |
|---|---|
| `400` | Cuerpo JSON malformado, o un id malformado que el endpoint pasa directo a la base (se indica en cada endpoint). |
| `404` | Ninguna ruta coincide. |
| `406` | `Accept` no admite el formato de la ruta. |
| `413` | Cuerpo de más de 8 MB. |
| `500` | Error no manejado (`Internal Server Error`). |

**Rate limit.** Solo lo tiene `POST /api/v1/issuer/credential-offer`: 120 solicitudes
por minuto **por IP de origen**, contadas por separado en cada nodo de la API. Por
encima responde `429` con `{"error": "too_many_requests"}`. **No se envía
`Retry-After` ni cabeceras `X-RateLimit-*`**: esperá y reintentá.

**Paginación.** Solo pagina `GET /api/v1/verifier/verifications` (`limit` / `offset`).
Los demás listados devuelven todo.

**Operaciones asincrónicas.** Cuatro escrituras devuelven antes de que su desenlace
sea definitivo. La respuesta confirma que la solicitud se aceptó; el webhook —o el
`GET` correspondiente— dice cómo terminó.

| Operación | Respuesta inmediata | El estado final llega como |
|---|---|---|
| Emitir un documento | `201`, `status: "signed"` | `document.status-changed` → `anchored` o `failed` |
| Cambiar el estado de una credencial | `202`, el destino en `pending_status` | `credential.revoked` / `.suspended` / `.unsuspended`, o `credential.status-change-failed` |
| Crear una oferta de credencial | `201` con la oferta | `credential.issued` cuando el holder la canjea |
| Crear una verificación | `201` con la URI de la solicitud | `presentation.verified` o `presentation.failed` |

**Especificación OpenAPI en vivo:** `GET {baseUrl}/openapi/api`.

## Autenticación

Todos los endpoints, salvo los [públicos](#endpoints-públicos), requieren una **API
key de workspace**:

```http
Authorization: Bearer sovra_sk_...
```

El esquema `Bearer` es sensible a mayúsculas. El workspace se deduce de la clave: no
se envía `workspace_id` en ningún request.

**Una credencial, un documento, un esquema o una verificación de otro workspace
responden `404`, como si no existieran** — nunca `403`. La API no revela su
existencia.

Después de validar la clave, el request se rechaza si su workspace, o la organización
de ese workspace, están desactivados. **Desactivar un tenant no revoca sus claves**:
este control es lo que las detiene.

| Código | `error` | Cuándo |
|---|---|---|
| `401` | `invalid_api_key` | Sin cabecera `Authorization`, malformada, o clave desconocida o revocada |
| `403` | `workspace_inactive` | El workspace de la clave está desactivado |
| `403` | `organization_inactive` | La organización del workspace está desactivada |

Cualquier endpoint con API key puede responder estos tres; las tablas de cada endpoint
no los repiten.

Las claves se crean en el dashboard (**Settings → API keys**) y se muestran una sola
vez.

## Índice de endpoints

| Método | Ruta | Auth | Para qué |
|---|---|---|---|
| `POST` | [`/api/v1/issuer/schemas`](#post-apiv1issuerschemas) | API key | Crear un esquema |
| `GET` | [`/api/v1/issuer/schemas`](#get-apiv1issuerschemas) | API key | Listar los esquemas, versión por versión |
| `PUT` | [`/api/v1/issuer/schemas/{schema_id}`](#put-apiv1issuerschemasschema_id) | API key | Revisar un esquema (nueva versión) |
| `DELETE` | [`/api/v1/issuer/schemas/{id}`](#delete-apiv1issuerschemasid) | API key | Borrar un esquema y todas sus versiones |
| `PUT` | [`/api/v1/issuer/schemas/{schema_id}/design`](#put-apiv1issuerschemasschema_iddesign) | API key | Crear o reemplazar el diseño en la wallet |
| `POST` | [`/api/v1/issuer/credential-offer`](#post-apiv1issuercredential-offer) | API key | Crear una oferta para una wallet |
| `GET` | [`/api/v1/issuer/credentials`](#get-apiv1issuercredentials) | API key | Listar las credenciales emitidas |
| `GET` | [`/api/v1/issuer/credentials/{id}`](#get-apiv1issuercredentialsid) | API key | Una credencial, canjeada o no |
| `PUT` | [`/api/v1/issuer/credentials/{id}/status/{status}`](#put-apiv1issuercredentialsidstatusstatus) | API key | Revocar, suspender o reactivar |
| `POST` | [`/api/v1/issuer/documents`](#documentos-firmados) | API key | Firmar un documento y anclarlo |
| `GET` | [`/api/v1/issuer/documents`](#documentos-firmados) | API key | Listar los documentos firmados |
| `PUT` | [`/api/v1/issuer/documents/{id}/visibility/{visibility}`](#documentos-firmados) | API key | Publicar o despublicar un documento |
| `POST` | [`/api/v1/verifier/verifications`](#post-apiv1verifierverifications) | API key | Abrir una verificación OID4VP |
| `GET` | [`/api/v1/verifier/verifications`](#get-apiv1verifierverifications) | API key | Listar las verificaciones |
| `GET` | [`/api/v1/verifier/verifications/{id}`](#get-apiv1verifierverificationsid) | API key | Una verificación |
| `GET` | [`/api/v1/status/{issuer_did}/{purpose}`](#get-apiv1statusissuer_didpurpose) | — | La Bitstring Status List del emisor |
| `GET` | [`/api/v1/documents/{id}`](#get-apiv1documentsid) | —¹ | Leer un documento firmado |

¹ Un documento `private` además exige una sesión del dashboard (ver el endpoint).

---

## Esquemas

> 📐 Esta sección es la referencia endpoint por endpoint. El recorrido explicado
> —los dos caminos, los claims, el registro on-chain, el diseño— está en
> [3. Crear un esquema](03-crear-un-esquema.md).

Un esquema define un tipo de credencial: sus claims, su formato de serialización y
cuánto vale lo que se emite con él. **Tiene dos identificadores**, y la confusión
entre los dos es la fuente de error más común:

- **`schema_id`** es tu string, por ejemplo `licencia_de_conducir`. Es estable entre
  versiones y es el que toman la emisión y el diseño.
- **`id`** es el UUID de **una fila de versión**. Solo lo toma
  `DELETE /schemas/{id}`.

Un esquema nuevo nace en `registration_status: "pending"` mientras su tipo de
credencial se registra on-chain en segundo plano; después pasa a `registered`, o a
`failed` si esa escritura agota sus reintentos.

> 🔴 **No se puede emitir nada con un esquema que no esté `registered`.** Emitir con
> uno `pending` responde `409 schema_registration_pending`. **No hay webhook de
> registro**: consultá `GET /schemas`.

### Definición de un claim

`claims` es un array de definiciones de claim. También se acepta `{"claims": [...]}`.

| Campo | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `key` | string | ✅ | El nombre del claim en la credencial y en los mapas `claims`. |
| `type` | string | ✅ | `string`, `uri`, `integer`, `boolean`, `number`, `date`, `datetime`, `bytes`, `driving_privileges` o `attachment`. En `vc+sd-jwt` es indicativo, salvo `attachment`; en `mso_mdoc` **se exige** al codificar. |
| `required` | boolean | — | Si la clave falta al emitir → `missing_claims`. |
| `disclosable` | boolean | — | SD-JWT: el holder puede retenerlo al presentar. |
| `label` | string | — | Etiqueta legible. |

Un claim `attachment` transporta un PDF como URI
`data:application/pdf;base64,…` de **hasta 560.000 bytes**. Solo se admite en
esquemas de credencial `vc+sd-jwt`, **tiene que ser `disclosable`**, y un esquema
puede tener **uno solo**.

### `POST /api/v1/issuer/schemas`

Crea la **versión 1** del esquema y encola su registro on-chain.

**Body**

| Campo | Tipo | Obligatorio | Restricciones | Descripción |
|---|---|---|---|---|
| `schema_id` | string | ✅ | Único en el workspace. Para `mso_mdoc`, `^[a-z0-9][a-z0-9_-]*$` | Tu identificador estable. |
| `credential_type` | string | ✅ | | El `vct` del SD-JWT y el id del tipo on-chain. |
| `name` | string | ✅ | | Nombre para mostrar. |
| `claims` | array | ✅ | Ver [definición de un claim](#definición-de-un-claim) | Los claims. |
| `validity_period` | string \| null | ✅ **la clave tiene que estar** | `^[1-9]\d*(y\|m\|d)$`, o `null` | `1y`, `6m`, `30d`. Con `null` lo emitido no vence. **Omitir la clave es un `422`.** |
| `kind` | string | — | `credential` (default) o `document` | Los de kind `document` alimentan `POST /documents`. |
| `format` | string | — | `vc+sd-jwt` (default) o `mso_mdoc`; `mso_mdoc` solo con `kind: credential` | **Inmutable de por vida.** |
| `doc_type` | string | — | Solo `mso_mdoc`; DNS inverso, p. ej. `org.iso.18013.5.1.mDL` | Emitir bajo un docType propio. Exige un document signer registrado. |
| `namespace` | string | — | Solo `mso_mdoc`; DNS inverso | El namespace de los elementos, cuando difiere del docType. |

```bash
curl -s -X POST "$BASE_URL/api/v1/issuer/schemas" \
  -H "Authorization: Bearer $SOVRA_API_KEY" -H 'Content-Type: application/json' \
  -d '{
    "schema_id": "licencia_de_conducir",
    "credential_type": "DrivingLicence",
    "name": "Licencia de Conducir",
    "validity_period": "1y",
    "claims": [
      {"key": "full_name", "type": "string", "required": true, "disclosable": true, "label": "Nombre"},
      {"key": "licence_number", "type": "string", "required": true, "disclosable": true}
    ]
  }'
```

**`201 Created`** — el [objeto Schema](#schema), **sin `version` ni `layout`**:

```json
{
  "id": "0b9f2a64-3c1e-4d8a-9f27-5e6a1c3d7b90",
  "schema_id": "licencia_de_conducir",
  "credential_type": "DrivingLicence",
  "name": "Licencia de Conducir",
  "claims": [
    {"key": "full_name", "type": "string", "required": true, "disclosable": true, "label": "Nombre"},
    {"key": "licence_number", "type": "string", "required": true, "disclosable": true}
  ],
  "validity_period": "1y",
  "kind": "credential",
  "format": "vc+sd-jwt",
  "registration_status": "pending",
  "registration_error": null,
  "doc_type": null,
  "namespace": null
}
```

**Errores**

| Respuesta | Causa |
|---|---|
| `409 workspace_not_provisioned` | El DID y las claves del workspace todavía no están listos. |
| `409 document_signer_required` | Hay `doc_type`, pero el workspace no tiene document signer registrado. |
| `422 validation_error` | Campo faltante o inválido; `details` lo nombra. **Un `schema_id` duplicado se reporta sobre `issuer_id`** como `has already been taken`. |
| `500 internal_error` | Falla inesperada. |

---

### `GET /api/v1/issuer/schemas`

Todos los esquemas del workspace, **una entrada por versión**, del más viejo al más
nuevo. La emisión usa siempre la versión más alta.

```bash
curl -s "$BASE_URL/api/v1/issuer/schemas" -H "Authorization: Bearer $SOVRA_API_KEY"
```

**`200 OK`**

```json
{
  "schemas": [
    {
      "id": "0b9f2a64-3c1e-4d8a-9f27-5e6a1c3d7b90",
      "schema_id": "licencia_de_conducir",
      "credential_type": "DrivingLicence",
      "name": "Licencia de Conducir",
      "claims": [{"key": "full_name", "type": "string", "required": true, "disclosable": true}],
      "validity_period": "1y",
      "version": 1,
      "kind": "credential",
      "format": "vc+sd-jwt",
      "registration_status": "registered",
      "registration_error": null,
      "doc_type": null,
      "namespace": null,
      "layout": null
    }
  ]
}
```

Es el único lugar donde se ve el `registration_status`, así que **es el endpoint que
hay que consultar después de crear un esquema**, antes de intentar emitir.

---

### `PUT /api/v1/issuer/schemas/{schema_id}`

Agrega una **versión nueva**. Las anteriores, y todo lo emitido con ellas, quedan como
están. La versión nueva hereda el diseño, el layout y el estado de registro de la
anterior.

`schema_id`, `credential_type`, `kind`, `format`, `doc_type` y `namespace` **no se
pueden cambiar**: si van en el body, se ignoran.

**Parámetros de ruta:** `schema_id` — tu string, no el UUID.

**Body**

| Campo | Tipo | Obligatorio | Restricciones | Descripción |
|---|---|---|---|---|
| `name` | string | ✅ | | Nombre para mostrar. |
| `claims` | array | ✅ | Ver [definición de un claim](#definición-de-un-claim) | **La lista completa** de claims nueva, no un parche. |
| `validity_period` | string \| null | ✅ la clave tiene que estar | Igual que al crear | |
| `base_version` | integer | ✅ | ≥ 1 | La versión desde la que empezaste a editar. **Tiene que seguir siendo la última.** |

```bash
curl -s -X PUT "$BASE_URL/api/v1/issuer/schemas/licencia_de_conducir" \
  -H "Authorization: Bearer $SOVRA_API_KEY" -H 'Content-Type: application/json' \
  -d '{
    "name": "Licencia de Conducir",
    "base_version": 1,
    "validity_period": "2y",
    "claims": [
      {"key": "full_name", "type": "string", "required": true, "disclosable": true},
      {"key": "licence_number", "type": "string", "required": true, "disclosable": true},
      {"key": "categoria", "type": "string", "required": false, "disclosable": true}
    ]
  }'
```

**`201 Created`** — el mismo objeto que al crear, más `"version": 2`.

**Errores**

| Respuesta | Causa |
|---|---|
| `404 schema_not_found` | No hay esquema con ese `schema_id`. |
| `409 schema_revision_conflict` | Existe una versión más nueva que `base_version`. Volvé a leer y reintentá. |
| `422 validation_error` | Campo inválido. Un `base_version` que no sea un entero ≥ 1 se reporta como `{"base_version": ["must be a positive integer"]}`. |

`base_version` es control de concurrencia optimista: dos personas editando el mismo
esquema desde el dashboard y desde la API no se pisan, la segunda recibe el `409`.

---

### `DELETE /api/v1/issuer/schemas/{id}`

Borra **todas las versiones** del esquema al que pertenece la fila indicada, de forma
atómica. Se rechaza si cualquiera de sus versiones tiene una oferta o una credencial
emitida, **sea cual sea su estado**.

**Parámetros de ruta:** `id` — el **UUID** de cualquier fila de versión (el `id` del
objeto Schema), no el slug.

```bash
curl -s -X DELETE "$BASE_URL/api/v1/issuer/schemas/0b9f2a64-3c1e-4d8a-9f27-5e6a1c3d7b90" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

**`204 No Content`** — cuerpo vacío.

**Errores**

| Respuesta | Causa |
|---|---|
| `404 schema_not_found` | Id inexistente, de otro workspace, o que no es un UUID. |
| `409 schema_in_use` | Alguna versión tiene ofertas o credenciales emitidas. |

---

## Diseño de la credencial

Cómo se dibuja la credencial en la wallet. Lo que se guarda acá termina siendo el
`output_descriptor` del Credential Manifest de DIF, que es lo que la wallet lee.

El endpoint actúa **siempre sobre la última versión** del esquema, direccionada por su
`schema_id` de string. Leerlo y borrarlo se hace desde el dashboard.

### `PUT /api/v1/issuer/schemas/{schema_id}/design`

Crea el diseño, o lo reemplaza en bloque. **No es un parche:** lo que no mandás, no
queda.

**Body**

| Campo | Tipo | Obligatorio | Restricciones | Descripción |
|---|---|---|---|---|
| `name` | string | ✅ | | Nombre para mostrar. |
| `description` | string | — | | |
| `display` | object | ✅ al crear | Mappings (ver abajo) | La cara de la tarjeta. |
| `styles` | object | ✅ al crear | `thumbnail.uri` y `hero.uri` ≤ 256 KB, `uri` obligatoria; `background.color` y `text.color` en `#rrggbb` | El aspecto. |
| `back` | object | — | `{display, styles}`, mismas reglas | El dorso de la tarjeta. |

Un **mapping** es `{text, path, schema, fallback, label}`. **Tiene que tener
exactamente uno** de `text` (un literal) y `path` (`["$.<claim_key>"]`, nombrando un
claim que el esquema declare). Las respuestas incluyen todos los campos, con `null`
donde no hay valor.

`display` acepta `title`, `subtitle` y `description` —un mapping cada uno— y
`properties`, un array de mappings.

```bash
curl -s -X PUT "$BASE_URL/api/v1/issuer/schemas/licencia_de_conducir/design" \
  -H "Authorization: Bearer $SOVRA_API_KEY" -H 'Content-Type: application/json' \
  -d '{
    "name": "Licencia de Conducir",
    "description": "La licencia que lleva el ciudadano",
    "display": {
      "title": {"text": "Licencia de Conducir"},
      "subtitle": {"path": ["$.full_name"], "fallback": "Titular"},
      "properties": [{"label": "N° de licencia", "path": ["$.licence_number"]}]
    },
    "styles": {
      "background": {"color": "#112233"},
      "text": {"color": "#ffffff"}
    }
  }'
```

**`200 OK`** — el [objeto Design](#design). **Crearlo también responde `200`**, no
`201`:

```json
{
  "id": "6d1c0e3a-9b2f-4f57-8e41-0a7c2b9d5e13",
  "credential_schema_id": "0b9f2a64-3c1e-4d8a-9f27-5e6a1c3d7b90",
  "name": "Licencia de Conducir",
  "description": "La licencia que lleva el ciudadano",
  "display": {
    "title": {"text": "Licencia de Conducir", "path": null, "schema": null, "fallback": null, "label": null},
    "subtitle": {"text": null, "path": ["$.full_name"], "schema": null, "fallback": "Titular", "label": null},
    "description": null,
    "properties": [{"text": null, "path": ["$.licence_number"], "schema": null, "fallback": null, "label": "N° de licencia"}]
  },
  "styles": {"thumbnail": null, "hero": null, "background": {"color": "#112233"}, "text": {"color": "#ffffff"}},
  "back": null,
  "version": 1
}
```

**Errores**

| Respuesta | Causa |
|---|---|
| `404 schema_not_found` | No hay esquema con ese `schema_id`. |
| `422 validation_error` | Un mapping con los dos o con ninguno de `text` y `path`; un `path` que no tenga la forma `$.<claim_key>` o que nombre un claim inexistente; un color mal formado; una imagen de más de 256 KB; `display` o `styles` faltantes al crear. |

---

## Emisor

Una credencial se emite a la wallet del holder por OID4VCI: creás una **oferta**, el
holder escanea su `offer_uri` y la wallet la canjea **dentro de 24 horas**. El
`credential_id` de la oferta es el id de la credencial para toda su vida, antes y
después del canje.

> 🔴 **La credencial firmada se entrega una sola vez**, en el webhook
> `credential.issued`, y **Sovra no la guarda**.

### `POST /api/v1/issuer/credential-offer`

Crea una oferta OID4VCI pre-autorizada. Ver [4. Emisión](04-emision-de-credenciales.md).

**Rate limit:** 120 solicitudes por minuto por IP de origen.

**Body**

| Campo | Tipo | Obligatorio | Restricciones | Descripción |
|---|---|---|---|---|
| `schema_id` | string | ✅ | Un esquema de `kind: credential` en estado `registered` | El slug; se usa su última versión. **Omitirlo es un `500`.** |
| `claims` | object | — | Todos los claims `required` presentes. **Tiene que ser un objeto**, o el request es un `500` | Valores `key` → valor. En `mso_mdoc` tienen que respetar el tipo declarado. |
| `valid_until` | string | — | ISO 8601, a futuro. **`null` se rechaza** | Vencimiento absoluto, que pisa el `validity_period` del esquema. Omitilo para usar el del esquema. |

**Respuesta `201`**

| Campo | Tipo | Descripción |
|---|---|---|
| `credential_id` | uuid | El id de la credencial de acá en adelante. |
| `offer_uri` | string | URI `openid-credential-offer://…` para el QR. |
| `pre_authorized_code` | string | El código que va dentro de `offer_uri`. Del lado servidor **solo se guarda su hash**. |
| `expires_at` | string | Cuándo deja de poder canjearse: 24 h. |
| `credential` | object | Vista previa **sin firmar** del payload: sin holder y sin prueba. Los adjuntos se describen, no se devuelven. |

**Errores**

| Respuesta | Causa |
|---|---|
| `404 schema_not_found` | No hay esquema con ese `schema_id`. |
| `409 workspace_not_provisioned` | El workspace todavía no está listo. |
| `409 schema_registration_pending` | El tipo de credencial se sigue registrando on-chain. Reintentá en unos segundos. |
| `409 schema_registration_failed` | El tipo no pudo registrarse: con ese esquema no se puede emitir nada. |
| `422 invalid_valid_until` | `valid_until` es `null`, está malformado o quedó en el pasado. |
| `422 wrong_schema_kind` | El esquema es de kind `document`. |
| `422 document_signer_required` | El esquema declara su propio docType y el workspace no tiene document signer registrado. |
| `422 missing_claims` | Faltan claims requeridos. `details` lista las claves. |
| `422 invalid_claim_values` | Un adjunto no es un data URI de PDF válido, o es demasiado grande. `details` lista `"<key>: <motivo>"`. |
| `422 unencodable_claims` | `mso_mdoc`: valores que no se pueden codificar en CBOR (números no enteros, fechas que no son ISO). |
| `422 bitmap_exhausted` | La lista de estado del workspace está llena. |
| `429 too_many_requests` | Rate limit. |

---

### `GET /api/v1/issuer/credentials`

Lista las credenciales **efectivamente canjeadas**, de la más nueva a la más vieja.
Las ofertas sin canjear **no aparecen**: se consultan por id.

**Respuesta `200`**

```json
{
  "credentials": [
    {
      "id": "b662aaad-d940-48fb-be9e-7194c76210ff",
      "holder_did": "did:sovra:0x9083…",
      "status": "issued",
      "pending_status": null,
      "status_change_error": null,
      "issued_at": "2026-08-26T04:41:32Z",
      "expires_at": "2027-08-26T04:41:32Z",
      "schema": {
        "id": "…",
        "schema_id": "licencia_de_conducir",
        "credential_type": "DrivingLicence",
        "name": "Licencia de Conducir"
      }
    }
  ]
}
```

---

### `GET /api/v1/issuer/credentials/{id}`

Una credencial, por el `credential_id` de su oferta. Funciona desde que la oferta se
crea.

**Parámetros de ruta:** `id` — el `credential_id`.

**Respuesta `200`** — [objeto Credential](#credential). Antes del canje, `status` es
`pending` —o `expired`, pasada la ventana de 24 h—, con `holder_did` e `issued_at` en
`null`.

**Errores:** `404 credential_not_found` — id inexistente, de otro workspace, o que no
es un UUID.

> El SD-JWT firmado **no** se devuelve acá. Solo llega en el webhook
> `credential.issued`.

---

### `PUT /api/v1/issuer/credentials/{id}/status/{status}`

**Pide** un cambio de estado. El cambio se escribe en la lista de estado on-chain en
segundo plano, así que la respuesta es **`202`**: `status` todavía no cambió y el
destino va en `pending_status`. Ver [7. Ciclo de vida](07-ciclo-de-vida-y-revocacion.md).

Cuando la transacción confirma, `status` se mueve y se envía `credential.revoked`,
`credential.suspended` o `credential.unsuspended`. Si nunca confirma, se envía
`credential.status-change-failed`, `pending_status` se limpia y `status_change_error`
dice por qué. **Un cambio a la vez por credencial.**

**Parámetros de ruta**

| Parámetro | Valores |
|---|---|
| `id` | El `credential_id`. |
| `status` | `revoked` (permanente), `suspended` (reversible), `issued` (levantar una suspensión). |

Transiciones admitidas: `issued → revoked`, `issued → suspended`,
`suspended → revoked`, `suspended → issued`.

**Respuesta `202`**

```json
{ "id": "b662aaad-d940-48fb-be9e-7194c76210ff", "status": "issued", "pending_status": "revoked" }
```

> ⚠️ **Hasta que llegue el webhook, los verificadores siguen viendo el estado
> anterior**, porque leen la lista on-chain. Un `202` no es una revocación efectiva.

**Errores**

| Respuesta | Causa |
|---|---|
| `400 unknown_status` | `{status}` no es ninguno de los tres valores. |
| `404 credential_not_found` | Id inexistente, de otro workspace, o que no es un UUID. **Se controla antes que `{status}`.** |
| `409 workspace_not_provisioned` | El workspace todavía no está listo. |
| `409 status_change_pending` | Otro cambio de esta credencial no confirmó todavía. |
| `422 credential_not_yet_issued` | El id es una oferta que el holder no canjeó. |
| `422 invalid_status_transition` | Cualquier cambio desde `revoked`, o suspender una credencial ya suspendida. |

---

## Verificador

Una verificación es una sesión OID4VP: describís lo que querés en una consulta
**DCQL**, mostrás la URI que devuelve como QR, y la wallet del holder presenta contra
Sovra, que verifica la presentación. **La sesión vive 10 minutos.** El resultado llega
como `presentation.verified` o `presentation.failed`, o consultando
`GET /verifications/{id}`.

### `POST /api/v1/verifier/verifications`

Ver [5. Verificación](05-verificacion-de-credenciales.md).

**Body**

| Campo | Tipo | Obligatorio | Restricciones |
|---|---|---|---|
| `dcql_query` | object | ✅ | `credentials` es un array no vacío. Cada entrada trae `format` `dc+sd-jwt`, `vc+sd-jwt` o `mso_mdoc`, y **todas comparten familia** (SD-JWT o mdoc). El `path` de un claim `mso_mdoc` es exactamente `[namespace, element_identifier]`. |

**Respuesta `201`**

| Campo | Tipo | Descripción |
|---|---|---|
| `session_id` | uuid | Es el `verification_id` de los webhooks. |
| `authorization_request_uri` | string | URI `openid4vp://…` autocontenida, con la solicitud firmada adentro (paso por valor). |
| `authorization_request_uri_ref` | string | URI por referencia. Es más corta, así que el QR queda menos denso: **usá esta**. |
| `status` | string | Siempre `pending`. |
| `expires_at` | string | Vencimiento: 10 minutos. |

**Errores**

| Respuesta | Causa |
|---|---|
| `400 missing_dcql_query` | No vino `dcql_query`. |
| `400 invalid_dcql_query` | No es un objeto, o no trae un array `credentials` no vacío. |
| `400 unsupported_dcql_format` | El `format` de una entrada falta o no está soportado. |
| `400 mixed_dcql_formats` | SD-JWT y mdoc en la misma consulta. Consultalos por separado. |
| `400 invalid_mdoc_claim_path` | Un `path` de claim `mso_mdoc` no es `[namespace, element_identifier]`. |
| `409 workspace_not_provisioned` | El workspace todavía no está listo. |
| `422 validation_error` | La sesión no pudo guardarse. |
| `502 signer_unavailable` | El objeto de la solicitud no pudo firmarse. Reintentá. |

---

### `GET /api/v1/verifier/verifications`

Lista las sesiones del workspace, de la más nueva a la más vieja. **Es el único
listado que pagina.**

**Query params**

| Parámetro | Tipo | Default | Descripción |
|---|---|---|---|
| `status` | string | todos | `pending`, `completed`, `failed`, `expired`. **Cualquier otro valor se ignora** y se devuelven todos. |
| `limit` | integer | 50 | Se acota a 1–200. Un valor no entero se ignora. |
| `offset` | integer | 0 | Un negativo cuenta como 0. |

**Respuesta `200`**

```json
{ "sessions": [ /* objetos Session */ ], "limit": 50, "offset": 0 }
```

`limit` y `offset` **devuelven lo que mandaste, antes de acotarlo**: si pediste
`limit=5000` vas a leer `5000` en la respuesta y 200 sesiones en el array.

---

### `GET /api/v1/verifier/verifications/{id}`

**Respuesta `200`** — [objeto Session](#session).

**Errores**

| Respuesta | Causa |
|---|---|
| `400` (framework) | `{id}` no es un UUID: `{"errors": {"detail": "Bad Request"}}`. |
| `404 session_not_found` | Id inexistente o de otro workspace. |

> Las sesiones de otro workspace responden `404`, no `403`: la API no revela su
> existencia.

---

## Endpoints públicos

Sin API key. Sirven lo que un verificador de terceros necesita para comprobar una
credencial o un documento **sin confiar en Sovra**.

### `GET /api/v1/status/{issuer_did}/{purpose}`

La Bitstring Status List firmada del emisor, un JWS ES256 compacto. La URL
`statusListCredential` que llevan adentro las credenciales emitidas apunta acá: **la
ruta no puede cambiar.**

**Parámetros:** `issuer_did` (`did:sovra:0x…`), `purpose` (`revocation` | `suspension`).

Mandá `Accept: */*` o `application/json`. **`Accept: application/jwt` a secas es un
`406`**, aunque la respuesta sea un JWT.

| Respuesta | Cuerpo | Cuándo |
|---|---|---|
| `200` | El JWS, `Content-Type: application/jwt` | Con `ETag` y `Cache-Control: public, max-age=300` |
| `304` | Vacío | `If-None-Match` coincide con el `ETag` actual |
| `400` | `{"error": "invalid_purpose"}` | No es `revocation` ni `suspension` |
| `404` | `{"error": "issuer_not_found"}` | No hay workspace con ese DID |
| `500` | `{"error": "<motivo>"}` | El bitmap on-chain no pudo leerse, o la lista no pudo firmarse |

```bash
curl -i "$BASE_URL/api/v1/status/did:sovra:0xcda79e1ee5612c230a6faf68236e3679b0579bae/revocation"
```

---

### `GET /api/v1/documents/{id}`

Devuelve la credencial de un documento firmado, para quien tenga su id. **Sirve la
credencial, nunca un veredicto:** la firma y el ancla on-chain los comprueba quien
lee. Responde `Access-Control-Allow-Origin: *`, así que un navegador puede llamarlo,
pero sin credenciales.

Un documento `public` no necesita nada más que el id. Uno `private` **además** exige
una sesión de usuario del dashboard: la cookie `document_session`, o
`Authorization: Bearer <token de sesión>`.

> ⚠️ **Una API key de workspace no abre un documento privado acá.** Una integración
> lee sus propios documentos por `GET /api/v1/issuer/documents`.

| Respuesta | Cuerpo |
|---|---|
| `200` | `id`, `credential`, `issuer`, `schema` (`{name, claims}`), `layout`, `anchor` (`{status, tx_hash, anchored_at}`) |
| `401` | `{"error": "authentication_required"}` — el documento es privado y no hay sesión válida |
| `404` | `{"error": "document_not_found"}` — id inexistente, o que no es un UUID |

De todo eso, **lo único que se sostiene por sí mismo es `credential`**: el resto es
contexto que sirve para mostrar, no para confiar. Ver
[`../documents/`](../documents/).

---

## Documentos firmados

> 📑 **Resumen.** La documentación completa de documentos firmados —emisión,
> visibilidad, renderizado, verificación, errores— está en
> [`../documents/`](../documents/), y la referencia endpoint por endpoint en
> [`../documents/08-referencia-api.md`](../documents/08-referencia-api.md).

Un **documento** es una variante sin wallet: el emisor firma los datos directamente,
sin holder y sin divulgación selectiva, y ancla la firma on-chain. Sirve para
certificados, constancias y actas — cosas que se comparten por enlace, no desde una
wallet.

Requiere un esquema de kind `document`, que se crea con
[`POST /api/v1/issuer/schemas`](#post-apiv1issuerschemas) igual que cualquier otro.
Los endpoints viven en la misma superficie de API key:

| Método | Ruta | Descripción |
|---|---|---|
| `POST` | `/api/v1/issuer/documents` | Valida, firma, guarda y **encola el anclaje**. |
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

> 🔴 **La emisión devuelve al instante; el anclaje, no.** El `201` llega con
> `status: "signed"` y `tx_hash: null`. El anclaje corre en un job y el documento pasa
> después a `anchored` —con `tx_hash` y `anchored_at`— o a `failed`. **Un verificador
> rechaza un documento que no esté `anchored`**, así que esperá el webhook
> [`document.status-changed`](06-webhooks.md#documentstatus-changed), o consultá
> `GET /documents`, antes de entregarlo.

**Errores:** `404 schema_not_found`, `409 workspace_not_provisioned`,
`409 schema_registration_pending`, `409 schema_registration_failed`,
`422 invalid_valid_until`, `422 wrong_schema_kind`, `422 missing_claims`,
`422 invalid_visibility`, `422 validation_error`,
`503 chain_not_configured` (sin registro de firmas no se firma nada que no se pueda
anclar).

---

## Límites y cuotas

| Recurso | Límite |
|---|---|
| `POST /api/v1/issuer/credential-offer` | 120 req/min por IP de origen, contadas por nodo |
| Cuerpo de un request | 8 MB |
| Vigencia de una oferta | 24 horas |
| Vigencia de una sesión de verificación | 10 minutos |
| `limit` en el listado de verificaciones | 200 (y es el único listado que pagina) |
| Adjunto de un claim `attachment` | 560.000 bytes, y uno solo por esquema |
| Imágenes del diseño (`thumbnail.uri`, `hero.uri`) | 256 KB |
| Intentos de un cambio de estado on-chain | 5 |
| Intentos de anclaje de un documento | 5 |
| Timeout de entrega de webhook | 5 segundos |
| Reintentos de webhook | 8 intentos, con backoff — ~1,5 horas en total |
| Endpoints de webhook por workspace | 10 |
| Desfasaje horario tolerado en el KB-JWT | ±5 minutos |
| Layout de un documento | 3 MB |

## Objetos comunes

### Schema

Lo que devuelven los endpoints de esquemas.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | uuid | El id de **esta fila de versión**. |
| `schema_id` | string | Tu identificador estable. |
| `credential_type` | string | El `vct` del SD-JWT y el id del tipo on-chain. |
| `name` | string | Nombre para mostrar. |
| `claims` | array | Las definiciones de claim. |
| `validity_period` | string \| null | `1y`, `6m`, `30d`; `null` si no vence. |
| `version` | integer | Arranca en 1. **Ausente en la respuesta de creación.** |
| `kind` | string | `credential` o `document`. |
| `format` | string | `vc+sd-jwt` o `mso_mdoc`. |
| `registration_status` | string | `pending`, `registered` o `failed`. |
| `registration_error` | string \| null | Por qué falló el registro. |
| `doc_type` | string \| null | Solo `mso_mdoc`: el docType, generado si no declaraste uno. `null` hasta que el workspace esté aprovisionado. |
| `namespace` | string \| null | Solo `mso_mdoc`: el primer segmento de todo path DCQL de mdoc. |
| `layout` | object \| null | **Solo en el listado:** el layout de la hoja de un esquema `document`, definido en el dashboard. |

### Design

Lo que devuelve `PUT …/design`.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | uuid | Id del diseño. |
| `credential_schema_id` | uuid | La fila de versión del esquema a la que pertenece. |
| `name` | string | Nombre para mostrar. |
| `description` | string \| null | |
| `display` | object | `title`, `subtitle`, `description` (mappings) y `properties` (array de mappings). |
| `styles` | object | `thumbnail`, `hero` (`{uri, alt}`), `background`, `text` (`{color}`). |
| `back` | object \| null | El dorso: su propio `display` y sus propios `styles`. |
| `version` | integer | Se incrementa en cada actualización. |

### Credential

```json
{
  "id": "b662aaad-d940-48fb-be9e-7194c76210ff",
  "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
  "status": "issued",
  "pending_status": null,
  "status_change_error": null,
  "issued_at": "2026-08-26T04:41:32Z",
  "expires_at": "2027-08-26T04:41:32Z",
  "schema": {
    "id": "3f2b…",
    "schema_id": "licencia_de_conducir",
    "credential_type": "DrivingLicence",
    "name": "Licencia de Conducir"
  }
}
```

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | uuid | El `credential_id`. |
| `holder_did` | string \| null | `null` hasta el canje. |
| `status` | string | `issued`, `suspended` o `revoked`; en una oferta sin canjear, `pending` o `expired`. |
| `pending_status` | string \| null | El destino de un cambio de estado que todavía va camino a la cadena. |
| `status_change_error` | string \| null | Por qué no llegó el último cambio de estado. |
| `issued_at` | string \| null | `null` hasta el canje. |
| `expires_at` | string \| null | Vencimiento de la credencial. **En una oferta sin canjear, el plazo para retirarla.** |
| `schema` | object | `{id, schema_id, credential_type, name}`. En una oferta sin canjear, `schema_id` no viene. |

### Session

```json
{
  "session_id": "e2d16450-42e8-46a9-ae92-d72af51a7435",
  "status": "completed",
  "dcql_query": { "credentials": [ /* … */ ] },
  "authorization_request_uri": null,
  "authorization_request_uri_ref": null,
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
| `status` | string | `pending`, `completed`, `failed`, `expired` — este último lo pone un barrido que corre cada minuto. |
| `dcql_query` | object | La consulta tal como se mandó. |
| `authorization_request_uri` | string \| null | Paso por valor. **`null` una vez que la sesión se resolvió o pasó su `expires_at`.** |
| `authorization_request_uri_ref` | string \| null | Paso por referencia. `null` cuando lo está la otra. |
| `holder_did` | string \| null | Se conoce recién cuando el holder responde. |
| `presentation_data` | object \| null | Si `completed`: `{"credentials": [{"id", "format", "claims"}]}`, donde `id` es el id del DCQL y `claims` trae lo divulgado. |
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

Ver [las reglas de escritura](05-verificacion-de-credenciales.md#escribir-la-consulta-dcql).

---

**Anterior:** [← 7. Ciclo de vida y revocación](07-ciclo-de-vida-y-revocacion.md) · **Siguiente:** [9. Errores y troubleshooting →](09-errores-y-troubleshooting.md)
