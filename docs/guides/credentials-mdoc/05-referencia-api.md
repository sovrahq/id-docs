# 5. Referencia de la API

## Tabla de contenidos

1. [Convenciones](#convenciones)
2. [Autenticación](#autenticación)
3. [Emisor](#emisor)
4. [Verificador](#verificador)
5. [Configuración del esquema mDoc](#configuración-del-esquema-mdoc)
6. [Tipos de claim](#tipos-de-claim)
7. [Elementos del namespace ISO](#elementos-del-namespace-iso)
8. [El namespace meta](#el-namespace-meta)
9. [Objetos](#objetos)

---

## Convenciones

| | |
|---|---|
| **Base URL** | Test `https://test-api-sovra.flagonsa.com` · Producción `https://api.sovra.io` |
| **Formato** | JSON en la solicitud y en la respuesta. `Content-Type: application/json`. |
| **Nomenclatura** | `snake_case` en la totalidad de la API. |
| **Fechas** | ISO 8601 en UTC. |
| **OpenAPI** | `GET {baseUrl}/openapi/api` — la fuente de verdad. |

> **No existen endpoints específicos de mDoc en la API servidor-a-servidor.** La
> emisión y la verificación se realizan a través de los mismos endpoints que una
> credencial SD-JWT; el formato lo determina el esquema. Las operaciones
> específicas de mDoc —creación del esquema, registro del document signer y
> admisión de una raíz IACA— residen en el **dashboard**, no en la API de
> integración.

## Autenticación

```
Authorization: Bearer sovra_sk_...
```

El esquema `Bearer` es sensible a mayúsculas. La clave se crea en
**Settings → API keys** y su alcance se limita a un workspace.

## Emisor

### `POST /api/v1/issuer/credential-offer`

Crea una oferta OID4VCI preautorizada. Si el `schema_id` designa un esquema
`mso_mdoc`, lo que se emite es un mDoc.

**Cuerpo de la solicitud**

| Campo | Tipo | Obligatorio | Observaciones |
|---|---|---|---|
| `schema_id` | string | ✅ | El slug del esquema, no su UUID. Se emplea la última versión. |
| `claims` | object | ❌ | Valores **en estructura plana, indexados por `key`**. El namespace lo determina Sovra. |

**`201`**

| Campo | Observaciones |
|---|---|
| `credential_id` | UUID. Identificador de todo el ciclo de vida. |
| `offer_uri` | `openid-credential-offer://…` — destinado al QR. |
| `pre_authorized_code` | El código que canjea la wallet. |
| `expires_at` | 24 horas. |
| `credential` | Vista previa **sin firmar**. |

**Errores:** `401 invalid_api_key` · `404 schema_not_found` · `404 workspace_not_found` ·
`409 workspace_not_provisioned` · `422 missing_claims` · `422 unencodable_claims` ·
`422 wrong_schema_kind` · `422 document_signer_required`

Límite de tasa: 120 solicitudes por minuto y API key.

### `GET /api/v1/issuer/credentials/{id}`

Consulta por `credential_id`.

### `PUT /api/v1/issuer/credentials/{id}/status/{status}`

Modifica el estado de una credencial emitida. Idéntico al caso de SD-JWT; véase
[Ciclo de vida y revocación](../credentials/06-ciclo-de-vida-y-revocacion.md).

| `{status}` | Efecto | Reversible |
|---|---|---|
| `revoked` | Revocación permanente. Marca el índice en el mapa de revocación. | ❌ |
| `suspended` | Suspensión. La credencial deja de verificar mientras permanezca en este estado. | ✅ |
| `issued` | Reactiva una credencial suspendida y la devuelve a su estado de emisión. | — |

> ⚠️ **El valor de reactivación es `issued`, no `active`.** El segmento indica el
> estado de destino, que es aquel en el que la credencial quedó al emitirse.
> Cualquier otro valor devuelve `400 unknown_status`.

Únicamente resulta aplicable a una credencial `suspended`; sobre una revocada,
`issued` devuelve `422 invalid_status_transition`.

**Respuesta `200`:** `{ "id": "<uuid>", "status": "<nuevo estado>" }`

**Errores:** `400 unknown_status` · `404 credential_not_found` ·
`422 credential_not_yet_issued` · `422 invalid_status_transition` ·
`409 workspace_not_provisioned`

En mDoc, el índice de la lista de estado reside en
`io.sovra.meta.1/status_list_index`, y **un único índice sirve a ambos
propósitos**, revocación y suspensión.

## Verificador

### `POST /api/v1/verifier/verifications`

**Cuerpo de la solicitud**

| Campo | Tipo | Obligatorio |
|---|---|---|
| `dcql_query` | object | ✅ |

**`201`**

| Campo | Observaciones |
|---|---|
| `session_id` | UUID. Se devuelve como `verification_id` en el webhook. |
| `authorization_request_uri` | Por valor, autocontenida. |
| `authorization_request_uri_ref` | Por referencia. **Opción recomendada para el QR.** |
| `status` | `pending`. |
| `expires_at` | 10 minutos. |

**Errores:** `400 invalid_dcql_query` · `400 unsupported_dcql_format` ·
`400 invalid_mdoc_claim_path` · `400 mixed_dcql_formats` · `401 invalid_api_key` ·
`403 workspace_inactive` · `409 workspace_not_provisioned`

### `GET /api/v1/verifier/verifications/{id}`

Consulta el estado de una sesión por su `session_id`. El resultado se entrega
asimismo por webhook; este endpoint se destina a consulta periódica o a
diagnóstico.

## Configuración del esquema mDoc

Se define desde el dashboard. Los campos relevantes para el formato son los
siguientes:

| Campo | Valores | Observaciones |
|---|---|---|
| `format` | `vc+sd-jwt` · `mso_mdoc` | **Inmutable entre revisiones.** Su envío en una revisión no produce efecto alguno. |
| `kind` | `credential` | mDoc **no** admite `document`. |
| `schema_id` | `^[a-z0-9][a-z0-9_-]*$` | Aplicable únicamente a mDoc: se convierte en segmento del docType. |
| `doc_type` | DNS inverso, **sensible a mayúsculas** | Vacío → generado. Su declaración exige document signer. |
| `namespace` | DNS inverso | Vacío → idéntico al docType. Para ISO, `org.iso.18013.5.1`. |
| `validity_period` | `^[1-9]\d*(y\|m\|d)$` | En su ausencia, el mDoc tiene una vigencia de **1 año** (ISO exige `validUntil`). |
| `claims[].key` | | El `elementIdentifier` de ISO. |
| `claims[].type` | véase más adelante | Determina la codificación CBOR. |
| `claims[].required` | bool | Debe figurar en la oferta. |
| `claims[].disclosable` | bool | `false` = **`always shared`**: el holder no puede retenerlo. |

**docType generado:**

```
io.sovra.<dirección-eoa-del-workspace-en-minúsculas>.<schema_id>.1
```

La dirección corresponde siempre al segmento que sigue a `io.sovra.`, en
minúsculas y con sus 40 dígitos hexadecimales completos. Constituye el mecanismo
por el cual un verificador determina el emisor a partir de una cadena firmada, en
lugar de hacerlo a partir de una cabecera sin firmar susceptible de ser
modificada por un holder.

## Tipos de claim

| Tipo | Valor en el JSON | Codificación CBOR | Observaciones |
|---|---|---|---|
| `string` | Texto | Texto | |
| `uri` | Texto | Texto | Una URL, transportada como texto. |
| `integer` | Entero | Entero | ISO lo emplea para `sex`, `height` y `weight`. |
| `number` | Entero, o float **integral** | Entero | `4.5` se rechaza: un float carece de forma CBOR determinista. |
| `boolean` | `true` / `false` | Booleano | Es el mecanismo por el cual `age_over_NN` atestigua una edad sin la fecha. |
| `date` | `"YYYY-MM-DD"` | Tag `full-date` | Diez caracteres exactos. |
| `datetime` | ISO 8601 con offset | Tag `tdate` | |
| `bytes` | **base64 estándar con padding** | Byte string | Retrato, firma o plantilla biométrica. |
| `driving_privileges` | Array de objetos | Array de mapas | ISO 18013-5 §7.2.4. |
| `attachment` | — | — | **Exclusivo de `vc+sd-jwt`.** ISO no define un elemento para un adjunto. |

Un tipo no reconocido por el codificador se interpreta como texto si el valor ya
es una cadena, y se rechaza en cualquier otro supuesto; de este modo, un esquema
con un tipo nuevo conserva su capacidad de emisión.

### `driving_privileges`

```
[ { "vehicle_category_code": string,      // obligatorio
    "issue_date":  "YYYY-MM-DD",          // opcional
    "expiry_date": "YYYY-MM-DD",          // opcional
    "codes": [ { "code": string,          // obligatorio
                 "sign": string,          // opcional, TEXTO
                 "value": string } ] } ]  // opcional, TEXTO
```

Un array vacío se rechaza: ISO exige al menos un privilegio.

`codes` es **opcional** y enumera las restricciones de la categoría: el
equivalente de la columna de observaciones de una licencia de plástico. El valor
de `code` lo **define la autoridad emisora** —ISO no fija un catálogo—, y `sign`
y `value` lo cuantifican cuando la restricción admite una cantidad. Los tres son
de tipo texto. Explicado con ejemplos en la
[guía de emisión](03-emision-de-mdocs.md#codes-las-restricciones-de-cada-categoría).

## Elementos del namespace ISO

`org.iso.18013.5.1` constituye un **conjunto cerrado**. Un `key` no incluido en
él se rechaza al guardar el esquema con `undefined_iso_elements`, y uno con tipo
incorrecto con `wrong_iso_element_type`.

### Obligatorios (11)

ISO exige su presencia **en la credencial**. Al inicializar un esquema de mDL se
generan con `required: true` y `disclosable: false`, es decir, **`always
shared`**.

| Elemento | Tipo |
|---|---|
| `family_name` | `string` |
| `given_name` | `string` |
| `birth_date` | `date` |
| `issue_date` | `date` o `datetime` |
| `expiry_date` | `date` o `datetime` |
| `issuing_country` | `string` |
| `issuing_authority` | `string` |
| `document_number` | `string` |
| `portrait` | `bytes` |
| `driving_privileges` | `driving_privileges` |
| `un_distinguishing_sign` | `string` |

> `issue_date` y `expiry_date` son los dos únicos elementos respecto de los
> cuales ISO permite al emisor optar entre `full-date` y `tdate`.

### Opcionales

Se generan con `required: false` y `disclosable: true`, lo cual constituye el
fundamento de la utilidad de la divulgación selectiva: quien acredita ser mayor
de 18 años no debería verse obligado a facilitar su domicilio.

| Elemento | Tipo | | Elemento | Tipo |
|---|---|---|---|---|
| `administrative_number` | `string` | | `nationality` | `string` |
| `sex` | `integer` | | `resident_city` | `string` |
| `height` | `integer` | | `resident_state` | `string` |
| `weight` | `integer` | | `resident_postal_code` | `string` |
| `eye_colour` | `string` | | `resident_country` | `string` |
| `hair_colour` | `string` | | `resident_address` | `string` |
| `birth_place` | `string` | | `family_name_national_character` | `string` |
| `portrait_capture_date` | `datetime` | | `given_name_national_character` | `string` |
| `age_in_years` | `integer` | | `signature_usual_mark` | `bytes` |
| `age_birth_year` | `integer` | | `issuing_jurisdiction` | `string` |

### Familias

Se identifican por patrón, no por enumeración:

| Patrón | Tipo | Observaciones |
|---|---|---|
| `age_over_NN` — `^age_over_\d{2}$` | `boolean` | Cien identificadores posibles. Al inicializar un mDL se generan `age_over_18` y `age_over_21`. |
| `biometric_template_xx` — `^biometric_template_[a-z_]+$` | `bytes` | |

### Extensión de un mDL

El namespace ISO no admite elementos adicionales. **Para campos propios debe
añadirse un namespace adicional**, que es el mecanismo previsto por ISO para
extender un documento y el que AAMVA ya emplea con `org.iso.18013.5.1.aamva`. Un
esquema que declare cualquier otro namespace no se contrasta con esta lista.

## El namespace meta

Todo mDoc emitido por Sovra incorpora, además del propio, el namespace reservado
**`io.sovra.meta.1`**. Se trata de elementos de datos ordinarios, firmados por el
emisor.

| Elemento | Tipo | Finalidad |
|---|---|---|
| `holder_did` | string | El DID del holder. Un mDoc lo identifica únicamente por su `deviceKey`, y Sovra requiere el DID para resolver las llaves que la cuenta autoriza actualmente. |
| `status_list_index` | entero ≥ 0 | El índice en el mapa de revocación. mDoc carece de `credentialStatus`. |
| `mandatory_elements` | mapa namespace → lista | El conjunto `disclosable: false`, congelado en la emisión. |
| `credential_type` | string | Lo que el emisor registró en el Issuer Trust Registry. |
| `namespace` | string | Dónde residen los elementos de este documento. |
| `issuer_address` | string `0x…` en minúsculas | La dirección EOA del workspace. |

> **El verificador exige los seis elementos, completos y correctamente
> tipados.** Un namespace meta incompleto constituye un fallo definitivo
> (`missing_mandatory_element:io.sovra.meta.1`), y no una ausencia de metadatos:
> se trata de insumos de control aportados por la parte sujeta a control, cuya
> omisión equivaldría a eludir dicho control.

## Objetos

### DCQL query — entrada `mso_mdoc`

```json
{
  "credentials": [
    {
      "id": "licencia",
      "format": "mso_mdoc",
      "meta": { "doctype_value": "org.iso.18013.5.1.mDL" },
      "claims": [
        { "path": ["org.iso.18013.5.1", "family_name"] }
      ]
    }
  ]
}
```

| Campo | Obligatorio | Observaciones |
|---|---|---|
| `id` | ✅ | Etiqueta definida por el verificador, devuelta sin modificación. |
| `format` | ✅ | `mso_mdoc`. |
| `meta.doctype_value` | ❌ | En su ausencia se admite cualquier docType. Si no coincide → `doctype_mismatch`. |
| `claims` | ❌ | Sin esta clave se solicita la credencial completa. |
| `claims[].path` | ✅ | **Exactamente dos cadenas.** |

No se admite la combinación de familias de formato en una misma consulta.

### Webhook `credential.issued` — mDoc

```json
{
  "credential_id": "uuid",
  "holder_did": "did:sovra:0x…",
  "schema_type": "DrivingLicence",
  "issued_at": "2026-09-28T13:02:11Z",
  "expires_at": "2036-09-28T13:02:11Z",
  "format": "mso_mdoc",
  "doc_type": "org.iso.18013.5.1.mDL",
  "credential": "omppc3N1ZXJBdXRo…"
}
```

`doc_type` es un campo aditivo: figura únicamente en mDoc. `credential`
corresponde al `IssuerSigned` en CBOR, **base64url sin padding**.

### Webhook `presentation.verified` — mDoc

```json
{
  "verification_id": "uuid",
  "holder_did": "did:sovra:0x…",
  "success": true,
  "completed_at": "2026-09-28T13:05:12Z",
  "credentials": [
    {
      "id": "licencia",
      "format": "mso_mdoc",
      "claims": { "org.iso.18013.5.1.family_name": "Lovelace" }
    }
  ]
}
```

Las claves de `claims` se entregan **cualificadas por namespace**, unidas
mediante un punto.

### `IssuerSigned`

```
IssuerSigned
├── nameSpaces: { <namespace> → [ #6.24(bstr .cbor IssuerSignedItem) ] }
└── issuerAuth: COSE_Sign1( MobileSecurityObject )
```

| Campo del MSO | Observaciones |
|---|---|
| `version` | `"1.0"` |
| `digestAlgorithm` | `"SHA-256"` |
| `docType` | Debe coincidir con el del documento. |
| `valueDigests` | Digest por elemento, agrupado por namespace. Calculado sobre el envoltorio tag-24, no sobre el mapa sin envolver. |
| `deviceKeyInfo.deviceKey` | La llave P-256 del holder, en formato COSE. |
| `validityInfo` | `signed`, `validFrom`, `validUntil`. `validUntil` es **obligatorio**. |

---

**Anterior:** [← 4. Verificación de mDocs](04-verificacion-de-mdocs.md) · **Siguiente:** [6. Errores y troubleshooting →](06-errores-y-troubleshooting.md)
