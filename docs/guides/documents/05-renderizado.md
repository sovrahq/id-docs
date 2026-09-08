# 5. Renderizado de la hoja

El `layout` que devuelve la lectura pública existe para que **un tercero pueda
mostrar la hoja con el diseño del emisor**. Esta guía es cómo recorrerlo — y las
reglas que hacen que un layout roto u hostil cueste un elemento y nunca la página.

**El renderizado corre de tu lado.** La API devuelve el `layout` y el `credential`;
dibujar la hoja es trabajo de tu aplicación. Hay un boilerplate completo de
referencia — ver [abajo](#boilerplate-de-referencia).

## Tabla de contenidos

1. [Las tres cosas que el JSON no trae](#las-tres-cosas-que-el-json-no-trae)
2. [El objeto `layout`](#el-objeto-layout)
3. [Los cinco tipos de elemento](#los-cinco-tipos-de-elemento)
4. [La geometría derivada](#la-geometría-derivada)
5. [Los valores salen del `credential`](#los-valores-salen-del-credential)
6. [Calcular el `digest`](#calcular-el-digest)
7. [El saneo, regla por regla](#el-saneo-regla-por-regla)
8. [La franja de procedencia](#la-franja-de-procedencia)
9. [Degradar sin romper](#degradar-sin-romper)
10. [Boilerplate de referencia](#boilerplate-de-referencia)

---

## Las tres cosas que el JSON no trae

Esto es lo que sorprende al integrar, así que va temprano:

| Lo que falta | De dónde sale |
|---|---|
| **Los valores de los campos** | `schema.claims[]` trae solo *definiciones* (`key`, `label`, `type`, `required`). El `1234567` y el `"Contenido de Prueba"` están **dentro del `credential`**. |
| **El `digest`** | No viene en la lectura pública (sí en la de emisión). Es `SHA-256(header.payload)` del `credential`, y se calcula — ver [abajo](#calcular-el-digest). |
| **El ancho de cada elemento** | `layout.elements[]` trae `x` e `y`, no el ancho. Es derivado: `page.width - 48 - x`. |

## El objeto `layout`

```json
{
  "page": { "width": 794, "height": 1123 },
  "elements": [
    { "id": "title", "type": "text", "value": "Document Example",
      "x": 283, "y": 56, "size": 26, "bold": true, "color": "#111827" },
    { "id": "emt2553os0", "type": "text", "value": "Date",
      "x": 600, "y": 128, "size": 14, "bold": true, "color": "#111827" },
    { "id": "emt25542s1", "type": "field", "bind": "date",
      "x": 648, "y": 128, "size": 14, "color": "#111827" }
  ]
}
```

| Campo | Tipo | Qué es |
|---|---|---|
| `layout.page` | `object` | Dimensiones de la hoja: **794 × 1123 px**, la proporción A4 a 96 dpi. |
| `layout.elements[]` | `array<object>` | Cada elemento visual de la página. |
| `elements[].id` | `string` | Identificador interno del elemento. |
| `elements[].type` | `string` | Qué dibuja — ver [abajo](#los-cinco-tipos-de-elemento). |
| `elements[].bind` | `string` | Solo en `type: "field"`: **qué claim se dibuja en ese lugar**. |
| `elements[].value` | `string` | Solo en `type: "text"`: el texto fijo. |
| `elements[].x` / `.y` | `number` | Posición en la página, en píxeles. |
| `elements[].size` | `number` | Tamaño de fuente. |
| `elements[].color` | `string` | Color en hex. |
| `elements[].bold` | `boolean` | Si el texto va en negrita. |
| `elements[].align` | `string` | `left`, `center` o `right`. |
| `elements[].src` | `string` | Solo en `type: "image"`: la imagen, como data URI. |

> **`layout` no está firmado.** Es presentación: el editor del dashboard lo dice
> explícito — *"This is presentation only, it is not part of what gets signed."* Un
> layout puede cambiar sin que el documento cambie.

**No hay `z-index`: el orden del array es el orden de pintado.** Un `box` de fondo va
antes que lo que se apoya encima.

## Los cinco tipos de elemento

| `type` | Qué dibuja |
|---|---|
| `text` | Su `value`. Títulos, etiquetas. |
| `field` | El claim que dice su `bind`. **Es la única diferencia con `text`.** |
| `line` | Una regla. 1 px de alto: el grosor no es configurable. |
| `box` | Un rectángulo de fondo (con `radius` opcional). |
| `image` | Un logo o un sello, como data URI. |

Un `type` desconocido conviene tratarlo como texto y dibujar su `value`: **es mejor
dibujar algo que no dibujar nada**, y un esquema nuevo con un tipo nuevo no debería
romper el renderizador.

## La geometría derivada

El layout trae `x` e `y`, no el ancho. El ancho se calcula, con un margen de 48 px:

```js
const MARGIN = 48;
const room  = Math.max(0, page.width - MARGIN - element.x);
const width = element.width
  ? `width:${Math.min(element.width, room)}px`
  : `max-width:${room}px`;
```

`room` es el espacio desde la `x` del elemento hasta el margen derecho, y es lo que
hace que **un texto largo corte en el margen** en vez de irse fuera de la hoja. El
título en `x = 283` da `794 - 48 - 283 = 463`, y ahí sale `max-width: 463px`.

> Un **ancho fijo** mantiene la caja quieta sea cual sea el valor que llegue. Sin uno,
> el elemento se ajusta a su texto, y dos documentos del mismo esquema con contenidos
> distintos quedan maquetados distinto.

## Los valores salen del `credential`

Esta es la regla que sostiene todo lo demás:

> ⚠️ **Los valores se leen del `credential` y de ningún otro campo, aunque
> estuvieran ahí.** El `credential` es lo único firmado de toda la respuesta:
> `schema`, `issuer`, `layout` y `anchor` los podría haber cambiado cualquiera en el
> camino. **Una hoja solo puede mostrar lo que cubre la firma.**

Y el filtro va en una dirección concreta — **por las keys del esquema, no por las del
payload**:

```js
const claims = {};
for (const claim of schema.claims) {
  if (claim.key in payload) claims[claim.key] = payload[claim.key];
}
```

La dirección importa. El payload trae también los claims registrados del JWT (`iss`,
`vct`, `jti`, `iat`, `exp`), y filtrando así **no llegan nunca a la hoja**: un layout
que bindea a `iss` dibuja vacío en lugar de filtrar el emisor por la ventana.

Cómo abrir el `credential`:

```js
const [jwt] = credential.split("~");
const [header, payload, signature] = jwt.split(".");
const signingInput = `${header}.${payload}`;   // sobre esto se firma y se ancla
```

Cada segmento es **base64url** (`-` y `_` en vez de `+` y `/`, sin padding). En el
navegador, `atob` solo entiende base64 común, así que hay que traducir los dos
caracteres antes — y pasar por `Uint8Array` + `TextDecoder`, porque un claim con
acentos o eñes sale mal si los bytes se leen como si fueran texto:

```js
function decodeSegment(segment) {
  const base64 = segment.replace(/-/g, "+").replace(/_/g, "/");
  const bytes  = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
  return JSON.parse(new TextDecoder().decode(bytes));
}
```

## Calcular el `digest`

El `digest` **no viene** en la lectura pública. No hace falta pedirlo: el digest
anclado on-chain es el SHA-256 del *signing input*, que son bytes que ya tenés.

```js
export async function digestOf(signingInput) {
  const bytes = new TextEncoder().encode(signingInput);
  const hash  = await crypto.subtle.digest("SHA-256", bytes);
  return [...new Uint8Array(hash)]
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}
```

> ⚠️ Se calcula sobre el **signing input** (`header.payload`), **no** sobre el
> `credential` completo: sobre eso se firmó y sobre eso se ancla. Incluir la firma o
> la `~` da otro hash, que no coincide con nada de lo que hay en la cadena.

Al comparar contra el `digest` que devuelve la emisión, compará en minúsculas e
ignorando un eventual prefijo `0x`.

## El saneo, regla por regla

El layout lo controla el emisor, pero termina dentro de un atributo `style` y de
nodos del DOM. Estas son las reglas, y qué pasa sin cada una:

| Regla | Sin ella |
|---|---|
| `color` solo si matchea `/^#[0-9a-fA-F]{3,8}$/` | Un valor como `"#fff; position:fixed; top:0"` **despega el elemento de la hoja**. |
| `src` solo `data:image/(png\|jpeg);base64,…` | Un `src: "javascript:…"`, o una URL a un tracker: la hoja pasa a depender de un servidor de terceros. |
| Lo que no es objeto en `elements[]` se descarta | Un `null` en el array llega a `element.type` y **tira la página entera**. |
| `align` solo `left` / `center` / `right` | Cualquier string entra al CSS. |
| `page.width` / `page.height` tienen que ser **números** | Un `"794"` string se cuela hasta el CSS y rompe la aritmética del margen. |
| Todo texto se escapa | Un claim con `<img src=x onerror=…>` se ejecuta. |
| El texto entra por `textContent`, nunca por `innerHTML` | El escapado pasa a ser la única línea de defensa en vez de la segunda. |

**Un layout roto cuesta un elemento, nunca la página.**

## La franja de procedencia

No es un elemento del layout, y **el emisor no la puede mover ni sacar**. Es lo que
permite ir a verificar en lugar de creerle a la hoja: si fuera opcional, el documento
no serviría para nada.

| Fila | Qué lleva |
|---|---|
| **Issued by** | `issuer.name` · `issuer.did`. El nombre es informativo y **no está firmado**; el DID sí (sale de `iss`). Van juntos porque el nombre es lo que un humano reconoce y el DID es lo que se puede comprobar. |
| **Transaction** | El `tx_hash`, linkeado al explorer en pantalla y con el hash **completo escrito igual**, así que en papel también se puede seguir a mano. Sin `tx_hash`: decir `not anchored` y **no inventar un link**. |
| **Digest** | El calculado, o `unavailable`. |
| **QR** | El **`credential` completo**, no un link a la página. |

### Por qué el QR lleva el credential y no un link

Es el único motivo por el que la hoja no hay que creerla: quien la recibe escanea,
verifica la firma contra la cadena, y **si este servicio desapareció el QR impreso
sigue sirviendo**. Un link solo prueba que el servicio está en línea.

El costo es tamaño. Con el ejemplo de estas guías:

| | |
|---|---|
| Tamaño del `credential` | 520 bytes → **versión 15** del QR (77 × 77 módulos) |
| Un link a la misma hoja | ~90 bytes → una versión mucho más chica |
| Techo en corrección nivel **L** | ~2953 bytes |

Arriba del techo, lo correcto es **devolver `null` en vez de lanzar**: perder el QR es
mejor que perder la página. Y conviene dibujarlo a varias veces el tamaño de display
—PNG, no SVG, que es un `<rect>` por módulo— para que impreso quede nítido.

## Degradar sin romper

La lectura del `credential` **no debería lanzar nunca**. Un credential ilegible cuesta
los campos dinámicos y el digest, no la página:

| Qué falla | Qué se pierde | Qué se sigue viendo |
|---|---|---|
| El `credential` no parsea | Los `field` quedan vacíos, el digest dice `unavailable` | Los `text` del layout, la franja |
| El QR no entra | El QR | Toda la hoja |
| El documento no está anclado | El link al explorer | `not anchored` escrito en la franja |
| Un elemento no pasa el saneo | Ese elemento | El resto de la página |

> ⚠️ **Lo que se degradó no se muestra, pero no se pierde: registralo.** Una hoja a la
> que le falta el QR se ve casi idéntica a una completa, y el QR es justamente la
> parte que permite no creerle a la página.

## Boilerplate de referencia

Hay una implementación completa de todo esto —HTML + CSS + JS vanilla, sin
frameworks, sin build, sin dependencias— en el repositorio
**`document-render-boilerplate`**. Dibuja la hoja A4 a partir del JSON de
`GET /api/v1/documents/{id}`, con el saneo de esta guía y 20 tests, incluido un golden
que compara la salida byte a byte.

Dos cosas que ese boilerplate **no** hace, y conviene saber antes de copiarlo:

- **No verifica la firma.** Dibuja lo que dice el `credential`; no comprueba que el
  `credential` esté firmado por quien dice. Sin ese check, **un documento adulterado
  se ve igual que uno legítimo** — ver [6. Verificación](06-verificacion.md).
- **No emite.** Eso es la zona emisor, y necesita la API key, que no puede vivir en el
  navegador.

Una nota de arquitectura que sale de ahí y aplica a cualquier front:

> La zona emisor **no se puede llamar desde el browser**, por dos razones
> independientes: el preflight `OPTIONS /api/v1/issuer/documents` responde `204` con
> `Access-Control-Allow-Headers` y `-Methods` pero **sin**
> `Access-Control-Allow-Origin`, así que el navegador bloquea la llamada; y la API key
> en un `.js` servido al navegador queda a la vista de cualquiera que abra DevTools.
> Necesitás un proxy en tu servidor, con una whitelist explícita de rutas.
>
> La zona pública sí responde `Access-Control-Allow-Origin: *`, así que
> `GET /api/v1/documents/{id}` se puede llamar directo desde el navegador.

---

**Anterior:** [← 4. Visibilidad y entrega](04-visibilidad-y-entrega.md) · **Siguiente:** [6. Verificación →](06-verificacion.md)
