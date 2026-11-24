# ID Docs — Documentación y Boilerplate

Documentación de la API de Sovra ID y plantillas para el desarrollo de aplicaciones
de identidad.

## 📚 Empezá acá

La documentación vive en el **sitio** (`apps/docs`), ordenada por concepto: primero los
conceptos, después una sección por rol. Levantalo con `npm install && npm run dev`
(http://localhost:4321) o leé las páginas en
[`apps/docs/src/content/docs/`](apps/docs/src/content/docs/).

| Sección | Qué cubre |
|---|---|
| [Conceptos](apps/docs/src/content/docs/conceptos/) | Credenciales verificables, tipos (SD-JWT VC, mDoc ISO, mDoc propio), documentos, esquemas, **ciclo de vida** de cada elemento, glosario. |
| [Primeros pasos](apps/docs/src/content/docs/primeros-pasos.mdx) | Entornos, workspace y DID, API key, webhook, primera llamada, convenciones de la API. |
| [Credenciales](apps/docs/src/content/docs/credenciales/) | El emisor: esquemas SD-JWT y mDoc, emisión, estados y revocación. |
| [Verificadores](apps/docs/src/content/docs/verificadores/) | Verificar SD-JWT y mDoc (OID4VP + DCQL), habilitar mDL ISO, verificar sin Sovra, estados de la sesión. |
| [Documentos](apps/docs/src/content/docs/documentos/) | Documentos firmados: esquema, emisión, estados, visibilidad, renderizado, verificación. |
| [Webhooks](apps/docs/src/content/docs/webhooks.mdx) | Eventos, payloads, firma HMAC, reintentos. |
| [Integraciones](apps/docs/src/content/docs/integraciones/) | WhatsApp y Moodle. |
| [Errores](apps/docs/src/content/docs/errores/) | Cada código de error y su solución. |

La referencia de la API (`/api` en el sitio) se genera desde
[`packages/openapi/openapi.json`](packages/openapi/openapi.json).

**La regla práctica:** si el dato es sobre una persona y ella decide cuándo y cuánto
mostrarlo, es una **credencial**. Si es una hoja que la institución emite y reparte,
es un **documento**.

## 📁 Estructura

Monorepo con npm workspaces + Turborepo.

```
├── apps/
│   └── docs/              # Sitio de documentación (Astro + Nimbus)
├── packages/
│   └── openapi/           # Spec OpenAPI de /api/v1, compartida por las apps
├── docs/                  # Colecciones de Postman, assets y archivo
│   ├── resources/         # Colecciones de Postman
│   ├── assets/            # Imágenes y logos (brand/: press kit de Sovra)
│   └── deprecated/        # 🗄 Plataforma anterior + guías viejas por formato (archivo)
├── templates/             # Plantillas de código (WhatsApp, Moodle, Next.js + Nest.js)
├── tools/                 # Scripts: Postman → OpenAPI
├── infra/                 # Despliegue: Cloud Run (gcp/) y Cloudflare (alternativa)
├── openspec/              # Propuestas de cambio (OpenSpec)
├── .github/workflows/     # CI y deploy a Cloud Run
├── Dockerfile             # Imagen del sitio (nginx) para Cloud Run
└── .agents/               # Guía para agentes (AGENTS.md)
```

## 🌐 Sitio de documentación

Requiere Node.js 22.23.4 o superior.

```sh
npm install
npm run dev        # http://localhost:4321
npm run build      # apps/docs/dist
npm run openapi    # regenera packages/openapi/openapi.json desde docs/resources
npm run docker:build && npm run docker:run   # la imagen de Cloud Run, en :8080
```

Las páginas del sitio se escriben en `apps/docs/src/content/docs/` (MDX), ordenadas por
concepto: Conceptos → Credenciales → Verificadores → Documentos. Detalle en
[`apps/docs/README.md`](apps/docs/README.md).

## 🧰 Recursos

- [Boilerplate de WhatsApp](templates/whatsapp-meta/) — bot que verifica credenciales dentro del chat, directo contra la Cloud API de Meta. `npm run demo` corre la conversación entera sin credenciales.
- [Boilerplate de Moodle](templates/moodle-local-plugin/) — plugin local (PHP) que emite una credencial de curso; la oferta se crea cuando el estudiante la pide. Probado en Moodle 4.5 y 5.1.
- [Colección de Postman — Credenciales](docs/resources/sovra-credenciales.postman_collection.json) — importala y configurá `baseUrl` + `apiKey`.
- [Colección de Postman — mDoc](docs/resources/sovra-mdoc.postman_collection.json) — requiere configurar `baseUrl`, `apiKey`, `schemaId`, `docType` y `namespace`.
- [Colección de Postman — Documentos firmados](docs/resources/sovra-documentos-firmados.postman_collection.json) — importala y configurá `base_url`, `api_key`, `schema_id`.
- Especificación OpenAPI en vivo: `GET {baseUrl}/openapi/api`.

## 🗄 Documentación obsoleta

[`docs/deprecated/`](docs/deprecated/) conserva la documentación de la **plataforma
anterior** (`x-api-key`, `did:quarkid`, DIDComm, BBS+), solo como referencia
histórica. **No la uses para integraciones nuevas**: los endpoints, los formatos y
los eventos no son compatibles con la API actual.

> ⚠️ [`templates/nextjs-nestjs/`](templates/nextjs-nestjs/) todavía
> apunta a la plataforma anterior (usa `x-api-key` y credenciales `did:quarkid`). Está
> pendiente de migrar; mientras tanto, seguí las [guías](apps/docs/src/content/docs/). El template
> [`whatsapp-meta/`](templates/whatsapp-meta/) y
> [`moodle-local-plugin/`](templates/moodle-local-plugin/) **sí** usan la API actual.

## 📝 Contribuir

Las páginas son MDX en [`apps/docs/src/content/docs/`](apps/docs/src/content/docs/). El orden del menú está en
`sidebar.items` de `apps/docs/astro.config.ts`; dentro de cada carpeta, en
`sidebar.order` del frontmatter. Antes de abrir un PR:
`npm run lint:docs && npm run typecheck && npm run build`.
