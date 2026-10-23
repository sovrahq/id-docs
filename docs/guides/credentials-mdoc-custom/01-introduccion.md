# 1. Por qué un namespace propio

La norma fija qué puede ir dentro de `org.iso.18013.5.1`, y la respuesta es: solo lo que ella define. Este capítulo explica por qué un documento con datos propios necesita su propio namespace, y qué se gana y qué se pierde al tomarlo.

## Tabla de contenidos

1. [Por qué un namespace propio](#por-que-un-namespace-propio)
2. [Las dos rutas, comparadas](#las-dos-rutas-comparadas)
3. [Qué se conserva de ISO](#que-se-conserva-de-iso)

---

## Por qué un namespace propio

`org.iso.18013.5.1` constituye un **conjunto cerrado**: la norma define exactamente
qué identificadores existen y de qué tipo es cada uno. Un `key` ajeno a esa lista es
rechazado **al crear el esquema**, con `undefined_iso_elements`, y un tipo distinto
del que ISO fija, con `wrong_iso_element_type`.

No se trata de una restricción de la plataforma. Un lector conforme que recibiera
`curp` dentro del namespace de ISO habría recibido algo que la norma declara
imposible, y la credencial resultaría malformada de una manera que solo se descubre
cuando un tercero la rechaza.

La vía que la propia norma prevé para extender un documento es **añadir un
namespace**, que es lo que hace AAMVA con `org.iso.18013.5.1.aamva`. La regla, por
tanto: conjunto fijo dentro del namespace de ISO, extensible al lado.

> ⚠️ **Un esquema mDoc declara un solo namespace.** La emisión agrupa todos los
> elementos bajo el namespace del esquema, de modo que la convivencia de
> `org.iso.18013.5.1` con una extensión propia —el patrón de AAMVA— **no es emitible
> hoy**. La elección es excluyente: o el namespace de ISO, o uno propio.

### Las dos rutas, comparadas

| | Namespace de ISO | Namespace propio |
|---|---|---|
| Elementos fuera del catálogo | ❌ El esquema no llega a crearse | ✅ |
| Document signer (CSR, certificado, raíz IACA admitida) | Obligatorio | No se requiere |
| Identidad del emisor | La cadena de certificados | La dirección inscrita en el docType |
| `doctype_value` identifica al emisor | ❌ `org.iso.18013.5.1.mDL` es idéntico para todo emisor | ✅ El docType generado porta la dirección del workspace |
| Lectores mDL de terceros | La interpretan | No la interpretan como licencia |

La cuarta fila resulta determinante en un documento de alcance estatal: con el docType
de ISO se admite la licencia de **cualquier** emisor cuyo document signer esté
admitido, y el filtrado por entidad queda a cargo del verificador, sobre
`issuing_authority`, una vez recibido el resultado. Con el docType generado, la
consulta misma ya ancla el workspace.

## Qué se conserva de ISO

Aunque el namespace sea propio, **los identificadores de ISO se conservan allí donde
ISO los define**. `given_name` se denomina `given_name` aunque resida en otro
namespace: ISO no es dueña de esa cadena, sino del par
`(org.iso.18013.5.1, given_name)`. Un element identifier siempre está acotado por su
namespace — esa es la razón de que los namespaces existan.

Conservarlos tiene dos efectos: la credencial sigue resultando legible para quien
conoce la mDL, y una eventual migración al namespace de ISO se reduce a trasladar
elementos entre grupos, sin renombrar nada.

---

**Índice:** [← Guías de mDoc con namespace propio](README.md) · **Siguiente:** [2. Crear el esquema →](02-crear-un-esquema.md)
