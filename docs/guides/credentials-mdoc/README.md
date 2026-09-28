# Guías de credenciales mDoc (ISO 18013-5)

Documentación para **emitir** y **verificar** credenciales en formato **mDoc** —
el formato de la norma **ISO/IEC 18013-5**, el mismo con el que se emiten las
licencias de conducir móviles (**mDL**).

Un mDoc es una credencial **CBOR** firmada con un **MSO** (*Mobile Security
Object*) `COSE_Sign1`, no un JWT. Se emite mediante **OID4VCI** y se verifica
mediante **OID4VP + DCQL**, a través de los mismos endpoints que una credencial
SD-JWT: lo que cambia es el `format` del esquema, el `format` del DCQL y la forma
de nombrar un claim.

> Para una credencial SD-JWT VC convencional —un título, una membresía, una
> constancia con holder—, la documentación correspondiente es
> [`../credentials/`](../credentials/). Para una hoja firmada sin holder, es
> [`../documents/`](../documents/). La comparación entre las tres se encuentra en
> el [índice de guías](../README.md).

## mDoc frente a SD-JWT VC

| | **SD-JWT VC** ([`../credentials/`](../credentials/)) | **mDoc** (esta guía) |
|---|---|---|
| `format` del esquema | `vc+sd-jwt` | `mso_mdoc` |
| Codificación | JSON / JWT | CBOR |
| Firma | JWS ES256 sobre el JWT | `COSE_Sign1` (MSO) ES256 |
| Divulgación selectiva | *Disclosures* con sal y hash en `_sd` | `IssuerSignedItem` por elemento, digest en `valueDigests` |
| Denominación de un claim | Ruta JSON: `["full_name"]` | Par namespace y elemento: `["org.iso.18013.5.1", "family_name"]` |
| Identidad del emisor | El DID del workspace, resuelto en SovraChain | La dirección contenida en el docType, **o** la cadena de certificados `x5chain` |
| Revocación | `credentialStatus` dentro de la credencial | `status_list_index` en el namespace meta de Sovra |
| Prueba de posesión | KB-JWT | `DeviceSigned` (device authentication) |
| Adjuntos (`attachment`) | ✅ | ❌ — ISO no define un elemento equivalente |
| `kind: "document"` | ✅ | ❌ — mDoc admite únicamente `credential` |
| Presencial (BLE/NFC) | — | ❌ **No implementado.** Únicamente el perfil en línea |

El resto es común a ambos formatos: la misma API key de workspace
(`Authorization: Bearer sovra_sk_...`), los mismos endpoints, los mismos
webhooks y el mismo estado publicado en **SovraChain**.

## Ruta de lectura

| # | Guía | Propósito |
|---|---|---|
| 1 | [Introducción](01-introduccion.md) | Qué es un mDoc, docType y namespace, anatomía del `IssuerSigned`, el namespace meta, las dos rutas de confianza. |
| 2 | [Primeros pasos](02-primeros-pasos.md) | Entornos, creación del esquema mDoc, tipos de claim, `required` frente a `always shared`, API key, webhook. |
| 3 | [Emisión de mDocs](03-emision-de-mdocs.md) | Creación de la oferta, codificación de cada tipo, el QR, el webhook `credential.issued`. |
| 4 | [Verificación de mDocs](04-verificacion-de-mdocs.md) | El DCQL `mso_mdoc`, las rutas `[namespace, elemento]`, lectura del resultado. |
| 5 | [Referencia de la API](05-referencia-api.md) | Endpoints, objetos, tipos de claim, catálogo de elementos ISO. |
| 6 | [Errores y troubleshooting](06-errores-y-troubleshooting.md) | Cada código de error, su causa y su solución. |
| 7 | [Verificación sin Sovra](07-verificacion-sin-sovra.md) | `verifyMdoc()` contra la cadena y contra anclas de confianza propias. |
| 8 | [Habilitar mDL ISO](08-habilitar-mdl-iso.md) | **Solo para el docType oficial de ISO.** El trámite del certificado ante la autoridad emisora. |

> La guía 8 es un trámite externo y opcional: solo hace falta si la credencial
> debe llevar el docType `org.iso.18013.5.1.mDL`. Con el docType que genera Sovra
> se emite sin ningún certificado.

## Resumen: los dos flujos

**Emisión** de un mDoc:

```bash
# 1. Creación de la oferta (servidor de la integración). El mismo endpoint que
#    para SD-JWT: el formato lo determina el esquema, no la llamada.
curl -X POST "$BASE_URL/api/v1/issuer/credential-offer" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"schema_id": "licencia_conducir", "claims": {
        "family_name": "Lovelace",
        "given_name": "Ada",
        "birth_date": "1815-12-10",
        "portrait": "iVBORw0KGgoAAAANSUhEUg...",
        "driving_privileges": [{"vehicle_category_code": "B"}]
      }}'
# → { "credential_id": "...", "offer_uri": "openid-credential-offer://?...", ... }

# 2. Se renderiza offer_uri como QR. El ciudadano lo escanea con la wallet.
# 3. El webhook recibe `credential.issued` con format "mso_mdoc" y el doc_type.
```

**Verificación** de un mDoc:

```bash
# 1. Creación de la sesión. Un claim mDoc se nombra con dos segmentos.
curl -X POST "$BASE_URL/api/v1/verifier/verifications" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"dcql_query": {"credentials": [{
        "id": "lic",
        "format": "mso_mdoc",
        "meta": {"doctype_value": "org.iso.18013.5.1.mDL"},
        "claims": [{"path": ["org.iso.18013.5.1", "age_over_18"]}]
      }]}}'
# → { "session_id": "...", "authorization_request_uri_ref": "openid4vp://?...", ... }

# 2. Se renderiza la URI como QR. El ciudadano la escanea.
# 3. El webhook recibe `presentation.verified` (o `presentation.failed`).
```

## Recursos

- [Colección de Postman — mDoc](../../resources/sovra-mdoc.postman_collection.json) — impórtela y configure `baseUrl` y `apiKey`.
- [Webhooks](../credentials/05-webhooks.md) — el catálogo de eventos y la firma HMAC son idénticos para ambos formatos.
- Especificación OpenAPI en vivo: `GET {baseUrl}/openapi/api`

---

**Volver a [todas las guías](../README.md)** · **Credenciales SD-JWT:** [`../credentials/`](../credentials/)
