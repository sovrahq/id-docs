# 6. Errores y troubleshooting

Este documento recoge únicamente lo específico de mDoc. Los errores comunes a
ambos formatos —autenticación, workspace y ciclo de vida— figuran en
[Errores de credenciales](../credentials/08-errores-y-troubleshooting.md).

## Tabla de contenidos

1. [Errores al guardar el esquema](#errores-al-guardar-el-esquema)
2. [Errores al crear la oferta](#errores-al-crear-la-oferta)
3. [Errores al crear la sesión de verificación](#errores-al-crear-la-sesión-de-verificación)
4. [Errores de presentación](#errores-de-presentación)
5. [`missing_required_claim` en mDoc](#missing_required_claim-en-mdoc)
6. [Errores de certificado](#errores-de-certificado)
7. [Incidencias frecuentes](#incidencias-frecuentes)
8. [Procedimiento de diagnóstico de un mDoc](#procedimiento-de-diagnóstico-de-un-mdoc)

---

## Errores al guardar el esquema

| `error` | Causa | Solución |
|---|---|---|
| `undefined_iso_elements` | Un `key` que `org.iso.18013.5.1` no define. | El namespace ISO es cerrado. Los campos propios deben alojarse en **un namespace propio, adicional**. |
| `wrong_iso_element_type` | El identificador existe pero con otro tipo. El caso más habitual es `portrait` declarado `string` en lugar de `bytes`. | Véase el [catálogo de elementos](05-referencia-api.md#elementos-del-namespace-iso). |
| `schema_id must be lowercase alphanumeric, - or _` | El `schema_id` de un esquema mDoc contiene caracteres fuera de `^[a-z0-9][a-z0-9_-]*$`. | Se convierte en un segmento del docType; un punto en su interior añadiría un nivel. |
| `doc_type must be a reverse-DNS identifier` | El docType no corresponde al patrón DNS inverso. | Por ejemplo, `org.iso.18013.5.1.mDL`. |
| `mdoc is only available for credential schemas` | Se seleccionó `format: mso_mdoc` con `kind: document`. | Un documento se firma directamente a un PDF, sin wallet ni holder: un mDoc no puede expresar ese modelo. |
| `attachments are only available for vc+sd-jwt schemas` | Un claim de tipo `attachment` en un esquema mDoc. | ISO no define un elemento para un adjunto arbitrario. Debe emplearse `bytes`. |
| `doc_type is only meaningful for mso_mdoc schemas` | Un esquema `vc+sd-jwt` con docType o namespace. | Deben eliminarse ambos campos. |

> **El `format` es inmutable.** Si se revisa un esquema enviando un `format`
> distinto, este se ignora de forma silenciosa y la versión se incrementa igual.
> No constituye un defecto: el docType se deriva del esquema y se firma dentro de
> cada credencial que ya reside en la wallet de un ciudadano.

## Errores al crear la oferta

| HTTP | `error` | Causa | Solución |
|---|---|---|---|
| 422 | `unencodable_claims` | Un valor no admite codificación como CBOR de ISO 18013-5. El campo `details` identifica las claves. | Véase la tabla siguiente. |
| 422 | `missing_claims` | Falta un claim `required`. | El campo `details` contiene la lista. |
| 422 | `document_signer_required` | El esquema declara un docType ISO y el workspace carece de document signer. | Véase la [guía 8](08-habilitar-mdl-iso.md). |
| 422 | `wrong_schema_kind` | El esquema es de kind `document`. | Un mDoc es siempre `credential`. |

### Causas de `unencodable_claims`

| Tipo | Valor incorrecto | Valor esperado |
|---|---|---|
| `number` | `4.5`, o cualquier decimal no entero | Un entero. Un float carece de forma CBOR determinista. |
| `integer` | `"170"` (cadena) | `170` |
| `boolean` | `"true"` (cadena) | `true` |
| `date` | `"10/12/1815"`, `"1815-12-10T00:00:00Z"` | `"1815-12-10"` — exactamente diez caracteres |
| `datetime` | Sin offset de zona horaria | ISO 8601 completo, por ejemplo `"2026-01-15T10:30:00Z"` |
| `bytes` | base64 URL-safe, sin padding o con saltos de línea | **base64 estándar con padding**, sin espacios en blanco |
| `driving_privileges` | `[]`, o una entrada sin `vehicle_category_code` | Al menos una entrada con su categoría |
| `driving_privileges` | `"sign": 70` (valor numérico) | `"sign": "<="`, `"value": "70"` — son de tipo **texto** |

> La comprobación se ejecuta **al crear la oferta**, no al entregar la
> credencial. Se trata de una decisión deliberada: detectarlo en la entrega se
> manifestaría como un `500` en mitad del flujo de la wallet, mucho después de
> que el operador haya finalizado su intervención.

> Un tipo conocido con un valor de forma incorrecta constituye un **error**, no
> una conversión. Admitir una cadena para un claim `integer` produciría una
> credencial que verifica correctamente y afirma algo falso.

## Errores al crear la sesión de verificación

| HTTP | `error` | Causa | Solución |
|---|---|---|---|
| 400 | `unsupported_dcql_format` | Un `format` distinto de `mso_mdoc`, `vc+sd-jwt` o `dc+sd-jwt`. | El campo `details` lo especifica. Debe prestarse atención a valores como `mdoc`, `mso-mdoc` o `mDL`. |
| 400 | `invalid_mdoc_claim_path` | Un `path` de `mso_mdoc` que no consiste exactamente en dos cadenas. | `{"path": ["<namespace>", "<identificador>"]}`. |
| 400 | `mixed_dcql_formats` | La consulta combina mDoc con SD-JWT. | Una presentación transporta una credencial en un formato. Deben emplearse dos sesiones. |
| 400 | `invalid_dcql_query` | Falta `credentials`, o se encuentra vacío. | Debe contener al menos una entrada. |

> Los cuatro se comprueban **al crear la sesión**. De este modo, una consulta que
> el verificador no puede corregir se manifiesta como un `400` síncrono sobre la
> solicitud que la introdujo, en lugar de como un `presentation.failed` que llega
> cuando el ciudadano ya ha escaneado el código y el verificador ha continuado su
> operación.

## Errores de presentación

Se entregan en `data.error` del webhook `presentation.failed`. La validación se
interrumpe ante el primer fallo, por lo que el código indica con precisión hasta
qué punto progresó.

### Formato del `vp_token`

| `error` | Causa |
|---|---|
| `malformed_vp_token` | El `DeviceResponse` no se analiza correctamente como CBOR, o carece de la estructura requerida. |
| `session_expired` | Han transcurrido los 10 minutos de vigencia. |

### Emisor

| `error` | Causa | Solución |
|---|---|---|
| `unknown_issuer` | No se pudo resolver el emisor: la dirección del docType no figura en el registro, o el `x5chain` no encadena a una raíz admitida. | Si el docType es ISO, debe comprobarse que la raíz IACA esté admitida y el DSC registrado. |
| `unknown_issuer` (tras la resolución) | La `issuer_address` del namespace meta no coincide con el workspace resuelto. | Credencial mal formada o atribuida a otro emisor. |
| `credential_signature_invalid` | La firma del `issuerAuth` no valida, **o bien** el `docType` del MSO firmado no coincide con el del documento. | El segundo supuesto corresponde a un docType modificado en tránsito. |
| `invalid_disclosure` | Los digests de los elementos divulgados no coinciden con `valueDigests`. | Habitualmente se debe a un envoltorio tag-24 compuesto incorrectamente por quien generó el mDoc. |

### Namespace meta y elementos obligatorios

| `error` | Causa | Solución |
|---|---|---|
| `missing_mandatory_element:io.sovra.meta.1` | El namespace meta está ausente, incompleto o incorrectamente tipado. | Los seis elementos son obligatorios: constituyen insumos de control aportados por la parte sujeta a control. |
| `missing_mandatory_element:<ns>.<elemento>` | La presentación omitió un elemento que la credencial declara `always shared`. | El conjunto está congelado en la credencial; la wallet debería haberlo incluido en todo caso. |

### Holder

| `error` | Causa |
|---|---|
| `holder_key_mismatch` | La `deviceKey` del MSO no figura entre las llaves que la cuenta del holder autoriza actualmente, habitualmente por tratarse de una passkey rotada. Comprende asimismo el supuesto de una firma de device auth que no valida. |
| `device_mac_unsupported` | La wallet empleó `DeviceMac` en lugar de `DeviceSignature`. |
| `unsupported_device_auth_alg` | Algoritmo de firma de dispositivo no admitido. |

> `holder_key_mismatch` por firma inválida obedece con frecuencia a un nonce
> obsoleto o a una audiencia incorrecta: el nonce y el `client_id` residen
> **dentro** del session transcript, de modo que cualquier discrepancia en ellos
> se manifiesta como un fallo de firma.

### Vigencia y estado

| `error` | Causa |
|---|---|
| `credential_expired` | `ahora >= validUntil`, `ahora < validFrom`, o la firma presenta fecha futura. |
| `credential_revoked` | El índice consta marcado en el mapa de revocación. |
| `credential_suspended` | Ídem, en el de suspensión. |
| `status_check_failed` | No fue posible consultar el estado en la cadena. |

### DCQL

| `error` | Causa | Solución |
|---|---|---|
| `doctype_mismatch` | El `meta.doctype_value` no coincide con el docType de la credencial presentada. | Debe prestarse atención a las mayúsculas: `mDL` ≠ `mdl`. |
| `format_mismatch:<formato>` | La credencial no corresponde al formato solicitado. | Para mDoc, el `format` es `mso_mdoc`. |
| `missing_required_claim:<ns>.<elemento>` | El elemento solicitado no figura en la presentación. | Véase el apartado siguiente. |
| `invalid_dcql_query` | Un `path` que no consiste en un par de cadenas. | |

## `missing_required_claim` en mDoc

Constituye el error más frecuente y obedece, casi invariablemente, a una de tres
causas.

### Causa 1 — Se empleó el docType como namespace

La más habitual de todas, y se produce únicamente con docTypes ISO:

```json
{ "path": ["org.iso.18013.5.1.mDL", "family_name"] }   ❌
{ "path": ["org.iso.18013.5.1",     "family_name"] }   ✅
```

ISO asocia el docType `org.iso.18013.5.1.mDL` con el namespace
`org.iso.18013.5.1`. **Uno no se deriva del otro.** Con el docType generado,
ambas cadenas coinciden, razón por la cual esta incidencia no se manifiesta hasta
la migración a ISO.

### Causa 2 — El `key` no corresponde al declarado en el esquema

El `key` del esquema es el `elementIdentifier` de ISO y constituye un contrato
literal. `first_name` no equivale a `given_name`, y un error tipográfico en el
esquema obliga a todos los verificadores a reproducirlo de forma permanente. Su
valor puede consultarse en el listado de esquemas.

### Causa 3 — El holder no lo compartió

Si el elemento **no** está marcado como `always shared`, el ciudadano puede
deseleccionarlo. No constituye un error de la integración: es el funcionamiento
previsto del formato.

Si un dato resulta imprescindible para el trámite, debe marcarse
`disclosable: false` en el esquema. No obstante, debe tenerse presente que ello
lo incorpora a **todas** las presentaciones de esa credencial de forma
permanente, y que el conjunto queda congelado en las credenciales ya emitidas.

## Errores de certificado

Aplicables únicamente a los docTypes ISO. Se entregan como `422` al admitir una
raíz o registrar un signer:

```json
{ "error": "invalid_certificate", "reason": "dsc_profile.validity_too_long" }
```

La tabla completa de valores de `reason`, con la acción correctiva
correspondiente a cada uno, figura en
[Habilitar mDL ISO](08-habilitar-mdl-iso.md#anexo-2-cuando-el-registro-es-rechazado).

## Incidencias frecuentes

### El QR se escanea pero la wallet no ofrece la credencial

La wallet identifica la credencial **por docType**. Debe comprobarse que el
`meta.doctype_value` del DCQL corresponda al docType exacto de la credencial que
posee el ciudadano, mayúsculas incluidas. En caso de duda, puede omitirse el
campo `meta` de la consulta: en su ausencia se admite cualquier docType.

### La wallet indica que este verificador no puede aceptar mDocs

Falta el `response_uri`. El session transcript que vincula la presentación a un
verificador incorpora el hash de esa URL, y un flujo que carezca de ella no
dispone de nada que hashear. Las sesiones creadas por la API siempre lo incluyen;
en el caso de un verificador propio, debe exponerse explícitamente.

### El retrato se visualiza como texto base64 en la wallet

`portrait` está declarado `string` en lugar de `bytes`. La credencial verifica
correctamente —los digests no evalúan el significado del valor— pero ningún
lector conforme la interpreta adecuadamente. Es precisamente lo que previene
`wrong_iso_element_type` al guardar el esquema, de modo que, si llegó a emitirse,
el esquema se creó con anterioridad a dicha validación.

### La emisión funciona en test y en producción falla con `document_signer_required`

El document signer se configura **por workspace**. Su registro en test no produce
efecto alguno en producción: deben repetirse los tres pasos, con una raíz que el
entorno de producción acepte.

### `credential_expired` en una credencial recién emitida

El esquema no declara `validity_period`, por lo que el mDoc se emitió con **un
año** de vigencia, y no sin vencimiento, que es el comportamiento de un SD-JWT.
Si ese no es el resultado previsto, debe declararse el período en el esquema. Un
mDoc no puede expresar la ausencia de vencimiento: ISO establece `validUntil`
como obligatorio.

### Se perdió el mDoc firmado

No existe endpoint para solicitarlo nuevamente. Se entrega una única vez, en el
webhook `credential.issued`. La única alternativa consiste en revocar la
credencial y emitir una nueva.

## Procedimiento de diagnóstico de un mDoc

Con el valor de `credential` del webhook `credential.issued` en `$CREDENTIAL`:

```bash
# 1. base64url sin padding → bytes CBOR
node -e '
  const b64 = process.argv[1].replace(/-/g, "+").replace(/_/g, "/");
  process.stdout.write(Buffer.from(b64, "base64"));
' "$CREDENTIAL" > mdoc.cbor

# 2. Inspección de la estructura. Cualquier decodificador CBOR resulta válido.
#    Deben localizarse: nameSpaces (los namespaces y sus elementos) e issuerAuth.
npx cbor-cli decode < mdoc.cbor | head -50
```

Elementos que deben verificarse, en orden:

1. **Las claves de `nameSpaces`.** Deben ser dos: el namespace del esquema y
   `io.sovra.meta.1`. Si el primero corresponde al docType completo, el esquema
   emplea el docType generado, y en consecuencia el DCQL debe solicitar con esa
   misma cadena.
2. **`io.sovra.meta.1/namespace`.** Indica, con la firma del emisor, dónde
   residen los elementos. Constituye la respuesta definitiva a qué valor debe
   emplearse en el `path`.
3. **`io.sovra.meta.1/mandatory_elements`.** Qué está obligado a divulgar el
   holder, congelado en el momento de la emisión.
4. **El `docType` del MSO.** Debe coincidir con el del documento, y es el valor
   que corresponde a `meta.doctype_value`.

---

**Anterior:** [← 5. Referencia de la API](05-referencia-api.md) · **Siguiente:** [7. Verificación sin Sovra →](07-verificacion-sin-sovra.md)
