# Sovra ID docs — guía para agentes

Monorepo (npm workspaces + Turborepo) con la documentación de la API de Sovra ID.

## Dónde va cada cosa

| Ruta | Qué es | ¿Se edita a mano? |
|---|---|---|
| `docs/deprecated/` | Plataforma anterior y las guías viejas por formato (`guias-por-formato/`) | No — archivo |
| `docs/resources/` | Colecciones de Postman | Sí |
| `docs/assets/brand/` | Logos del press kit de Sovra (https://sovra.io/es/press-kit) | No |
| `apps/docs/` | Sitio Astro + Nimbus. Seguí `apps/docs/AGENT.md` | Sí |
| `apps/docs/src/content/docs/` | Las páginas del sitio (MDX), por concepto | Sí — es la fuente de verdad |
| `packages/openapi/openapi.json` | Spec de `/api/v1` | No — `npm run openapi` o la spec real del backend |
| `templates/` | Plantillas de integración, cada una con su propio `package.json` (fuera de los workspaces) | Sí |
| `tools/` | Scripts Python de generación | Sí |
| `infra/cloudflare/` | `wrangler.jsonc` del sitio | Sí |
| `openspec/` | Propuestas de cambio | Sí |

## Flujo

1. Editá o agregá la página en `apps/docs/src/content/docs/<seccion>/<slug>.mdx`.
   Secciones: `conceptos/`, `credenciales/` (emisor), `verificadores/`,
   `documentos/`, `integraciones/`, `errores/`, más `primeros-pasos.mdx` y
   `webhooks.mdx` en la raíz.
2. Diagramas con bloques ```` ```mermaid ````; ciclos de vida con `stateDiagram-v2`.
3. `npm run lint:docs && npm run typecheck && npm run build` antes de abrir un PR.

Las guías y el sitio están en español (rioplatense, voseo).
