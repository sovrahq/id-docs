# @sovra-id/docs — sitio de documentación

La documentación de Sovra ID, ordenada por concepto, sobre [Nimbus](https://github.com/cloudflare/nimbus), el framework de
documentación para Astro que usa Cloudflare.

## Cómo verlo

Requiere Node.js 22.23.4 o superior.

Desde la raíz del monorepo:

```sh
npm install
npm run dev
```

Abre `http://localhost:4321`. Para compilar el sitio estático: `npm run build`
(queda en `apps/docs/dist/`).

## Qué incluye

- **Guías** (`src/content/docs/`): ordenadas por concepto — Conceptos, Primeros
  pasos, Credenciales, Verificadores, Documentos, Webhooks, Integraciones y Errores.
- **Referencia de API** (`/api`): 18 operaciones generadas desde
  `packages/openapi/openapi.json`.
- **Diagramas**: los bloques ```` ```mermaid ```` se dibujan en el navegador
  (`src/lib/mermaid-fences.ts` + `src/scripts/mermaid.ts`).
- **Búsqueda** con Pagefind, tema claro/oscuro, versión Markdown de cada página
  y `llms.txt`.

## Dónde se escribe

Las páginas son MDX en `src/content/docs/` y se editan a mano. El orden del menú
está en `sidebar.items` de `astro.config.ts`; dentro de cada carpeta manda
`sidebar.order` del frontmatter. La spec de la API vive en
[`packages/openapi`](../../packages/openapi/) y el deploy en
[`infra/cloudflare`](../../infra/cloudflare/).

## Pendientes

- **Spec real de la API.** El repo no trae un archivo OpenAPI, solo colecciones
  de Postman sin respuestas de ejemplo. Por eso los esquemas del cuerpo están
  inferidos de los ejemplos (todos los campos figuran como opcionales) y las
  respuestas son genéricas. Reemplazar `packages/openapi/openapi.json` por la salida de
  `GET {baseUrl}/openapi/api` lo corrige.
- **Dominio.** `site` en `astro.config.ts` sigue con el valor de ejemplo.
- **Textos de la interfaz** ("Search", "On this page", "Copy page") siguen en
  inglés.
- **Chat para dudas y botón "Try it"** de los endpoints: no están instalados.
