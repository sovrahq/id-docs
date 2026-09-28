# 4. Verificación de mDocs

El endpoint es el mismo que para SD-JWT. Lo que varía es el `format` del DCQL y,
principalmente, **la forma de denominar un claim**: un elemento mDoc se compone
de dos segmentos, namespace e identificador.

## Tabla de contenidos

1. [Paso 1 — Crear la sesión](#paso-1--crear-la-sesión)
2. [Composición de la consulta DCQL](#composición-de-la-consulta-dcql)
3. [Las cuatro reglas que previenen la mayoría de los fallos](#las-cuatro-reglas-que-previenen-la-mayoría-de-los-fallos)
4. [Ejemplos](#ejemplos)
5. [La respuesta, campo por campo](#la-respuesta-campo-por-campo)
6. [Paso 2 — Mostrar el QR](#paso-2--mostrar-el-qr)
7. [Paso 3 — Recibir el resultado](#paso-3--recibir-el-resultado)
8. [Alcance de la validación efectuada por Sovra](#alcance-de-la-validación-efectuada-por-sovra)
9. [Errores al crear la sesión](#errores-al-crear-la-sesión)
10. [Ejemplo completo en Node.js](#ejemplo-completo-en-nodejs)

---

## Paso 1 — Crear la sesión

```bash
curl -s -X POST "$BASE_URL/api/v1/verifier/verifications" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "dcql_query": {
      "credentials": [
        {
          "id": "licencia",
          "format": "mso_mdoc",
          "meta": { "doctype_value": "org.iso.18013.5.1.mDL" },
          "claims": [
            { "path": ["org.iso.18013.5.1", "family_name"] },
            { "path": ["org.iso.18013.5.1", "given_name"] },
            { "path": ["org.iso.18013.5.1", "age_over_18"] }
          ]
        }
      ]
    }
  }'
```

## Composición de la consulta DCQL

| Campo | Obligatorio | Descripción |
|---|---|---|
| `credentials[].id` | ✅ | Una etiqueta definida por el verificador. Se devuelve sin modificación en el resultado. |
| `credentials[].format` | ✅ | **`mso_mdoc`**. Los restantes valores admitidos son `vc+sd-jwt` y `dc+sd-jwt`, correspondientes al otro formato. |
| `credentials[].meta.doctype_value` | ❌ | El docType exacto requerido. En su ausencia, se admite cualquiera. |
| `credentials[].claims` | ❌ | Los elementos solicitados. Sin esta clave, se solicita la credencial completa. |
| `claims[].path` | ✅ | **Exactamente dos cadenas**: `["<namespace>", "<identificador>"]`. |

### El `path` de un elemento mDoc

Constituye la única grafía admitida por OpenID4VP 1.0 y la única que Sovra
acepta:

```json
{ "path": ["org.iso.18013.5.1", "family_name"] }
```

No corresponde emplear `["family_name"]` —propio de SD-JWT— ni un par
`{"namespace": …, "claim_name": …}`, correspondiente a un borrador anterior de la
especificación. Admitir ambas formas dejaría dos maneras de expresar lo mismo, y
un dashboard que emitiera la forma obsoleta continuaría funcionando hasta dejar
de hacerlo de forma abrupta.

Un `path` que no consista en dos cadenas devuelve
**`400 invalid_mdoc_claim_path`** al crear la sesión, y no una presentación
fallida diez minutos después.

### Determinación del namespace aplicable

| Esquema | Namespace correspondiente |
|---|---|
| docType generado (`io.sovra.…`) | **La misma cadena que el docType** |
| docType ISO (`org.iso.18013.5.1.mDL`) | **`org.iso.18013.5.1`** — no el docType |

Su confusión constituye el error más frecuente: el elemento existe, pero se busca
en un namespace donde no reside, y el resultado es
`missing_required_claim:<namespace>.<elemento>`.

## Las cuatro reglas que previenen la mayoría de los fallos

**1. Dos segmentos, siempre.** `["<namespace>", "<identificador>"]`. Ni uno ni
tres.

**2. El namespace no coincide con el docType** cuando este último es ISO.

**3. No deben combinarse familias de formato.** Una presentación transporta
**una** credencial en **un** formato, de modo que una consulta que combine
`mso_mdoc` con `vc+sd-jwt` resultaría insatisfacible. El fallo se produce al
crearla, con `400 mixed_dcql_formats`, momento en el que aún admite corrección.
Si se requieren ambos formatos, deben emplearse dos sesiones.

**4. Debe solicitarse el mínimo necesario.** Cada elemento solicitado es un
elemento que el ciudadano visualiza en la pantalla de aprobación, y que puede
rechazar si no está marcado como `always shared`. Si únicamente se requiere
acreditar la mayoría de edad, debe solicitarse `age_over_18` y no `birth_date`:
en ello reside el propósito del formato.

## Ejemplos

**Verificación de edad, sin información adicional:**

```json
{ "credentials": [ { "id": "edad", "format": "mso_mdoc",
  "meta": { "doctype_value": "org.iso.18013.5.1.mDL" },
  "claims": [ { "path": ["org.iso.18013.5.1", "age_over_18"] } ] } ] }
```

**Identificación para atención presencial:**

```json
{ "credentials": [ { "id": "identidad", "format": "mso_mdoc",
  "claims": [
    { "path": ["org.iso.18013.5.1", "family_name"] },
    { "path": ["org.iso.18013.5.1", "given_name"] },
    { "path": ["org.iso.18013.5.1", "document_number"] },
    { "path": ["org.iso.18013.5.1", "portrait"] }
  ] } ] }
```

**Categorías habilitadas:**

```json
{ "credentials": [ { "id": "categorias", "format": "mso_mdoc",
  "claims": [ { "path": ["org.iso.18013.5.1", "driving_privileges"] } ] } ] }
```

**Cualquier mDoc del docType indicado, sin solicitar elementos:**

```json
{ "credentials": [ { "id": "cualquiera", "format": "mso_mdoc",
  "meta": { "doctype_value": "io.sovra.0xab…ef.credencial.1" } } ] }
```

**Un esquema propio con namespace generado**, en el que el namespace corresponde
al docType completo:

```json
{ "credentials": [ { "id": "membresia", "format": "mso_mdoc",
  "claims": [ { "path": ["io.sovra.0xab…ef.membresia.1", "nivel"] } ] } ] }
```

## La respuesta, campo por campo

```json
{
  "session_id": "e2d16450-42e8-46a9-ae92-d72af51a7435",
  "authorization_request_uri": "openid4vp://?client_id=…&request=eyJ…",
  "authorization_request_uri_ref": "openid4vp://?client_id=…&request_uri=https%3A%2F%2F…",
  "status": "pending",
  "expires_at": "2026-09-28T13:12:34Z"
}
```

| Campo | Descripción |
|---|---|
| `session_id` | Identificador de correlación: se devuelve como `verification_id` en el webhook. |
| `authorization_request_uri` | Por valor: el objeto de solicitud firmado, incorporado en línea. Su longitud crece con el tamaño del DCQL. |
| `authorization_request_uri_ref` | Por referencia: contiene un enlace al objeto de solicitud. **Es la opción recomendada para el QR.** |
| `expires_at` | La sesión tiene una vigencia de **10 minutos**. |

> En el caso de mDoc resulta preferible `authorization_request_uri_ref` en la
> práctica totalidad de los supuestos: una consulta con varios elementos y un
> `doctype_value` extenso genera un QR de elevada densidad, y la longitud de esta
> variante no crece con el DCQL.

## Paso 2 — Mostrar el QR

La URI se renderiza como código QR. La wallet **identifica la credencial por
docType**, presenta al ciudadano los elementos solicitados de forma individual, y
este deselecciona aquellos que no desee revelar, salvo los `always shared`, que
se muestran con el control deshabilitado y se incluyen en todo caso.

A continuación envía un `DeviceResponse` firmado al `response_uri` de la sesión.

> ⚠️ **Un mDoc exige `response_uri`.** El *session transcript* que vincula la
> presentación a un verificador incorpora el hash de esa URL. Un flujo que
> carezca de ella no dispone de nada que hashear, y la wallet rechaza presentar
> el mDoc indicando que dicho verificador no puede aceptar credenciales mDoc.

## Paso 3 — Recibir el resultado

### ✅ `presentation.verified`

```json
{
  "event": "presentation.verified",
  "data": {
    "verification_id": "e2d16450-42e8-46a9-ae92-d72af51a7435",
    "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
    "success": true,
    "completed_at": "2026-09-28T13:05:12Z",
    "credentials": [
      {
        "id": "licencia",
        "format": "mso_mdoc",
        "claims": {
          "org.iso.18013.5.1.family_name": "Lovelace",
          "org.iso.18013.5.1.given_name": "Ada",
          "org.iso.18013.5.1.age_over_18": true
        }
      }
    ]
  }
}
```

| Campo | Observaciones |
|---|---|
| `credentials[].format` | `"mso_mdoc"`. |
| `credentials[].claims` | **Indexado por `"<namespace>.<identificador>"`**, la misma forma empleada en la consulta, cualificada por namespace dado que dos namespaces pueden contener un elemento de idéntica denominación. |

> ⚠️ **Las claves del resultado incorporan el namespace como prefijo.** Si se
> solicitó `["org.iso.18013.5.1", "family_name"]`, la clave resultante es
> `"org.iso.18013.5.1.family_name"`, y no `"family_name"`. Constituye el punto en
> el que una integración falla de forma silenciosa si presupone la forma
> correspondiente al SD-JWT.

El valor de `holder_did` procede del namespace meta de la credencial, no de la
`deviceKey`: un mDoc no incorpora DID alguno, y Sovra requiere uno para resolver
las llaves que la cuenta del holder autoriza **en el momento presente**.

### ❌ `presentation.failed`

```json
{
  "event": "presentation.failed",
  "data": {
    "verification_id": "183ee078-69b7-4fa5-9a6f-b40ddbb59ce5",
    "holder_did": "did:sovra:0x90833e…",
    "success": false,
    "error": "missing_required_claim:org.iso.18013.5.1.first_name",
    "completed_at": "2026-09-28T13:06:40Z"
  }
}
```

La lista completa de valores de `error` figura en
[Errores y troubleshooting](06-errores-y-troubleshooting.md#errores-de-presentación).

> El campo `holder_did` presenta valor `null` cuando el fallo se produce antes de
> poder identificar al holder, como en los casos de `malformed_vp_token`,
> `unknown_issuer` o `session_expired`.

## Alcance de la validación efectuada por Sovra

La cascada de comprobaciones se ejecuta en orden y **se interrumpe ante el primer
fallo**:

| # | Comprobación | Fallo asociado |
|---|---|---|
| 1 | El `DeviceResponse` se analiza correctamente | `malformed_vp_token` |
| 2 | Se resuelve el emisor, por la dirección del docType o por el `x5chain` | `unknown_issuer` |
| 3 | La firma del `issuerAuth` valida contra la llave resuelta | `credential_signature_invalid` |
| 4 | El `docType` del MSO firmado coincide con el del documento | `credential_signature_invalid` |
| 5 | Los digests de los elementos divulgados coinciden con `valueDigests` | `invalid_disclosure` |
| 6 | El namespace `io.sovra.meta.1` está completo y correctamente tipado | `missing_mandatory_element:io.sovra.meta.1` |
| 7 | La dirección del emisor del namespace meta coincide con la resuelta | `unknown_issuer` |
| 8 | Están presentes todos los elementos obligatorios congelados en la emisión | `missing_mandatory_element:<ns>.<elemento>` |
| 9 | `validFrom <= ahora < validUntil`, y la firma no presenta fecha futura | `credential_expired` |
| 10 | La `deviceKey` del MSO figura entre las llaves que el holder autoriza actualmente | `holder_key_mismatch` |
| 11 | La firma de device authentication valida contra el session transcript | `holder_key_mismatch`, `device_mac_unsupported`, `unsupported_device_auth_alg` |
| 12 | El estado en cadena no consta como revocado ni suspendido | `credential_revoked`, `credential_suspended`, `status_check_failed` |
| 13 | La consulta DCQL se satisface: docType y cada elemento solicitado | `doctype_mismatch`, `missing_required_claim:<ns>.<el>`, `format_mismatch:<formato>` |

Dos comprobaciones carecen de equivalente en SD-JWT y conviene detallarlas:

**El `docType` firmado debe coincidir con el del documento** (paso 4). El docType
del documento no está firmado y, en la ruta generada, es el elemento que
determinó la llave del emisor. Exigir que el MSO firmado declare la misma cadena
implica que un docType reescrito resolvería a un workspace cuya llave no puede
verificar dicha firma. En la ruta del certificado el docType no determina nada,
pero la comprobación conserva su utilidad: impide presentar como licencia de
conducir una credencial emitida como atestación de edad.

**La dirección del emisor del namespace meta debe coincidir** (paso 7). Es lo que
impide que la ruta del certificado amplíe quién puede atribuirse la condición de
emisor: en ella el docType no identifica a nadie y la cadena identifica
únicamente una llave, de modo que, sin esta comprobación, un workspace con
document signer podría emitir credenciales con la dirección de otro, que es la
clave de indexación de los mapas de revocación y del registro de emisores.

## Errores al crear la sesión

La totalidad corresponde a `400`, con excepción de los relativos al workspace:

| HTTP | `error` | Causa |
|---|---|---|
| 400 | `invalid_dcql_query` | Falta `credentials`, o se encuentra vacío. |
| 400 | `unsupported_dcql_format` | Un `format` distinto de `mso_mdoc`, `vc+sd-jwt` o `dc+sd-jwt`. |
| 400 | `invalid_mdoc_claim_path` | Un `path` de `mso_mdoc` que no consiste exactamente en dos cadenas. |
| 400 | `mixed_dcql_formats` | La consulta combina mDoc con SD-JWT. Deben emplearse dos sesiones. |
| 401 | `invalid_api_key` | API key ausente o inválida. |
| 403 | `workspace_inactive` | Workspace u organización inactivos. |
| 409 | `workspace_not_provisioned` | El aprovisionamiento aún no ha concluido. |

## Ejemplo completo en Node.js

```js
const BASE_URL = process.env.BASE_URL;
const API_KEY = process.env.SOVRA_API_KEY;

const MDL_DOCTYPE = "org.iso.18013.5.1.mDL";
const MDL_NS = "org.iso.18013.5.1"; // atención: el namespace NO es el docType

/** Solicita únicamente la atestación de edad: ni nombre ni fecha de nacimiento. */
async function pedirControlDeEdad() {
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
            id: "edad",
            format: "mso_mdoc",
            meta: { doctype_value: MDL_DOCTYPE },
            claims: [{ path: [MDL_NS, "age_over_18"] }],
          },
        ],
      },
    }),
  });

  const body = await res.json();
  if (!res.ok) throw new Error(body.error);

  // La variante `_ref` mantiene el QR compacto con independencia del tamaño
  // que alcance el DCQL.
  return { sessionId: body.session_id, qr: body.authorization_request_uri_ref };
}

/** Las claves del resultado se entregan cualificadas por namespace. */
function leerClaim(credencial, namespace, elemento) {
  return credencial.claims[`${namespace}.${elemento}`];
}

app.post("/webhooks/sovra", verificarFirmaHmac, (req, res) => {
  const { event, data } = req.body;

  if (event === "presentation.verified") {
    const lic = data.credentials.find((c) => c.id === "edad");
    const mayor = leerClaim(lic, MDL_NS, "age_over_18");
    resolverSesion(data.verification_id, { mayor });
  }

  if (event === "presentation.failed") {
    // `error` identifica con precisión la comprobación que se interrumpió.
    rechazarSesion(data.verification_id, data.error);
  }

  res.sendStatus(200);
});
```

---

**Anterior:** [← 3. Emisión de mDocs](03-emision-de-mdocs.md) · **Siguiente:** [5. Referencia de la API →](05-referencia-api.md)
