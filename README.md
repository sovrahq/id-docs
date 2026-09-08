# ID Docs — Documentación y Boilerplate

Documentación de la API de Sovra ID y plantillas para el desarrollo de aplicaciones
de identidad.

## 📚 Empezá acá

👉 **[Guías de Sovra ID](docs/guides/)** — dos rutas de lectura, según qué emitas:

| | Qué es | Cuándo |
|---|---|---|
| 📄 **[Credenciales verificables](docs/guides/credentials/)** | Viven en la wallet del ciudadano, con divulgación selectiva y prueba de posesión | Licencias, identidad, membresías, títulos habilitantes |
| 📑 **[Documentos firmados](docs/guides/documents/)** | Una credencial **sin holder**: el emisor la firma, la ancla on-chain y la entrega | Certificados, constancias, actas, comprobantes |

**La regla práctica:** si el dato es sobre una persona y ella decide cuándo y cuánto
mostrarlo, es una **credencial**. Si es una hoja que la institución emite y reparte,
es un **documento**.

### 📄 Credenciales verificables

| # | Guía | Contenido |
|---|---|---|
| 1 | [Introducción](docs/guides/credentials/01-introduccion.md) | Roles, SD-JWT VC, el flujo completo, glosario. |
| 2 | [Primeros pasos](docs/guides/credentials/02-primeros-pasos.md) | Entornos, workspace, DID, esquemas, API key, webhook. |
| 3 | [Emisión de credenciales](docs/guides/credentials/03-emision-de-credenciales.md) | Oferta → QR → `credential.issued`. |
| 4 | [Verificación de credenciales](docs/guides/credentials/04-verificacion-de-credenciales.md) | Sesión OID4VP, DCQL, `presentation.verified`. |
| 5 | [Webhooks](docs/guides/credentials/05-webhooks.md) | Eventos, payloads, firma HMAC, reintentos. |
| 6 | [Ciclo de vida y revocación](docs/guides/credentials/06-ciclo-de-vida-y-revocacion.md) | Estados, transiciones, Bitstring Status List. |
| 7 | [Referencia de la API](docs/guides/credentials/07-referencia-api.md) | Todos los endpoints de `/api/v1`. |
| 8 | [Errores y troubleshooting](docs/guides/credentials/08-errores-y-troubleshooting.md) | Cada código de error y su solución. |
| 9 | [Verificación sin Sovra](docs/guides/credentials/09-verificacion-sin-sovra.md) | Verificar contra SovraChain con el SDK. |

### 📑 Documentos firmados

| # | Guía | Contenido |
|---|---|---|
| 1 | [Introducción](docs/guides/documents/01-introduccion.md) | Documento vs. credencial, anatomía del `credential`, las dos capas de confianza. |
| 2 | [Primeros pasos](docs/guides/documents/02-primeros-pasos.md) | Entornos, las dos zonas de la API, esquema `document`, layout, API key. |
| 3 | [Emisión de documentos](docs/guides/documents/03-emision-de-documentos.md) | `POST /issuer/documents`, la latencia de ~90 s, qué guardar. |
| 4 | [Visibilidad y entrega](docs/guides/documents/04-visibilidad-y-entrega.md) | `public`/`private`, la lectura pública, los caminos de entrega. |
| 5 | [Renderizado de la hoja](docs/guides/documents/05-renderizado.md) | `layout`, geometría, saneo, franja de procedencia, QR. |
| 6 | [Verificación](docs/guides/documents/06-verificacion.md) | Los nueve checks, el SDK, códigos de error. |
| 7 | [Referencia de la API](docs/guides/documents/07-referencia-api.md) | Los cuatro endpoints y los objetos. |
| 8 | [Errores y troubleshooting](docs/guides/documents/08-errores-y-troubleshooting.md) | Cada código de error y su solución. |

## 📁 Estructura

```
├── docs/
│   ├── guides/
│   │   ├── credentials/   # ✅ Credenciales verificables (wallet, OID4VCI/OID4VP)
│   │   └── documents/     # ✅ Documentos firmados (sin holder, anclados on-chain)
│   ├── resources/         # Colecciones de Postman
│   └── deprecated/        # 🗄 Plataforma anterior — solo referencia histórica
├── boilerplate/           # Plantillas y código base
└── assets/                # Imágenes y diagramas
```

## 🧰 Recursos

- [Colección de Postman — Credenciales](docs/resources/sovra-credenciales.postman_collection.json) — importala y configurá `baseUrl` + `apiKey`.
- [Colección de Postman — Documentos firmados](docs/resources/sovra-documentos-firmados.postman_collection.json) — importala y configurá `base_url`, `api_key`, `schema_id`.
- Especificación OpenAPI en vivo: `GET {baseUrl}/openapi/api`.

## 🗄 Documentación obsoleta

[`docs/deprecated/`](docs/deprecated/) conserva la documentación de la **plataforma
anterior** (`x-api-key`, `did:quarkid`, DIDComm, BBS+), solo como referencia
histórica. **No la uses para integraciones nuevas**: los endpoints, los formatos y
los eventos no son compatibles con la API actual.

> ⚠️ El contenido de [`boilerplate/`](boilerplate/) todavía apunta a la plataforma
> anterior (usa `x-api-key` y credenciales `did:quarkid`). Está pendiente de migrar a
> la API actual; mientras tanto, seguí las [guías](docs/guides/).

## 📝 Contribuir

Las guías viven en `docs/guides/credentials/` y `docs/guides/documents/`. Al agregar
una, sumala al índice de su carpeta, al de [`docs/guides/README.md`](docs/guides/README.md),
al de [`docs/README.md`](docs/README.md) y al de este README.
