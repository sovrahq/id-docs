# Guías de mDoc con namespace propio

Documentación para emitir y verificar un **mDoc** (ISO/IEC 18013-5) cuyos datos
residen en un **namespace propio**, no en el de ISO.

El caso que motiva esta ruta es un documento que debe transportar datos que
`org.iso.18013.5.1` **no define**. El ejemplo que se desarrolla a lo largo de la guía
es una licencia de conducir mexicana, que además de los elementos de una mDL debe
llevar CURP, colonia, tipo sanguíneo, alergias, condición de donante y un teléfono de
emergencia. Ninguno de los seis existe en el catálogo de ISO, y ninguno puede añadirse
a él.

> Para un mDoc bajo el namespace de ISO —una mDL conforme, legible por lectores de
> terceros—, la documentación correspondiente es
> [`../credentials-mdoc/`](../credentials-mdoc/). Esta guía supone esa lectura previa:
> aquí solo se describe lo que cambia.

## Namespace de ISO frente a namespace propio

| | **Namespace de ISO** ([`../credentials-mdoc/`](../credentials-mdoc/)) | **Namespace propio** (esta guía) |
|---|---|---|
| `namespace` del esquema | `org.iso.18013.5.1` | El que se declare, en DNS inverso |
| Elementos fuera del catálogo de ISO | ❌ `undefined_iso_elements` al crear el esquema | ✅ Sin restricción |
| Tipos de los elementos | Fijados por la norma | A elección del emisor |
| Document signer (CSR, certificado, raíz IACA) | Obligatorio si el docType es el de ISO | No se requiere |
| Identidad del emisor | La cadena de certificados `x5chain` | La dirección contenida en el docType generado |
| `doctype_value` identifica al emisor | ❌ `org.iso.18013.5.1.mDL` es idéntico para todo emisor | ✅ El docType porta la dirección del workspace |
| Lectores mDL de terceros | La interpretan | No la interpretan como licencia |
| Convivencia de ambos namespaces | — | ❌ **No emitible hoy**: un esquema declara uno solo |

Todo lo demás es común: los mismos endpoints, la misma API key de workspace
(`Authorization: Bearer sovra_sk_...`), los mismos webhooks y el mismo estado publicado
en **SovraChain**.

## Ruta de lectura

| # | Guía | Propósito |
|---|---|---|
| 1 | [Por qué un namespace propio](01-introduccion.md) | El conjunto cerrado de ISO, las dos rutas comparadas, qué identificadores conviene conservar. |
| 2 | [Crear el esquema](02-crear-un-esquema.md) | El cuerpo completo, `required` frente a `always shared`, los 23 atributos y su clave, los tres casos que ISO no cubre. |
| 3 | [Emisión](03-emision.md) | La oferta con los 24 claims, `valid_until` frente a `expiry_date`, la codificación de cada tipo. |
| 4 | [Imágenes: foto y firma](04-imagenes.md) | Base64 estándar al emitir, base64url al verificar, los límites de tamaño y qué muestra hoy la cartera. |
| 5 | [Verificación](05-verificacion.md) | El DCQL con namespace propio, las tres cadenas que deben coincidir, seis escenarios reales, el resultado y los fallos más frecuentes. |

## Las tres decisiones irreversibles

`format`, `doc_type` y `namespace` quedan firmados dentro de cada credencial emitida.
Una revisión del esquema añade o retira claims, pero **descarta esos tres campos** si
vienen en el cuerpo. Se cierran antes de la primera llamada:

| Decisión | En el ejemplo |
|---|---|
| El namespace, en DNS inverso | `mx.gob.nl.icv.1` |
| El `schema_id`, que pasa a formar parte del docType | `driving_licence_v2` |
| El docType: generado o propio | Generado — `io.sovra.0x5de0df12cce712ddaaa23feb359920ae4f3230d4.driving_licence_v2.1` |

## Resumen del flujo

**Emisión:** se crea el esquema con `format: mso_mdoc` y el namespace propio → se espera
a que figure como `registered` → se crea la oferta con los claims en estructura plana →
el ciudadano escanea el `offer_uri` → la credencial firmada llega una sola vez, en el
webhook `credential.issued`.

**Verificación:** se crea la sesión con un DCQL cuyas rutas son
`["<namespace>", "<elemento>"]` → se renderiza `authorization_request_uri_ref` como QR →
el ciudadano aprueba → el resultado llega indexado por `"<namespace>.<elemento>"`, en el
webhook `presentation.verified` o consultando la sesión.

## Lista de comprobación

- [ ] El namespace está elegido y **escrito en DNS inverso**. No puede cambiarse.
- [ ] El `schema_id` respeta `^[a-z0-9][a-z0-9_-]*$`: pasa a formar parte del docType.
- [ ] `validity_period` **no es `null`**, o la emisión envía `valid_until`.
- [ ] `valid_until` coincide con `expiry_date` en cada emisión.
- [ ] Los identificadores de ISO conservan su nombre; los propios van en inglés.
- [ ] `exterior_number` e `interior_number` son `string`, no `integer`.
- [ ] Lo marcado always shared es lo que debe viajar **siempre**; los datos sensibles
      quedan retenibles.
- [ ] `portrait` y `signature_usual_mark` van en base64 **estándar con padding**, PNG o
      JPEG, 480 px, 400 KB.
- [ ] El verificador convierte base64url **a** base64 estándar antes de renderizar.
- [ ] `doctype_value` lleva el docType; `path[0]`, el namespace. Ambos se leen de
      `GET /schemas`.
- [ ] El esquema figura como `registered` antes de la primera emisión.

## Recursos

- [Colección de Postman — mDoc con namespace propio](../../resources/sovra-mdoc-custom.postman_collection.json)
- [Guías de mDoc bajo el namespace de ISO](../credentials-mdoc/)
- [Índice de guías](../README.md)
