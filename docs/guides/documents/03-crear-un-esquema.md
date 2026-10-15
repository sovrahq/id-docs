# 3. Crear un esquema

Un **esquema** es el molde del documento: define qué claims lleva, cuánto vale, y
—opcionalmente— cómo se dibuja la hoja. Sin un esquema de kind `document` no se puede
emitir nada, y el esquema queda congelado dentro de la firma de cada documento, así
que conviene pensarlo antes de la primera emisión.

Hay dos caminos:

| | |
|---|---|
| **El dashboard** (menú **Schemas**) | El asistente completo, **y el único lugar donde se diseña el layout**. Es el camino recomendado. |
| **La API** (`POST /api/v1/issuer/schemas`) | Para provisionar el esquema desde tu código. Hay que mandar `"kind": "document"`. **El layout queda sin definir**, y sin layout el documento se emite y se verifica igual, pero no hay hoja para renderizar. |

## Tabla de contenidos

1. [Camino A — El dashboard](#camino-a--el-dashboard)
2. [Camino B — La API](#camino-b--la-api)
3. [El registro on-chain](#el-registro-on-chain)
4. [Los claims](#los-claims)
5. [El layout](#el-layout)
6. [Versionado](#versionado)
7. [Borrar un esquema](#borrar-un-esquema)
8. [Checklist](#checklist)

---

## Camino A — El dashboard

El asistente tiene cuatro pasos: **Metadata → Claims → Layout → Review**.

### Metadata

| Campo | Qué es | Notas |
|---|---|---|
| **Type** | `Credential` o `Document` | Elegí **`Document`**: *"Signed and issued directly by you, no wallet and no holder. Verified by link."* |
| **Display name** | Nombre visible del esquema | De acá se deriva el `schema_id`. |
| **Credential type** | El `vct` del documento | PascalCase, sensible a mayúsculas (`Document`, `ConstanciaDeEstudios`). Es contra este valor que se comprueba on-chain si el emisor está autorizado. |
| **Validity** | Cuánto vive el documento | Formato `^\d+(y\|m\|d)$`: `1y`, `6m`, `30d`. Define el `exp` del JWT. |
| **Schema ID** | El identificador legible | Se deriva del *display name*; es el valor que vas a mandar en `schema_id` al emitir. |

> El **kind** es lo que separa un documento de una credencial. Un esquema de kind
> `credential` no sirve acá: la emisión responde `422 wrong_schema_kind`.

### Claims

Una fila por campo, con **claim key**, **label**, **type** y **required**:

| Columna | Qué es |
|---|---|
| **Claim key** | El nombre real del campo dentro del `credential`. Es lo que mandás en `claims` al emitir y lo que un layout bindea. |
| **Type** | `string`, `number`, `date` o `boolean`. |
| **Label** | Etiqueta legible, para la UI y el layout. **No se persiste en el `credential`.** |
| **Required** | Si es `true` y falta al emitir → `422 missing_claims`. |

Ejemplo, el esquema `document_example` que usan estas guías:

| Claim key | Label | Type | Required |
|---|---|---|---|
| `date` | Date | `date` | ✅ |
| `idDocument` | ID Document | `number` | ✅ |
| `content` | Content | `string` | ✅ |
| `isConfidential` | Confidencial | `boolean` | ✅ |

## Camino B — La API

Los mismos campos van en el body. Lo único que no se puede hacer por API es el
layout.

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
| `disclosable` | No tiene efecto: un documento no tiene holder que negocie divulgación. |

A diferencia del dashboard, acá **el `schema_id` lo elegís vos**: no se deriva del
nombre. El detalle campo por campo está en
[8. Referencia de la API](08-referencia-api.md#esquemas-de-kind-document).

## El registro on-chain

> 🔴 **Un esquema recién creado todavía no sirve para emitir.**

Su tipo de credencial se registra en la cadena en segundo plano. Mientras eso pasa el
esquema está en `registration_status: "pending"`, y **`POST /issuer/documents`
responde `409 schema_registration_pending`**.

| `registration_status` | Qué significa |
|---|---|
| `pending` | El registro está en curso. Esperá. |
| `registered` | Listo: recién **acá** se puede emitir. |
| `failed` | El registro agotó sus reintentos. `registration_error` dice por qué; hay que crear el esquema de nuevo. |

**No hay webhook de registro.** La única forma de enterarte es consultar:

```bash
curl -s "$BASE_URL/api/v1/issuer/schemas" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  | jq '.schemas[] | select(.kind == "document") | {schema_id, version, registration_status}'
```

## Los claims

Tres cosas que conviene saber antes de guardar el primer esquema:

**1. El `key` es lo único que sobrevive.** El `label` es decorativo. El `key` es el
nombre del campo dentro del JWT firmado y **es lo que tu código y cualquier
renderizador van a buscar, letra por letra**. Revisá la ortografía: una errata en un
`key` queda dentro de la firma de todos los documentos emitidos con esa versión.

**2. `required` es sobre la emisión, no sobre la lectura.** Marcar un campo como
requerido solo significa que **tiene que estar presente al emitir**. No afecta quién
puede leerlo: en un documento todos los claims van en el payload, a la vista de
cualquiera que tenga el string.

**3. `disclosable` no cambia nada acá.** El dashboard marca `disclosable: true` en
cada claim y el campo aparece en las respuestas de la API, pero un documento no tiene
holder que negocie divulgación: la lista de disclosures del SD-JWT está vacía y todos
los claims son visibles. Es relevante solo para esquemas de kind `credential`.

> ⚠️ **Lo que no declares, no se firma.** Al emitir, las claves que el esquema no
> declara **se descartan en silencio** antes de firmar. Un claim que te olvidaste de
> agregar no rompe la emisión: simplemente no queda en el documento.

## El layout

El paso **Layout** del asistente es un editor visual: se arrastran los claims sobre
una hoja A4 (794 × 1123 px, la proporción A4 a 96 dpi).

Del panel izquierdo salen dos cosas por cada claim — **Label** (el texto fijo) y
**Value** (el campo dinámico) — más elementos libres: **Add title**, **Add text**,
**Add line**, **Add box**, **Add image** y **Upload template** para una imagen de
fondo. Un `Preview` muestra la hoja con datos de ejemplo.

> El propio editor lo dice: *"This is presentation only, it is not part of what gets
> signed."* El layout **no está cubierto por la firma**. Define cómo se dibuja la
> hoja, no qué dice el documento.

**El layout es opcional**, y **no se puede definir por API**: no hay endpoints para
el layout, ni en la creación ni en la revisión. Sin layout la emisión funciona igual;
lo que se pierde es el renderizado, porque la respuesta pública no trae con qué
dibujar la hoja.

Dónde aparece después: en el campo `layout` de `GET /api/v1/issuer/schemas` —sólo en
el listado— y en `GET /api/v1/documents/{id}`, para que quien recibe el documento
pueda dibujarlo. Los detalles del objeto y cómo recorrerlo están en
[6. Renderizado de la hoja](06-renderizado.md).

## Versionado

Editar un esquema **no lo modifica**: publica una **versión nueva**
(`document_example · v1 → v2`). Los documentos ya emitidos quedan anclados a la
versión con la que se emitieron y siguen siendo válidos. Al emitir, Sovra usa siempre
la versión más reciente de ese `schema_id`.

Cuatro campos **no** pueden cambiar entre versiones: el **Schema ID**, el
**Credential type** (es el `vct`, y los verificadores lo reconocen), el `kind` y el
`format`.

Por API la revisión es
[`PUT /api/v1/issuer/schemas/{schema_id}`](08-referencia-api.md#esquemas-de-kind-document),
que pide un `base_version` —la versión desde la que empezaste a editar— y devuelve
`409 schema_revision_conflict` si alguien publicó otra mientras editabas. `claims` va
**entero**: es la lista nueva completa, no un parche. **La versión nueva hereda el
layout de la anterior.**

## Borrar un esquema

```bash
curl -s -X DELETE "$BASE_URL/api/v1/issuer/schemas/$SCHEMA_UUID" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

Dos cosas: **toma el UUID** de una fila de versión, no el `schema_id`, y **borra
todas las versiones** de una vez. Se rechaza con `409 schema_in_use` si cualquiera de
ellas tiene documentos emitidos, sea cual sea su estado.

## Checklist

Antes de pasar a emitir:

- [ ] El esquema es de kind **`document`**, y anotaste su **`schema_id`** (el slug,
      no el UUID).
- [ ] `GET /api/v1/issuer/schemas` lo muestra en **`registration_status: "registered"`**.
- [ ] Anotaste los `key` y los `type` de cada claim, y cuáles son `required`.
- [ ] Los `key` están sin erratas — quedan dentro de la firma.
- [ ] *(Opcional)* El esquema tiene un layout diseñado, si vas a renderizar la hoja.

---

**Anterior:** [← 2. Primeros pasos](02-primeros-pasos.md) · **Siguiente:** [4. Emisión de documentos →](04-emision-de-documentos.md)
