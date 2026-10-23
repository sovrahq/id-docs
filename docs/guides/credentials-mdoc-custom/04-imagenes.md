# 4. Imágenes: foto y firma

La fotografía y la firma son elementos `bytes` dentro del mDoc. Este capítulo cubre cómo se envían, cómo se recuperan y qué muestra hoy la cartera.

## Tabla de contenidos

1. [Imágenes: foto y firma](#imagenes-foto-y-firma)
2. [Cómo se presenta al verificador](#como-se-presenta-al-verificador)
3. [Qué muestra la cartera hoy](#que-muestra-la-cartera-hoy)

---

## Imágenes: foto y firma

`portrait` y `signature_usual_mark` son elementos `bytes`. Residen dentro del mDoc.

**Al emitir**, base64 **estándar con padding**, sin prefijo `data:`, sin espacios ni
saltos de línea: el decodificador es estricto y el alfabeto URL-safe (`-_`) se rechaza
con `422 unencodable_claims`.

```json
"portrait": "/9j/4AAQSkZJRgABAQAAAQABAAD...",
"signature_usual_mark": "iVBORw0KGgoAAAANSUhEUgAAA..."
```

Cómo obtener ese base64 a partir de un archivo:

```bash
# portable
openssl base64 -A -in firma.png

# GNU/Linux
base64 -w 0 firma.png

# macOS / BSD
base64 -i firma.png | tr -d '\n'
```

El `-A` / `-w 0` / `tr -d '\n'` no es opcional.

La plataforma no valida el contenido: decodifica el base64 y almacena lo que reciba.
Los límites que conviene respetar, y que el dashboard aplica por su cuenta:

- **PNG o JPEG** exclusivamente. PNG cuando la firma lleve fondo transparente.
- Reescalada a **480 px** en su lado mayor.
- **400 KB** como máximo.

Una fotografía tomada directamente con un teléfono ocupa varios megabytes, y todos
ellos se firman dentro de una credencial que debe llegar a una cartera y viajar en cada
presentación.

> **`bytes` no transporta media type.** El elemento es una
> cadena de bytes desnuda: nada en la credencial declara si es PNG o JPEG. O se fija por
> convención —PNG para la firma, JPEG para la foto, documentado— o se leen los primeros
> bytes (`\x89PNG`, `\xFF\xD8\xFF`). Suponer el tipo equivocado produce una imagen rota,
> no un error.

### Cómo se presenta al verificador

El resultado entrega base64**url** **sin padding**, no el base64 estándar que se envió.
Para renderizarla:

```js
const b64url = claims["mx.gob.nl.icv.1.signature_usual_mark"];

// base64url → base64 estándar, reponiendo el padding
const b64 = b64url
  .replace(/-/g, "+")
  .replace(/_/g, "/")
  .padEnd(Math.ceil(b64url.length / 4) * 4, "=");

img.src = `data:image/png;base64,${b64}`;
```

Esa asimetría entre entrada y salida es la causa más frecuente de una imagen ilegible
del lado del verificador.

### Qué muestra la cartera hoy

| Elemento | ¿Lo ve el ciudadano? |
|---|---|
| `portrait` | ✅ Se renderiza como la fotografía que es |
| `signature_usual_mark` | ❌ Una fila que indica «Imagen» |
| Cualquier otro `bytes` | ❌ Igual |

No es cuestión del esquema ni del formato: la cartera renderiza **un único** elemento
binario, el denominado literalmente `portrait`. El lector de bytes es genérico y
recupera cualquier elemento, pero está invocado con ese nombre fijo.

**La firma viaja correctamente dentro de la credencial**; lo que falta es el
renderizado, y es un cambio acotado del lado de la cartera — la plataforma ya lo
soporta. **No habrá que reemitir nada**: las credenciales ya emitidas se mostrarán
solas el día que la cartera aprenda a dibujarlas, porque los bytes ya están firmados
dentro.

---

**Anterior:** [← 3. Emisión](03-emision.md) · **Siguiente:** [5. Verificación →](05-verificacion.md)
