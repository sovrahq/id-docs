# ID Docs — Documentación y Boilerplate

Documentación de la API de Sovra ID y plantillas para el desarrollo de aplicaciones
de identidad.

## 📚 Empezá acá

👉 **[Guías de Sovra ID](docs/guides/)** — cómo emitir y verificar credenciales
verificables con la API actual.

| # | Guía | Contenido |
|---|---|---|
| 1 | [Introducción](docs/guides/01-introduccion.md) | Roles, SD-JWT VC, el flujo completo, glosario. |
| 2 | [Primeros pasos](docs/guides/02-primeros-pasos.md) | Entornos, workspace, DID, esquemas, API key, webhook. |
| 3 | [Emisión de credenciales](docs/guides/03-emision-de-credenciales.md) | Oferta → QR → `credential.issued`. |
| 4 | [Verificación de credenciales](docs/guides/04-verificacion-de-credenciales.md) | Sesión OID4VP, DCQL, `presentation.verified`. |
| 5 | [Webhooks](docs/guides/05-webhooks.md) | Eventos, payloads, firma HMAC, reintentos. |
| 6 | [Ciclo de vida y revocación](docs/guides/06-ciclo-de-vida-y-revocacion.md) | Estados, transiciones, Bitstring Status List. |
| 7 | [Referencia de la API](docs/guides/07-referencia-api.md) | Todos los endpoints de `/api/v1`. |
| 8 | [Errores y troubleshooting](docs/guides/08-errores-y-troubleshooting.md) | Cada código de error y su solución. |
| 9 | [Verificación sin Sovra](docs/guides/09-verificacion-sin-sovra.md) | Verificar contra SovraChain con el SDK. |

## 📁 Estructura

```
├── docs/
│   ├── guides/        # ✅ Documentación vigente
│   ├── resources/     # Colección de Postman
│   └── deprecated/    # 🗄 Plataforma anterior — solo referencia histórica
├── boilerplate/       # Plantillas y código base
└── assets/            # Imágenes y diagramas
```

## 🧰 Recursos

- [Colección de Postman](docs/resources/sovra-credenciales.postman_collection.json) — importala y configurá `baseUrl` + `apiKey`.
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

Las guías viven en `docs/guides/`. Al agregar una, sumala al índice de este README y
al de [`docs/README.md`](docs/README.md).
