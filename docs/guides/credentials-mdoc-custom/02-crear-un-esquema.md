# 2. Crear el esquema

El esquema decide tres cosas que después no pueden cambiarse: el formato, el docType y el namespace. Y decide, claim por claim, qué es obligatorio y qué viaja siempre.

## Tabla de contenidos

1. [El esquema](#el-esquema)
2. [`required` frente a `always shared`](#required-frente-a-always-shared)
3. [Los atributos y su clave](#los-atributos-y-su-clave)
4. [Las claves propias, y el porqué de cada nombre](#las-claves-propias-y-el-porque-de-cada-nombre)
5. [Tres casos que ISO no cubre](#tres-casos-que-iso-no-cubre)

---

## El esquema

```bash
curl -X POST "$BASE_URL/api/v1/issuer/schemas" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "schema_id": "driving_licence_v2",
    "credential_type": "DrivingLicence",
    "name": "Driving Licence v2",
    "format": "mso_mdoc",
    "namespace": "mx.gob.nl.icv.1",
    "validity_period": "4y",
    "claims": [
      {"key": "given_name",           "label": "Nombre",                 "type": "string",             "required": true,  "disclosable": false},
      {"key": "family_name",          "label": "Apellido paterno",       "type": "string",             "required": true,  "disclosable": false},
      {"key": "second_family_name",   "label": "Apellido materno",       "type": "string",             "required": false, "disclosable": false},
      {"key": "birth_date",           "label": "Fecha de nacimiento",    "type": "date",               "required": true,  "disclosable": true},
      {"key": "document_number",      "label": "Folio de licencia",      "type": "string",             "required": true,  "disclosable": false},
      {"key": "issue_date",           "label": "Fecha de expedición",    "type": "date",               "required": true,  "disclosable": false},
      {"key": "expiry_date",          "label": "Fecha de vencimiento",   "type": "date",               "required": true,  "disclosable": false},
      {"key": "driving_privileges",   "label": "Tipo y restricciones",   "type": "driving_privileges", "required": true,  "disclosable": false},
      {"key": "portrait",             "label": "Foto",                   "type": "bytes",              "required": true,  "disclosable": false},
      {"key": "issuing_authority",    "label": "Autorizado por",         "type": "string",             "required": true,  "disclosable": false},

      {"key": "signature_usual_mark", "label": "Firma",                  "type": "bytes",              "required": false, "disclosable": true},
      {"key": "street",               "label": "Calle",                  "type": "string",             "required": false, "disclosable": true},
      {"key": "exterior_number",      "label": "Número exterior",        "type": "string",             "required": false, "disclosable": true},
      {"key": "interior_number",      "label": "Número interior",        "type": "string",             "required": false, "disclosable": true},
      {"key": "neighborhood",         "label": "Colonia",                "type": "string",             "required": false, "disclosable": true},
      {"key": "resident_city",        "label": "Municipio",              "type": "string",             "required": false, "disclosable": true},
      {"key": "resident_postal_code", "label": "Código postal",          "type": "string",             "required": false, "disclosable": true},
      {"key": "issuing_country",      "label": "País emisor",            "type": "string",             "required": false, "disclosable": true},
      {"key": "age_over_18",          "label": "Mayor de 18",            "type": "boolean",            "required": false, "disclosable": true},

      {"key": "curp",                 "label": "CURP",                   "type": "string",             "required": false, "disclosable": true},
      {"key": "organ_donor",          "label": "Donador",                "type": "boolean",            "required": false, "disclosable": true},
      {"key": "blood_type",           "label": "Tipo sanguíneo",         "type": "string",             "required": false, "disclosable": true},
      {"key": "allergies",            "label": "Alergias",               "type": "string",             "required": false, "disclosable": true},
      {"key": "emergency_phone",      "label": "Teléfono de emergencia", "type": "string",             "required": false, "disclosable": true}
    ]
  }'
```

El esquema queda `registration_status: "pending"` mientras su credential type se
inscribe on-chain. **Nada puede emitirse hasta que figure como `registered`**; la
emisión desde un esquema pendiente responde `409 schema_registration_pending`. No
existe webhook de registro: se consulta con `GET /schemas`.

Una vez publicado, el dashboard muestra el docType generado:

```
Document type: io.sovra.0x5de0df12cce712ddaaa23feb359920ae4f3230d4.driving_licence_v2.1
```

**El docType y el namespace son cadenas distintas**, y ambas son inmutables de por
vida. Una revisión del esquema (`PUT /schemas/:schema_id`) añade o retira claims, pero
descarta `namespace`, `doc_type` y `format` si vienen en el cuerpo.

| | Valor |
|---|---|
| `schema_id` | `driving_licence_v2` |
| `credential_type` | `DrivingLicence` |
| `doc_type` | `io.sovra.0x5de0df12cce712ddaaa23feb359920ae4f3230d4.driving_licence_v2.1` — generado |
| `namespace` | `mx.gob.nl.icv.1` — propio |
| `validity_period` | `4y` |

> 🔴 **`validity_period: null` no equivale a «sin vencimiento» en mDoc.** ISO 18013-5
> hace obligatorio `ValidityInfo.validUntil`, de modo que el formato carece de manera
> de expresar «nunca expira» y la emisión recae en un **valor por defecto de un año**.
> Con `null`, una licencia cuyo `expiry_date` indica 2030 deja de verificar en 2027,
> con `credential_expired`. De ahí que el esquema declare `"4y"` y que la emisión
> envíe `valid_until` cuando la vigencia de esa licencia concreta sea otra.

### El namespace reservado

`io.sovra.meta.1` transporta el holder DID, el índice de la status list y el conjunto
de elementos no retenibles. **No debe emplearse como namespace propio**: no existe
guarda alguna, y los elementos del esquema se perderían en silencio.

## `required` frente a `always shared`

Son dos ejes independientes, y confundirlos es el error más frecuente.

- **`required: true`** — sin ese claim la emisión falla con `422 missing_claims`.
- **`disclosable: false`**, que el dashboard rotula **always shared** — el claim viaja
  en **toda** presentación y el ciudadano no puede retenerlo: queda congelado en
  `mandatory_elements`, dentro del MSO firmado.

Que sean independientes se aprecia en dos filas del esquema anterior:

| Claim | required | always shared | Por qué |
|---|---|---|---|
| `second_family_name` | ❌ | ✅ | Hay personas con un solo apellido; pero si consta, es parte del nombre legal |
| `birth_date` | ✅ | ❌ | Para que el ciudadano acredite su edad con `age_over_18` sin entregar la fecha |

El esquema reúne **24 claims: 9 obligatorios y 9 always shared**, así agrupados:

| Grupo | Claims |
|---|---|
| Obligatorio **y** always shared (8) | `given_name`, `family_name`, `document_number`, `issue_date`, `expiry_date`, `driving_privileges`, `portrait`, `issuing_authority` |
| Obligatorio, retenible (1) | `birth_date` |
| Always shared, opcional al emitir (1) | `second_family_name` |
| Ni lo uno ni lo otro (14) | `signature_usual_mark`, `street`, `exterior_number`, `interior_number`, `neighborhood`, `resident_city`, `resident_postal_code`, `issuing_country`, `age_over_18`, `curp`, `organ_donor`, `blood_type`, `allergies`, `emergency_phone` |

Los catorce del último grupo son los que el ciudadano decide caso por caso. Ahí residen
la CURP, el domicilio, el tipo sanguíneo y las alergias: un control de tránsito no
precisa el historial médico, y lo que se marca always shared viaja a todas partes, para
siempre.

## Los atributos y su clave

Las claves se escriben **en inglés**, sin excepción. Un element identifier es una clave
técnica que viaja firmada dentro de la credencial y comparece en toda consulta DCQL; el
español reside en el `label`, que es lo que el ciudadano lee. Mezclar idiomas en las
claves obliga a cada integrador a recordar cuál de los dos le correspondió.

| # | Atributo | Clave | Tipo | required | always shared | Origen |
|---|---|---|---|---|---|---|
| 1 | Nombre | `given_name` | `string` | ✅ | ✅ | ISO |
| 2 | Apellido paterno | `family_name` | `string` | ✅ | ✅ | ISO |
| 3 | Apellido materno | `second_family_name` | `string` | — | ✅ | propio |
| 4 | CURP | `curp` | `string` | — | — | propio |
| 5 | Fecha de nacimiento | `birth_date` | `date` | ✅ | — | ISO |
| 6 | Calle | `street` | `string` | — | — | propio |
| 7 | Colonia | `neighborhood` | `string` | — | — | propio |
| 8 | Municipio | `resident_city` | `string` | — | — | ISO |
| 9 | Número exterior | `exterior_number` | `string` | — | — | propio |
| 10 | Número interior | `interior_number` | `string` | — | — | propio |
| 11 | Código postal | `resident_postal_code` | `string` | — | — | ISO |
| 12 | Fecha de expedición | `issue_date` | `date` | ✅ | ✅ | ISO |
| 13 | Fecha de vencimiento | `expiry_date` | `date` | ✅ | ✅ | ISO |
| 14 | Folio de licencia | `document_number` | `string` | ✅ | ✅ | ISO |
| 15 | Tipo de licencia | `driving_privileges[].vehicle_category_code` | `driving_privileges` | ✅ | ✅ | ISO |
| 16 | Firma | `signature_usual_mark` | `bytes` | — | — | ISO |
| 17 | Foto | `portrait` | `bytes` | ✅ | ✅ | ISO |
| 18 | Donador | `organ_donor` | `boolean` | — | — | propio |
| 19 | Teléfono de emergencia | `emergency_phone` | `string` | — | — | propio |
| 20 | Tipo sanguíneo | `blood_type` | `string` | — | — | propio |
| 21 | Alergias | `allergies` | `string` | — | — | propio |
| 22 | Restricciones | `driving_privileges[].codes[]` | `driving_privileges` | ✅ | ✅ | ISO |
| 23 | Autorizado por | `issuing_authority` | `string` | ✅ | ✅ | ISO |

Se añaden dos elementos que el documento en papel no imprime pero que conviene
transportar: `issuing_country` (`"MX"`, obligatorio en ISO y exigido por cualquier
lector extranjero) y `age_over_18`, que acredita la mayoría de edad **sin revelar la
fecha de nacimiento** — la razón de ser del formato.

### Las claves propias, y el porqué de cada nombre

| Atributo | Clave | Criterio |
|---|---|---|
| CURP | `curp` | Acrónimo, no palabra. **No debe emplearse `administrative_number`**: ISO lo define como número de control interno de la autoridad emisora, no como identificador nacional; usarlo produciría una credencial que verifica y afirma algo falso |
| Apellido materno | `second_family_name` | ISO define un solo `family_name`. Como campo independiente, una verificación puede solicitar el paterno sin el materno, y ninguno queda oculto dentro del otro |
| Colonia | `neighborhood` | ISO desciende de país a ciudad y de ahí a dirección libre: carece de nivel intermedio |
| Número exterior | `exterior_number` | **`string`, no `integer`**: admite `"S/N"`, `"123-A"`, `"KM 4.5"`. Declararlo entero haría fallar la emisión con `422 unencodable_claims` en el primer domicilio sin número |
| Número interior | `interior_number` | Lo mismo. Suele estar ausente, y la ausencia es correcta: un claim opcional en blanco no se envía |
| Donador | `organ_donor` | AAMVA emplea ese identificador en su propio namespace, de modo que adoptarlo deja abierta la alineación con ese precedente |

### Tres casos que ISO no cubre

| Caso | Problema | Resolución |
|---|---|---|
| **Dos apellidos** | ISO define un solo `family_name` | **Separados**: `family_name` es el paterno, `second_family_name` el materno. Un lector conforme verá una persona con un solo apellido; a cambio, cada apellido se solicita por separado |
| **Domicilio** | ISO carece de calle y número: `resident_address` es **una sola cadena libre** | **Desglosado**: `street`, `exterior_number` e `interior_number`, junto con `neighborhood`, `resident_city` y `resident_postal_code`. `resident_address` se omite: su contenido se deduce de las partes, y transportarlo además duplicaría el dato dentro de la credencial firmada |
| **Tipo y restricciones** | ISO no modela «tipo de licencia» como texto | Constituyen el mismo elemento, `driving_privileges`: el tipo es `vehicle_category_code` y las restricciones son **códigos asociados a cada categoría** (ISO 18013-5 §7.2.4) |

---

**Anterior:** [← 1. Por qué un namespace propio](01-introduccion.md) · **Siguiente:** [3. Emisión →](03-emision.md)
