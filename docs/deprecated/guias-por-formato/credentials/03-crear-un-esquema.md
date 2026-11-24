# 3. Crear un esquema

Un **esquema** es el molde de una credencial: define qué campos lleva, cómo se
serializa y cuánto vale lo que se emite con él. Nada se puede emitir sin uno, y el
esquema queda congelado dentro de cada credencial que firmás — por eso conviene
pensarlo antes de la primera emisión y no después.

Hay **dos caminos**, y los dos llegan al mismo lugar:

| | |
|---|---|
| **El dashboard** (menú **Schemas**) | El asistente, con vista previa de la tarjeta. Es el camino recomendado para el primer esquema. |
| **La API** (`POST /api/v1/issuer/schemas`) | Para provisionar esquemas desde tu código o desde un script de despliegue. |

## Tabla de contenidos

1. [Los dos identificadores](#los-dos-identificadores)
2. [Camino A — El dashboard](#camino-a--el-dashboard)
3. [Camino B — La API](#camino-b--la-api)
4. [El registro on-chain](#el-registro-on-chain)
5. [Los claims](#los-claims)
6. [El diseño de la tarjeta](#el-diseño-de-la-tarjeta)
7. [Versionado](#versionado)
8. [Borrar un esquema](#borrar-un-esquema)
9. [Checklist](#checklist)

---

## Los dos identificadores

Un esquema tiene dos ids, y confundirlos es el error más común:

| | Qué es | Quién lo usa |
|---|---|---|
| **`schema_id`** | Tu string, por ejemplo `licencia_de_conducir`. **Es estable entre versiones.** | La emisión, el diseño y la revisión. Es el que mandás en el día a día. |
| **`id`** | El UUID de **una fila de versión**. Cambia con cada revisión. | Solo `DELETE /schemas/{id}`. |

Es decir: casi siempre querés el `schema_id`. El UUID aparece una sola vez, para
borrar.

## Camino A — El dashboard

**Schemas → New schema.** El asistente pide:

| Campo | Qué es | Notas |
|---|---|---|
| **Name** | Nombre visible del esquema | De acá se deriva el `schema_id`. |
| **Credential type** | El `vct` de la credencial | Sensible a mayúsculas. Es lo que el verificador debe reconocer. |
| **Validity period** | Cuánto vive la credencial | Formato `^\d+(y\|m\|d)$`: `10y`, `6m`, `30d`. Si no se puede parsear, se usa 1 año en silencio. |
| **Kind** | `credential` o `document` | Para emitir a una wallet: **`credential`**. Para documentos firmados, ver [`../documents/`](../documents/). |
| **Claims** | Los campos | Cada uno con `key`, `label`, `type` (`string`, `number`, `date`, `boolean`) y `required`. |

**Desde el dashboard el `schema_id` se deriva del nombre.** Se toma el *name*, se
pasa a minúsculas, todo lo que no sea `a-z0-9` se convierte en `_`, y se corta a 24
caracteres. "Licencia de Conducir" → `licencia_de_conducir`. **Ese es el valor que
vas a mandar en `schema_id` al crear una oferta** — confirmalo en el listado de
esquemas antes de escribirlo en tu código.

## Camino B — La API

Los mismos campos van en el body, con una diferencia que importa: **acá el
`schema_id` lo elegís vos**, no se deriva del nombre.

```bash
curl -s -X POST "$BASE_URL/api/v1/issuer/schemas" \
  -H "Authorization: Bearer $SOVRA_API_KEY" -H 'Content-Type: application/json' \
  -d '{
    "schema_id": "licencia_de_conducir",
    "credential_type": "DrivingLicence",
    "name": "Licencia de Conducir",
    "validity_period": "10y",
    "claims": [
      {"key": "full_name",      "type": "string", "required": true,  "disclosable": true, "label": "Nombre"},
      {"key": "licence_number", "type": "string", "required": true,  "disclosable": true, "label": "N° de licencia"},
      {"key": "categoria",      "type": "string", "required": false, "disclosable": true, "label": "Categoría"}
    ]
  }'
```

| Campo | Obligatorio | Qué tener en cuenta |
|---|---|---|
| `schema_id` | ✅ | Único en el workspace. Un duplicado se reporta como `validation_error` **sobre `issuer_id`**, con `has already been taken` — no sobre `schema_id`, que es dónde uno lo busca. |
| `credential_type` | ✅ | El `vct`. **No se puede cambiar nunca más**, ni en una revisión. |
| `name` | ✅ | Nombre para mostrar. |
| `claims` | ✅ | Ver [los claims](#los-claims). |
| `validity_period` | ✅ **la clave tiene que estar** | `1y`, `6m`, `30d`, o `null` para que no venza. **Omitir la clave es un `422`**, aunque no quieras vencimiento: hay que mandar `null` explícito. |
| `kind` | — | `credential` (default) o `document`. |
| `format` | — | `vc+sd-jwt` (default) o `mso_mdoc`. **Inmutable de por vida.** Para mDoc, ver [`../credentials-mdoc/`](../credentials-mdoc/). |

**`201 Created`** devuelve el esquema con su `id`, pero **sin `version`** — esa sólo
aparece en el listado. El detalle campo por campo está en
[8. Referencia de la API](08-referencia-api.md#post-apiv1issuerschemas).

## El registro on-chain

> 🔴 **Un esquema recién creado todavía no sirve para emitir.**

Al crearlo, su tipo de credencial se registra en la cadena en segundo plano. Mientras
eso pasa, el esquema está en `registration_status: "pending"` y **cualquier intento
de emitir con él devuelve `409 schema_registration_pending`**.

| `registration_status` | Qué significa |
|---|---|
| `pending` | El registro está en curso. Esperá. |
| `registered` | Listo: recién **acá** se puede emitir. |
| `failed` | El registro agotó sus reintentos. `registration_error` dice por qué, y con ese esquema no se puede emitir nada: hay que crearlo de nuevo. |

**No hay webhook de registro.** La única forma de enterarte es consultar:

```bash
curl -s "$BASE_URL/api/v1/issuer/schemas" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  | jq '.schemas[] | {schema_id, version, registration_status}'
```

Si estás automatizando el alta, ese `GET` es el paso que no podés saltear: consultalo
hasta ver `registered` antes de intentar la primera oferta.

## Los claims

Cada entrada de `claims` se define así:

| Campo | Obligatorio | Qué hace |
|---|---|---|
| `key` | ✅ | El nombre del campo en la credencial. |
| `type` | ✅ | `string`, `uri`, `integer`, `boolean`, `number`, `date`, `datetime`, `bytes`, `driving_privileges` o `attachment`. En `vc+sd-jwt` es **indicativo** —no se rechaza un valor por no coincidir—, salvo `attachment`. |
| `required` | — | Si falta al emitir → `422 missing_claims`. |
| `disclosable` | — | El holder puede retenerlo al presentar. |
| `label` | — | Etiqueta legible, para la wallet. |

Tres cosas que conviene saber antes de cargar el primero:

**1. El `key` es lo único que sobrevive.** El `label` es decorativo: no se persiste
en la credencial. El `key` es el nombre real del campo dentro del SD-JWT, es lo que
ve el ciudadano en la wallet y **es lo que un verificador tiene que pedir, letra por
letra**. Usá `snake_case` y revisá la ortografía: un `fist_name` con errata obliga a
todos los verificadores a pedir `fist_name` para siempre.

**2. El default de `disclosable` no es el mismo en los dos caminos.** El dashboard
marca `disclosable: true` en cada campo, así que la divulgación selectiva funciona
sin configurar nada. **Por API no**: si no mandás `disclosable: true`, el claim no es
divulgable y el holder no puede retenerlo.

**3. Un claim de más es un claim para siempre.** Las credenciales ya emitidas quedan
con el esquema con el que se firmaron; agregar un campo después no las alcanza.

> 💡 **Mantené los esquemas en ~12 claims.** El JWT del emisor lleva un digest `_sd`
> por **cada** claim divulgable, revele el holder uno o todos — unos 44 caracteres
> cada uno. Un esquema de 30 campos infla el QR de respuesta de la wallet más de un
> kilobyte incluso cuando se comparte un solo dato, y un QR muy denso se vuelve
> difícil de escanear.

### Adjuntar un PDF

Un claim de tipo `attachment` transporta un PDF como URI
`data:application/pdf;base64,…`. Tiene tres restricciones:

- **hasta 560.000 bytes** una vez decodificado;
- sólo en esquemas `vc+sd-jwt` de kind `credential`, y **tiene que ser
  `disclosable`**;
- **uno solo por esquema**.

El adjunto viaja a la wallet, pero **no viaja en el webhook**: ahí llega un
descriptor con el tamaño y el tipo. Ver
[6. Webhooks](06-webhooks.md#credentialissued).

## El diseño de la tarjeta

Cómo se dibuja la credencial en la wallet se define aparte, y **siempre sobre la
última versión** del esquema:

```bash
curl -s -X PUT "$BASE_URL/api/v1/issuer/schemas/licencia_de_conducir/design" \
  -H "Authorization: Bearer $SOVRA_API_KEY" -H 'Content-Type: application/json' \
  -d '{
    "name": "Licencia de Conducir",
    "display": {
      "title": {"text": "Licencia de Conducir"},
      "subtitle": {"path": ["$.full_name"], "fallback": "Titular"},
      "properties": [{"label": "N° de licencia", "path": ["$.licence_number"]}]
    },
    "styles": { "background": {"color": "#112233"}, "text": {"color": "#ffffff"} }
  }'
```

Dos reglas que concentran casi todos los `422`: cada *mapping* lleva **o** `text` (un
literal) **o** `path` —nunca los dos, nunca ninguno—, y un `path` tiene la forma
`["$.<claim_key>"]` nombrando un claim que el esquema **declare**. Los colores van en
`#rrggbb` y las imágenes no pasan de 256 KB.

**Es un reemplazo, no un parche:** lo que no mandás, no queda. El detalle está en
[8. Referencia de la API](08-referencia-api.md#put-apiv1issuerschemasschema_iddesign).

Un esquema sin diseño se emite igual; la wallet lo muestra con su estilo por defecto.

## Versionado

Editar un esquema **no lo modifica**: crea una **versión nueva**. Las credenciales ya
emitidas quedan ancladas a la versión con la que se emitieron, y al crear una oferta
Sovra usa siempre la más reciente de ese `schema_id`.

```bash
curl -s -X PUT "$BASE_URL/api/v1/issuer/schemas/licencia_de_conducir" \
  -H "Authorization: Bearer $SOVRA_API_KEY" -H 'Content-Type: application/json' \
  -d '{
    "name": "Licencia de Conducir",
    "base_version": 1,
    "validity_period": "10y",
    "claims": [ "…la lista completa, no un parche…" ]
  }'
```

| | |
|---|---|
| **`claims` va entero** | Es la lista nueva completa. Lo que no mandes, desaparece de la versión nueva. |
| **`base_version`** | La versión desde la que empezaste a editar. Si mientras tanto alguien publicó otra desde el dashboard, la llamada devuelve **`409 schema_revision_conflict`** en vez de pisarla: releé y rebasá tus cambios. |
| **Lo que no se puede cambiar** | `schema_id`, `credential_type`, `kind`, `format`, `doc_type` y `namespace`. Si van en el body, **se ignoran en silencio**. |
| **Lo que se hereda** | El diseño, el layout y el estado de registro de la versión anterior. |

## Borrar un esquema

```bash
curl -s -X DELETE "$BASE_URL/api/v1/issuer/schemas/$SCHEMA_UUID" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

Dos cosas: **toma el UUID**, no el `schema_id`, y **borra todas las versiones** de
una vez, de forma atómica. Se rechaza con `409 schema_in_use` si cualquiera de ellas
tiene una oferta o una credencial emitida, **sea cual sea su estado** — una oferta
vencida de hace un año también cuenta.

En la práctica: un esquema que ya se usó en producción no se borra, se versiona.

## Checklist

Antes de pasar a emitir:

- [ ] El esquema existe, y anotaste su **`schema_id`** (el slug, no el UUID).
- [ ] `GET /api/v1/issuer/schemas` lo muestra en **`registration_status: "registered"`**.
- [ ] Los `key` están en `snake_case` y sin erratas — son para siempre.
- [ ] Los claims que el ciudadano debería poder retener están en `disclosable: true`.
- [ ] Los `required` son los que de verdad no pueden faltar: un `required` de más
      rompe la emisión con `422 missing_claims`.
- [ ] *(Opcional)* El esquema tiene un diseño asignado.

---

**Anterior:** [← 2. Primeros pasos](02-primeros-pasos.md) · **Siguiente:** [4. Emisión de credenciales →](04-emision-de-credenciales.md)
