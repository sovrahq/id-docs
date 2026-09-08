# Guías de documentos firmados

Documentación para **emitir**, **repartir**, **renderizar** y **verificar** documentos
firmados con la API de Sovra ID.

Un documento firmado es una **credencial sin holder**: el emisor la firma y la
entrega. No hay wallet, no hay OID4VCI, no hay flujo de aceptación. Una sola llamada
valida los datos, los firma con **ES256** y ancla la firma **on-chain**; el resultado
es un string **SD-JWT VC** autocontenido que cualquiera puede verificar contra la
cadena, sin depender de la API de Sovra.

> Si lo que necesitás es una credencial que viva en la wallet del ciudadano, con
> divulgación selectiva y prueba de posesión, la documentación es
> [`../credentials/`](../credentials/). La comparación completa entre los dos formatos
> está en [1. Introducción](01-introduccion.md#documento-o-credencial-cuál-usar).

## Ruta de lectura

| # | Guía | Para qué sirve |
|---|---|---|
| 1 | [Introducción](01-introduccion.md) | Qué es un documento firmado, documento vs. credencial, anatomía del `credential`, las dos capas de confianza. |
| 2 | [Primeros pasos](02-primeros-pasos.md) | Entornos, las dos zonas de la API, workspace y DID, el esquema de kind `document`, el layout, API key. |
| 3 | [Emisión de documentos](03-emision-de-documentos.md) | `POST /issuer/documents`, la latencia de ~90 s, la respuesta campo por campo, qué guardar, el listado. |
| 4 | [Visibilidad y entrega](04-visibilidad-y-entrega.md) | Publicar y despublicar, qué restringe y qué no, la lectura pública, los caminos de entrega. |
| 5 | [Renderizado de la hoja](05-renderizado.md) | El objeto `layout`, la geometría, el saneo, la franja de procedencia, el QR. |
| 6 | [Verificación](06-verificacion.md) | Por qué no hay un endpoint, los nueve checks, el SDK, códigos de error. |
| 7 | [Referencia de la API](07-referencia-api.md) | Los cuatro endpoints, los objetos, los límites, la colección de Postman. |
| 8 | [Errores y troubleshooting](08-errores-y-troubleshooting.md) | Cada código de error, qué lo causa y cómo se arregla. |

## Atajo: el flujo en una pantalla

```bash
# 0. Prerrequisitos: un esquema de kind `document` en el dashboard, y una API key.
export BASE_URL="https://test-api-sovra.flagonsa.com"
export SOVRA_API_KEY="sovra_sk_..."

# 1. Emitir. Valida, firma y ancla en una sola llamada — hasta ~90 segundos.
curl -s -X POST "$BASE_URL/api/v1/issuer/documents" \
  --max-time 120 \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{
    "schema_id": "document_example",
    "claims": { "date": "20-08-2026", "idDocument": 1234567,
                "content": "Contenido de Prueba", "isConfidential": false }
  }'
# → 201 { "id": "...", "status": "anchored", "credential": "eyJhbGci…~", ... }
#   ★ Guardá `credential`: ese string ES el documento.

# 2. Publicarlo (opcional — todo documento nace privado).
curl -s -X PUT -H "Authorization: Bearer $SOVRA_API_KEY" \
  "$BASE_URL/api/v1/issuer/documents/$DOCUMENT_ID/visibility/public"

# 3. El destinatario lo lee. Sin /issuer y sin API key.
curl -s "$BASE_URL/api/v1/documents/$DOCUMENT_ID"
# → { "credential": "...", "issuer": {...}, "schema": {...},
#     "layout": {...}, "anchor": {...} }

# 4. Y lo verifica contra la cadena. No hay endpoint que dé un veredicto.
```

## Las cuatro cosas que hay que saber antes de integrar

1. **`credential` es lo único que importa guardar.** Todo el resto de la respuesta es
   la afirmación del propio servicio y se puede volver a pedir. Si tu integración
   persiste un solo campo, es ese.
2. **La emisión puede tardar ~90 segundos.** Poné el timeout del cliente en 120 s.
3. **Todo documento nace privado**, y `visibility` **restringe los endpoints, no el
   artefacto**: volver a privado cierra el enlace, no las copias ya entregadas.
4. **No hay endpoint de verificación, a propósito.** Un veredicto habría que creerlo;
   una firma se puede comprobar.

## Recursos

- [Colección de Postman](../../resources/sovra-documentos-firmados.postman_collection.json)
  — importala y configurá `base_url`, `api_key`, `schema_id`.
- Especificación OpenAPI en vivo: `GET {baseUrl}/openapi/api`
- Boilerplate de renderizado: repositorio `document-render-boilerplate` — dibuja la
  hoja A4 a partir del JSON público, en HTML + CSS + JS vanilla.

---

**Volver a [todas las guías](../README.md)** · **Credenciales verificables:** [`../credentials/`](../credentials/)
