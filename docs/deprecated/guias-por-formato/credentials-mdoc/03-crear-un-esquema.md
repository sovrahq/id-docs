# 3. Crear un esquema

El esquema es lo que convierte una credencial en un mDoc: **lo determina su campo
`format`**, con valor `mso_mdoc`. Todo lo demás —la emisión, la verificación, la
revocación— usa los mismos endpoints que una credencial SD-JWT.

Es también donde se deciden las tres cosas que después no se pueden cambiar: el
**docType**, el **namespace** y el **formato** mismo. Quedan firmados dentro de cada
mDoc que ya resida en la cartera de un ciudadano, de modo que una revisión que los
modificara dejaría huérfanas a las credenciales emitidas.

## Tabla de contenidos

1. [Crear el esquema](#crear-el-esquema)
2. [El registro on-chain](#el-registro-on-chain)
3. [Los tipos de claim](#los-tipos-de-claim)
4. [`required` frente a `always shared`](#required-frente-a-always-shared)
5. [Elección del docType](#elección-del-doctype)
6. [Versionado](#versionado)
7. [Borrar un esquema](#borrar-un-esquema)
8. [Lista de comprobación](#lista-de-comprobación)

---

## Crear el esquema

Los esquemas se crean **desde el dashboard** (menú **Schemas**) o bien **por API**,
con [`POST /api/v1/issuer/schemas`](06-referencia-api.md#post-apiv1issuerschemas): lo
que determina el formato es el campo `format`, con valor `"mso_mdoc"`. El dashboard es
el camino recomendado para el primer esquema, por cuanto el asistente valida el
namespace ISO elemento por elemento.

En el asistente, el campo determinante es **Format**:

| Modo del operador | Etiqueta mostrada |
|---|---|
| Normal | *Standard (recommended)* · **Mobile driving license (mDoc)** |
| Developer | *W3C Verifiable Credential (JSON / JWT)* · **mDoc (CBOR, ISO 18013-5)** |

Seleccionado `mDoc`, el resto del asistente solicita:

| Campo | Descripción | Observaciones |
|---|---|---|
| **Name** | Nombre visible | De él se deriva el `schema_id`. |
| **Credential type** | Lo que el emisor registra en la cadena | Sensible a mayúsculas. Se transporta en el namespace meta. |
| **Validity period** | Vigencia de la credencial | `^[1-9]\d*(y\|m\|d)$`: `10y`, `6m`, `30d`. |
| **Kind** | Siempre **`credential`** | mDoc **no** admite `kind: "document"`: un documento se firma directamente a un PDF, sin wallet ni holder, y un mDoc no puede expresar ese modelo. |
| **Document type** | El docType | Generado por defecto. Véase [Elección del docType](#elección-del-doctype). |
| **Claims** | Los elementos de datos | Cada uno con `key`, `type`, `required` y `disclosable`. |

Dos reglas propias del formato:

**1. El `schema_id` tiene un alfabeto restringido.** Para `mso_mdoc` debe
corresponder al patrón `^[a-z0-9][a-z0-9_-]*$` —minúsculas, dígitos, `-` y `_`—
dado que el `schema_id` **se convierte en un segmento del docType**, y un punto
en su interior añadiría un nivel de forma involuntaria. La regla no se aplica a
los esquemas `vc+sd-jwt`, a fin de no invalidar retroactivamente identificadores
ya en uso.

**2. El `key` de cada claim es el `elementIdentifier` de ISO.** No es un elemento
decorativo: es lo que se transporta en el `IssuerSignedItem`, lo que la wallet
muestra y **lo que un verificador debe solicitar de forma literal** en el DCQL.
En el namespace ISO, además, el conjunto de identificadores válidos es cerrado,
como se detalla más adelante.

### Por API

Los mismos campos viajan en el cuerpo, y allí **el `schema_id` lo elige quien
integra**: no se deriva del nombre.

```bash
curl -s -X POST "$BASE_URL/api/v1/issuer/schemas" \
  -H "Authorization: Bearer $SOVRA_API_KEY" -H 'Content-Type: application/json' \
  -d '{
    "schema_id": "licencia-de-conducir",
    "credential_type": "DrivingLicence",
    "name": "Licencia de Conducir",
    "format": "mso_mdoc",
    "namespace": "org.iso.18013.5.1",
    "validity_period": "10y",
    "claims": [
      {"key": "family_name", "type": "string",  "required": true,  "disclosable": false},
      {"key": "given_name",  "type": "string",  "required": true,  "disclosable": false},
      {"key": "birth_date",  "type": "date",    "required": true,  "disclosable": false},
      {"key": "portrait",    "type": "bytes",   "required": true,  "disclosable": false},
      {"key": "age_over_18", "type": "boolean", "required": false, "disclosable": true}
    ]
  }'
```

Dos advertencias que no aparecen en el asistente:

- **`validity_period` ha de estar presente**, aun con valor `null`; su omisión
  constituye un `422`. En mDoc, la ausencia de un período se resuelve con una
  vigencia de 1 año, por cuanto ISO exige `validUntil`.
- **`disclosable` no tiene el mismo valor por defecto que en el dashboard.** Allí los
  claims opcionales se marcan divulgables; por API, lo que no se declare
  `disclosable: true` queda como `always shared`.

El detalle campo por campo figura en
[6. Referencia de la API](06-referencia-api.md#post-apiv1issuerschemas).

## El registro on-chain

> 🔴 **Un esquema recién creado no admite emisión.**

Su tipo de credencial se registra en la cadena en segundo plano. Mientras ello
ocurre, el esquema permanece en `registration_status: "pending"` y la creación de una
oferta devuelve `409 schema_registration_pending`.

| `registration_status` | Significado |
|---|---|
| `pending` | El registro está en curso. |
| `registered` | Recién **aquí** resulta posible emitir. |
| `failed` | El registro agotó sus reintentos; `registration_error` indica el motivo. El esquema ha de crearse de nuevo. |

**No existe webhook de registro.** Corresponde consultar:

```bash
curl -s "$BASE_URL/api/v1/issuer/schemas" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  | jq '.schemas[] | select(.format == "mso_mdoc") | {schema_id, version, doc_type, registration_status}'
```

Ese listado es asimismo **el único lugar donde consta el `doc_type` efectivamente
generado**, que hasta el aprovisionamiento del workspace figura en `null`.

## Los tipos de claim

Un mDoc codifica cada valor **estrictamente según el tipo declarado**. Un valor
con una forma incorrecta se rechaza en lugar de convertirse: una conversión
produciría una credencial que verifica correctamente y afirma algo falso.

| Tipo | Valor admitido por la API | Representación en CBOR |
|---|---|---|
| `string` | Texto | Texto |
| `uri` | Una URL, como texto | Texto |
| `integer` | Un entero | Entero |
| `number` | Un número **entero** — `4.0` es válido, `4.5` no | Entero |
| `boolean` | `true` / `false` | Booleano |
| `date` | `"YYYY-MM-DD"` | Tag `full-date` |
| `datetime` | ISO 8601 completo | Tag `tdate` |
| `bytes` | **base64 estándar con padding** | Byte string |
| `driving_privileges` | Array de categorías (ISO 18013-5 §7.2.4) | Array de mapas con fechas etiquetadas |

> **`number` admite únicamente valores enteros**, dado que un float carece de
> codificación CBOR determinista. Asignarle una implicaría que un verificador
> pudiera calcular sobre la misma credencial un hash distinto del empleado en la
> firma. La comprobación se realiza al crear la oferta, no al entregar la
> credencial, de modo que el error constituya un `422` sobre la solicitud que lo
> introdujo y no un `500` en mitad del flujo de la wallet, mucho después de que
> el operador haya finalizado su intervención.

> **`bytes` se expresa en base64 estándar, con padding, y exclusivamente así.**
> Sin alfabeto URL-safe y sin espacios en blanco. Se trata de un cuerpo de
> solicitud HTTP, no de una URL: admitir cuatro grafías del mismo valor es el
> origen de discrepancias entre emisores respecto de lo efectivamente enviado. La
> credencial transporta los bytes decodificados, de modo que la wallet renderiza
> la imagen suministrada por el emisor y no una cadena base64.

> `attachment` **no existe en mDoc**. ISO 18013-5 no define un elemento para un
> documento adjunto arbitrario, y `bytes` ya cubre el caso de un archivo. Es
> exclusivo de `vc+sd-jwt`.

### `driving_privileges`

El único tipo no escalar. ISO 18013-5 §7.2.4 lo define como un array de
categorías de vehículo:

```json
[
  {
    "vehicle_category_code": "B",
    "issue_date": "2020-03-15",
    "expiry_date": "2030-03-15"
  },
  { "vehicle_category_code": "A" }
]
```

| Campo | Obligatorio | Observaciones |
|---|---|---|
| `vehicle_category_code` | ✅ | La categoría: `B` para automóvil, `A` para motocicleta, `C` para camión. |
| `issue_date` / `expiry_date` | ❌ | `YYYY-MM-DD`. Se codifican como `full-date` etiquetadas y anidadas. |
| `codes` | ❌ | Las restricciones de la categoría, con el formato que documenta la [guía de emisión](04-emision-de-mdocs.md#codes-las-restricciones-de-cada-categoría). Se omite cuando no se dispone de un catálogo de restricciones. |

> **Un array vacío se rechaza.** ISO exige al menos un privilegio de conducción,
> y un mDL que no declara ninguno constituye una licencia sin habilitación
> alguna.

Constituye un tipo específico y no JSON libre precisamente por las fechas: un
tipo genérico de «array de objetos» las transportaría como texto, y el resultado
sería una credencial que verifica correctamente y de la cual ningún lector
conforme puede extraer las fechas.

## `required` frente a `always shared`

Son dos conceptos distintos, presentados en el dashboard como dos etiquetas
independientes:

| Etiqueta | Campo | Significado |
|---|---|---|
| **`required`** | `required: true` | El claim **debe figurar en la oferta**. En su ausencia, `422 missing_claims`. Es una regla de emisión. |
| **`always shared`** | `disclosable: false` | El holder **no puede retenerlo** en una presentación. Es una regla de presentación. |

Un elemento puede ser simultáneamente `required` y divulgable —obligatorio de
cargar, opcional de mostrar—, o no cumplir ninguna de las dos condiciones.

El conjunto `always shared` se **congela dentro de la credencial** en el momento
de emitirla, en `io.sovra.meta.1/mandatory_elements`, agrupado por namespace:

- Un verificador no necesita consultar el esquema para determinar cuáles son.
- Una revisión posterior del esquema no modifica lo que una credencial ya emitida
  está obligada a divulgar.
- Si la presentación omite uno, se produce el fallo
  `missing_mandatory_element:<namespace>.<elemento>`.

La agrupación por namespace obedece a que un identificador solo es único dentro
de uno: un documento con el namespace ISO y otro doméstico puede contener dos
`document_number`, y una lista plana no permitiría distinguir cuál.

## Elección del docType

### Generado (opción por defecto)

No requiere ninguna acción. Sovra compone:

```
io.sovra.<dirección-eoa-del-workspace-en-minúsculas>.<schema_id>.1
```

y el namespace es **esa misma cadena**. Un verificador lee la dirección del
emisor directamente del docType y la resuelve contra SovraChain. No se requiere
certificado alguno.

### ISO (definido por el operador)

Para emitir una licencia de conducir reconocible fuera de Sovra, el esquema debe
declarar el docType de ISO:

| | |
|---|---|
| **Document type** | `org.iso.18013.5.1.mDL` |
| **Namespace** | `org.iso.18013.5.1` |

Ambos deben ser identificadores DNS inversos y **son sensibles a mayúsculas**:
presentan la forma de nombres de host, pero no lo son —nada los resuelve y ningún
lector los convierte a minúsculas antes de compararlos—, por lo que `mdl` y `mDL`
constituyen dos tipos de documento distintos.

> ⚠️ **Declarar un docType ISO exige un document signer registrado.** Es lo que
> habilita la opción en el asistente, y en su ausencia la emisión falla con
> `document_signer_required`. El procedimiento completo figura en
> [Habilitar mDL ISO](09-habilitar-mdl-iso.md).

### El namespace ISO es un conjunto cerrado

`org.iso.18013.5.1` define con exactitud qué identificadores existen y el
significado de cada uno. Un lector que reciba `color_favorito` en dicho namespace
habrá recibido algo que la norma no contempla, por lo que el dashboard lo rechaza
al guardar el esquema:

| Error | Causa |
|---|---|
| `undefined_iso_elements` | Un `key` que `org.iso.18013.5.1` no define. |
| `wrong_iso_element_type` | El identificador existe, pero con otro tipo. Por ejemplo, `portrait` declarado `string` en lugar de `bytes`. |

El catálogo completo figura en la
[Referencia](06-referencia-api.md#elementos-del-namespace-iso).

**Para incorporar campos propios, debe añadirse un namespace adicional**, que es
el mecanismo previsto por ISO para extender un documento y el que AAMVA ya emplea
con `org.iso.18013.5.1.aamva`. El principio es: conjunto fijo dentro del namespace
ISO, extensible junto a él. Un esquema que declare cualquier otro namespace no se
contrasta con la lista de ISO.

> La ausencia de elementos obligatorios **no** constituye un error al guardar: un
> esquema es una plantilla, y un emisor que componga su mDL de forma incremental
> no podría conservar su trabajo. Lo que debe estar completo es la credencial.

## Versionado

La edición de un esquema **no lo modifica**: incrementa su versión. Las
credenciales ya emitidas permanecen ancladas a la versión con la que se
emitieron, y al crear una oferta se emplea siempre la más reciente de ese
`schema_id`.

> ⚠️ **El `format` es inmutable entre revisiones**, al igual que `kind` y
> `credential_type`. Si se envía `format` en una revisión, se ignora. El docType
> se deriva del esquema y se firma dentro de cada credencial que ya reside en la
> wallet de un ciudadano: una revisión que lo modificara las dejaría huérfanas.

En el dashboard, esta acción corresponde al botón **Revise (v2)**; por API, a
[`PUT /api/v1/issuer/schemas/{schema_id}`](06-referencia-api.md#put-apiv1issuerschemasschema_id--delete-apiv1issuerschemasid),
que exige un `base_version` y rechaza con `409 schema_revision_conflict` toda revisión
que no parta de la última versión.

> 🔴 **Un esquema recién creado no admite emisión.** Nace en
> `registration_status: "pending"` mientras su tipo de credencial se registra
> on-chain; la creación de una oferta devuelve `409 schema_registration_pending` hasta
> que pase a `registered`. **No existe webhook de registro**: ha de consultarse
> `GET /api/v1/issuer/schemas`.

## Borrar un esquema

```bash
curl -s -X DELETE "$BASE_URL/api/v1/issuer/schemas/$SCHEMA_UUID" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

Toma el **UUID** de una fila de versión —no el `schema_id`— y alcanza a **todas** las
versiones de una vez. Se rechaza con `409 schema_in_use` si alguna registra ofertas o
credenciales emitidas, sea cual fuere su estado.

## Lista de comprobación

Antes de emitir el primer mDoc:

- [ ] El esquema tiene `format: "mso_mdoc"` y `kind: credential`.
- [ ] `GET /api/v1/issuer/schemas` lo muestra en **`registration_status: "registered"`**.
- [ ] Anotaste el `schema_id`, y el `doc_type` que figura en ese listado.
- [ ] El `namespace` es el que los verificadores van a usar en el `path` del DCQL.
- [ ] Los elementos obligatorios de ISO figuran con `required: true` y
      `disclosable: false`, si el esquema es una mDL.
- [ ] El `schema_id` contiene únicamente minúsculas, dígitos, `-` o `_`.
- [ ] Cada claim declara el `type` correcto, en particular `portrait` como `bytes` y
      `driving_privileges` como su tipo específico.
- [ ] Están identificados los elementos `always shared` y los que no lo son.
- [ ] Si declaraste un `doc_type` propio, o el docType es ISO, el workspace tiene un
      document signer registrado ([guía 9](09-habilitar-mdl-iso.md)).

---

**Anterior:** [← 2. Primeros pasos](02-primeros-pasos.md) · **Siguiente:** [4. Emisión de mDocs →](04-emision-de-mdocs.md)
