# 4. Emisión de credenciales

Emitir una credencial son tres movimientos: **creás una oferta**, **mostrás un QR**,
y **recibís un webhook** cuando el ciudadano la acepta.

## Tabla de contenidos

1. [Paso 1 — Crear la oferta](#paso-1--crear-la-oferta)
2. [La respuesta, campo por campo](#la-respuesta-campo-por-campo)
3. [Paso 2 — Mostrar el QR](#paso-2--mostrar-el-qr)
4. [Paso 3 — Recibir `credential.issued`](#paso-3--recibir-credentialissued)
5. [Consultar el estado de una emisión](#consultar-el-estado-de-una-emisión)
6. [Errores al crear la oferta](#errores-al-crear-la-oferta)
7. [Ejemplo completo en Node.js](#ejemplo-completo-en-nodejs)

---

## Paso 1 — Crear la oferta

```http
POST /api/v1/issuer/credential-offer
Authorization: Bearer sovra_sk_...
Content-Type: application/json
```

```json
{
  "schema_id": "licencia_de_conducir",
  "claims": {
    "full_name": "Ada Lovelace",
    "birth_date": "1815-12-10",
    "document_number": "34.902.117",
    "age_over_18": true
  }
}
```

| Campo | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `schema_id` | string | ✅ | El `schema_id` del esquema (el slug, no el UUID). Se usa siempre su **última versión**. **Omitirlo no es un `422`, es un `500`.** |
| `claims` | object | — | Los valores de la credencial, `key` → valor. Si se omite, equivale a `{}`. Tiene que ser un objeto: un string o un array también terminan en `500`. |
| `valid_until` | string | — | Vencimiento absoluto en ISO 8601, a futuro, que **pisa el `validity_period` del esquema**. Omitilo para usar el del esquema; **`null` se rechaza** con `422 invalid_valid_until`. |

Sobre `claims`:

- Las **keys** deben coincidir exactamente con las del esquema. Una key que el
  esquema no declara **se descarta en silencio** al firmar.
- Todo claim marcado `required` en el esquema tiene que estar presente, o la llamada
  falla con `422 missing_claims`.
- Los valores viajan tal cual se envían. Fechas como texto ISO (`"1815-12-10"`),
  booleanos como booleanos.

**Rate limit:** 120 solicitudes por minuto **por IP de origen** —no por API key—, y se
cuentan por separado en cada nodo de la API. Por encima, `429 too_many_requests`, **sin
cabecera `Retry-After`**: esperá y reintentá.

```bash
curl -X POST "$BASE_URL/api/v1/issuer/credential-offer" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "schema_id": "licencia_de_conducir",
    "claims": { "full_name": "Ada Lovelace", "age_over_18": true }
  }'
```

## La respuesta, campo por campo

`201 Created`:

```json
{
  "credential_id": "b662aaad-d940-48fb-be9e-7194c76210ff",
  "offer_uri": "openid-credential-offer://?credential_offer=%7B%22credential_issuer%22...",
  "pre_authorized_code": "a3f1…",
  "expires_at": "2026-08-27T04:41:32Z",
  "credential": {
    "iss": "https://api.sovra.io/did:sovra:0xcda79e1ee5612c230a6faf68236e3679b0579bae",
    "iat": 1787719292,
    "exp": 1819255292,
    "vct": "VerifiableCredential",
    "full_name": "Ada Lovelace",
    "age_over_18": true,
    "credentialStatus": [ /* … */ ]
  }
}
```

| Campo | Para qué sirve |
|---|---|
| `credential_id` | **El identificador estable de toda la vida de la credencial.** Guardalo. Es el mismo que llega en el webhook `credential.issued` y el que usás en `GET /credentials/{id}` y en los cambios de estado. |
| `offer_uri` | La URI OID4VCI que hay que renderizar como QR. |
| `pre_authorized_code` | El código de un solo uso que ya viene embebido en `offer_uri`. Solo lo necesitás si construís tu propio deep link. |
| `expires_at` | La oferta caduca **24 horas** después de creada. |
| `credential` | **Vista previa sin firmar**: mismos claims, pero sin `sub`, sin `cnf`, sin digests `_sd` y **sin firma**. Sirve para mostrarle al ciudadano qué va a recibir, antes de que escanee. **No es una credencial y no prueba nada.** |

> ⚠️ `credential` es una vista previa. La credencial real, firmada, aparece recién
> en el webhook `credential.issued` — y solo si el ciudadano escanea y acepta.

## Paso 2 — Mostrar el QR

`offer_uri` tiene esta forma:

```
openid-credential-offer://?credential_offer={"credential_issuer":"https://api.sovra.io/did:sovra:0x…",
"credential_configuration_ids":["LicenciaDeConducir"],
"grants":{"urn:ietf:params:oauth:grant-type:pre-authorized_code":{"pre-authorized_code":"a3f1…"}}}
```

Codificá **la cadena completa, sin modificarla** en un QR. Cualquier librería sirve:

```js
import QRCode from "qrcode";
const dataUrl = await QRCode.toDataURL(offer.offer_uri, { errorCorrectionLevel: "M" });
```

En móvil también podés abrir la URI directamente como deep link, sin QR.

Lo que pasa cuando el ciudadano escanea (Sovra se encarga de todo esto):

1. La wallet lee `credential_issuer` y descarga los metadatos OID4VCI del emisor.
2. Canjea el `pre-authorized_code` por un access token.
3. Envía una **prueba de posesión** de su llave (un JWT `openid4vci-proof+jwt`
   firmado con su passkey).
4. Sovra construye el SD-JWT, lo firma con la llave del workspace y se lo entrega.
5. La credencial queda guardada en el teléfono, y se dispara tu webhook.

**La oferta es de un solo uso.** Una vez canjeada, ese QR ya no sirve.

## Paso 3 — Recibir `credential.issued`

Cuando la credencial queda en la wallet, tu servidor recibe:

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
    "credential": "eyJhbGciOiJFUzI1NiIsInR5cCI6InZjK3NkLWp3dCJ9.eyJfc2QiOls…~WyJONFpyMkNTLWFrOTNKMFdoMl9qQ0R3IiwiZW1haWwiLCJxdWludGVyb3NtLmRhbmllbEBnbWFpbC5jb20iXQ~…~"
  }
}
```

| Campo de `data` | Descripción |
|---|---|
| `credential_id` | El mismo UUID que devolvió la creación de la oferta. |
| `holder_did` | El DID del ciudadano que la aceptó. Antes de esto no se conoce. |
| `schema_type` | El `credential_type` (`vct`) del esquema. |
| `format` | Siempre `vc+sd-jwt`. |
| `issued_at` / `expires_at` | Emisión y expiración, ISO 8601. |
| `credential` | **El SD-JWT firmado, completo.** |

> 🔴 **Este es el único momento en que existe el SD-JWT firmado del lado servidor.**
> Sovra no lo almacena y no hay endpoint para volver a pedirlo. Si tu sistema lo
> necesita — para auditar, reimprimir o verificar por tu cuenta — **guardalo al
> recibir este evento**.

Ver [6. Webhooks](06-webhooks.md) para la validación de la firma HMAC y la política
de reintentos.

## Consultar el estado de una emisión

El `credential_id` funciona desde el momento en que creaste la oferta, incluso antes
de que el ciudadano escanee:

```bash
curl -s "$BASE_URL/api/v1/issuer/credentials/b662aaad-d940-48fb-be9e-7194c76210ff" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

**Todavía sin canjear:**

```json
{
  "id": "b662aaad-d940-48fb-be9e-7194c76210ff",
  "holder_did": null,
  "status": "pending",
  "issued_at": null,
  "expires_at": "2026-08-27T04:41:32Z",
  "schema": { "id": "…", "credential_type": "VerifiableCredential", "name": "Licencia de Conducir" }
}
```

**Ya emitida:**

```json
{
  "id": "b662aaad-d940-48fb-be9e-7194c76210ff",
  "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
  "status": "issued",
  "issued_at": "2026-08-26T04:41:32Z",
  "expires_at": "2027-08-26T04:41:32Z",
  "schema": { "id": "…", "schema_id": "licencia_de_conducir", "credential_type": "VerifiableCredential", "name": "Licencia de Conducir" }
}
```

Estados posibles: `pending`, `expired` (la oferta venció sin canjearse), `issued`,
`suspended`, `revoked`. Ver [7. Ciclo de vida](07-ciclo-de-vida-y-revocacion.md).

Y para listar todo lo emitido por el workspace:

```bash
curl -s "$BASE_URL/api/v1/issuer/credentials" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

> Ojo: el listado devuelve las credenciales **efectivamente emitidas**. Las ofertas
> pendientes no aparecen ahí; se consultan de a una por su `credential_id`.

## Errores al crear la oferta

| HTTP | `error` | Causa | Solución |
|---|---|---|---|
| 401 | `invalid_api_key` | Falta el header, la clave está mal o fue revocada. | Revisá `Authorization: Bearer sovra_sk_...`. |
| 403 | `workspace_inactive` / `organization_inactive` | El tenant está desactivado. La clave sigue siendo válida: lo que se frena es el tenant. | Reactivarlo desde el dashboard. |
| 404 | `schema_not_found` | El `schema_id` no existe en este workspace. | Usá el slug del esquema, no el UUID ni el nombre visible. |
| 409 | `workspace_not_provisioned` | El DID todavía no terminó de generarse. | Esperá unos minutos y reintentá. |
| 409 | `schema_registration_pending` | El tipo de credencial **se sigue registrando on-chain**. | Esperá unos segundos; el estado se ve en `GET /api/v1/issuer/schemas`. |
| 409 | `schema_registration_failed` | El tipo no pudo registrarse: con ese esquema no se puede emitir nada. | Creá el esquema de nuevo. |
| 422 | `invalid_valid_until` | `valid_until` es `null`, está malformado o quedó en el pasado. | Mandá una fecha futura, u omitilo. |
| 422 | `missing_claims` | Falta al menos un claim `required`. `details` los lista. | Completá los campos que faltan. |
| 422 | `wrong_schema_kind` | El esquema es de kind `document`, no `credential`. | Los documentos no se emiten a una wallet. |
| 422 | `invalid_claim_values` | Un adjunto no es un data URI de PDF válido, o pasa los 560.000 bytes. | `details` lista `"<key>: <motivo>"`. |
| 422 | `document_signer_required` | El esquema declara su propio docType y el workspace no tiene document signer. | Registralo desde el dashboard. |
| 422 | `bitmap_exhausted` | La lista de estado del workspace está llena. | Contactá al equipo de Sovra. |
| 429 | `too_many_requests` | Pasaste las 120 req/min de tu IP. | Esperá y reintentá. |

Ejemplo de `422 missing_claims`:

```json
{ "error": "missing_claims", "details": ["birth_date", "document_number"] }
```

## Ejemplo completo en Node.js

```js
const BASE_URL = process.env.SOVRA_BASE_URL;
const API_KEY = process.env.SOVRA_API_KEY;

async function emitirCredencial(schemaId, claims) {
  const res = await fetch(`${BASE_URL}/api/v1/issuer/credential-offer`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${API_KEY}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ schema_id: schemaId, claims }),
  });

  if (!res.ok) {
    const err = await res.json();
    throw new Error(`Sovra ${res.status}: ${err.error} ${JSON.stringify(err.details ?? "")}`);
  }

  const offer = await res.json();

  // Guardá credential_id antes de mostrar el QR: es la única forma de
  // correlacionar el webhook con este trámite.
  await db.tramites.update(tramiteId, {
    sovra_credential_id: offer.credential_id,
    estado: "esperando_escaneo",
    oferta_expira: offer.expires_at,
  });

  return offer; // renderizá offer.offer_uri como QR
}
```

Y del lado del webhook:

```js
app.post("/webhooks/sovra", verificarFirmaSovra, async (req, res) => {
  const { event, data } = req.body;

  if (event === "credential.issued") {
    await db.tramites.updateBySovraId(data.credential_id, {
      estado: "emitida",
      holder_did: data.holder_did,
      sd_jwt: data.credential,      // ← la única copia que vas a tener
      expira: data.expires_at,
    });
  }

  res.sendStatus(200);
});
```

---

**Anterior:** [← 3. Crear un esquema](03-crear-un-esquema.md) · **Siguiente:** [5. Verificación de credenciales →](05-verificacion-de-credenciales.md)
