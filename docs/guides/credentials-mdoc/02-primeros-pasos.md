# 2. Primeros pasos

Requisitos para emitir el primer mDoc: un workspace aprovisionado, un esquema de
formato `mso_mdoc`, una API key y un webhook.

## Tabla de contenidos

1. [Entornos](#entornos)
2. [Paso 1 — Workspace aprovisionado](#paso-1--workspace-aprovisionado)
3. [Paso 2 — Crear un esquema mDoc](#paso-2--crear-un-esquema-mdoc)
4. [Los tipos de claim](#los-tipos-de-claim)
5. [`required` frente a `always shared`](#required-frente-a-always-shared)
6. [Elección del docType](#elección-del-doctype)
7. [Versionado](#versionado)
8. [Paso 3 — API key y webhook](#paso-3--api-key-y-webhook)
9. [Paso 4 — Primera llamada](#paso-4--primera-llamada)
10. [Lista de comprobación](#lista-de-comprobación)

---

## Entornos

| | Test | Producción |
|---|---|---|
| **API** | `https://test-api-sovra.flagonsa.com` | `https://api.sovra.io` |
| **Dashboard** | `https://test-issuer-sovra.flagonsa.com` | `https://issuer.sovra.io` |
| **RPC de SovraChain** | `https://rpc.testnet.sovra.io` | `https://rpc.sovra.io` |
| **Chain ID** | `65536101` | `65536001` |

```bash
export BASE_URL="https://test-api-sovra.flagonsa.com"
export SOVRA_API_KEY="sovra_sk_..."
```

La especificación OpenAPI del entorno se encuentra en `GET $BASE_URL/openapi/api`.

## Paso 1 — Workspace aprovisionado

El procedimiento es idéntico al de cualquier credencial: el acceso se realiza por
invitación y, al aceptarla, se crean el usuario, la organización y el workspace.
Este último se aprovisiona contra SovraChain y queda con un DID `did:sovra:0x…`.
El detalle completo figura en
[Primeros pasos de credenciales](../credentials/02-primeros-pasos.md#paso-1--acceder-al-dashboard).

Lo relevante en este contexto: **el mDoc requiere además la dirección EOA del
workspace**. De ella se deriva el docType generado
(`io.sovra.<dirección>.<schema_id>.1`) y es la clave de indexación de los mapas de
revocación. Mientras el aprovisionamiento no concluya, el docType se muestra vacío
en el dashboard y cualquier llamada responde:

```json
{ "error": "workspace_not_provisioned" }
```

## Paso 2 — Crear un esquema mDoc

Los esquemas se crean **desde el dashboard** (menú **Schemas**), no por API.

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
| `codes` | ❌ | Las restricciones de la categoría, con el formato que documenta la [guía de emisión](03-emision-de-mdocs.md#codes-las-restricciones-de-cada-categoría). Se omite cuando no se dispone de un catálogo de restricciones. |

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
> [Habilitar mDL ISO](08-habilitar-mdl-iso.md).

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
[Referencia](05-referencia-api.md#elementos-del-namespace-iso).

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

En el dashboard, esta acción corresponde al botón **Revise (v2)**.

## Paso 3 — API key y webhook

Idénticos a los de cualquier credencial:

- **API key**: Settings → API keys. Prefijo `sovra_sk_`, se muestra **una única
  vez**. Se envía como `Authorization: Bearer sovra_sk_...`.
- **Webhook**: Settings → Webhook. Una URL HTTPS y un secreto para la firma HMAC.

El detalle figura en [Primeros pasos de credenciales](../credentials/02-primeros-pasos.md#paso-4--crear-una-api-key)
y en [Webhooks](../credentials/05-webhooks.md).

## Paso 4 — Primera llamada

```bash
curl -s -X POST "$BASE_URL/api/v1/issuer/credential-offer" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "schema_id": "licencia_de_conducir",
    "claims": {
      "family_name": "Lovelace",
      "given_name": "Ada",
      "birth_date": "1815-12-10",
      "issue_date": "2026-01-15",
      "expiry_date": "2036-01-15",
      "issuing_country": "MX",
      "issuing_authority": "Nuevo León",
      "document_number": "NL-0001",
      "un_distinguishing_sign": "MEX",
      "portrait": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
      "driving_privileges": [{ "vehicle_category_code": "B" }],
      "age_over_18": true
    }
  }'
```

Una respuesta `201` con `offer_uri` confirma que el esquema, la API key y el
aprovisionamiento son correctos.

## Lista de comprobación

- [ ] El workspace muestra su DID y su dirección EOA en **Settings → Workspace**.
- [ ] El esquema está creado con **Format: mDoc** y **Kind: credential**.
- [ ] El `schema_id` contiene únicamente minúsculas, dígitos, `-` o `_`.
- [ ] Cada claim declara el `type` correcto, en particular `portrait` como `bytes` y `driving_privileges` como su tipo específico.
- [ ] Están identificados los elementos `always shared` y los que no lo son.
- [ ] Si el docType es ISO: existe un document signer registrado ([guía 8](08-habilitar-mdl-iso.md)).
- [ ] La API key se almacena fuera del código fuente.
- [ ] El webhook responde `2xx` y valida la firma HMAC.

---

**Anterior:** [← 1. Introducción](01-introduccion.md) · **Siguiente:** [3. Emisión de mDocs →](03-emision-de-mdocs.md)
