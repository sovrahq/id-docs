import { defineConfig } from "astro/config";
import tailwindcss from "@tailwindcss/vite";
import nimbus, {
  defineConfig as defineNimbusConfig,
} from "@cloudflare/nimbus-docs";
import { tableScroll } from "@cloudflare/nimbus-docs/markdown";
import { mermaidFences } from "./src/lib/mermaid-fences";

const nimbusConfig = defineNimbusConfig({
  // El dominio real del sitio (sin slash final). Se usa para las URL
  // canónicas, las imágenes OG, robots.txt, el sitemap y /llms.txt. En CI lo
  // pasa la variable SITE_URL (ver infra/gcp/README.md).
  site: process.env.SITE_URL || "https://example.com",
  title: "Sovra ID",
  description: "Documentación de la API de Sovra ID: credenciales verificables, mDoc y documentos firmados.",
  locale: "es",
  github: "https://github.com/sovrahq/id-docs",
  socialImageAlt: "Documentación de Sovra ID",
  // Primero los conceptos; después una sección por rol: quien emite
  // credenciales, quien las verifica y quien emite documentos.
  sidebar: {
    items: [
      "index",
      { label: "Conceptos", autogenerate: { directory: "conceptos" } },
      "primeros-pasos",
      { label: "Credenciales", autogenerate: { directory: "credenciales" } },
      { label: "Verificadores", autogenerate: { directory: "verificadores" } },
      { label: "Documentos", autogenerate: { directory: "documentos" } },
      "webhooks",
      { label: "Integraciones", autogenerate: { directory: "integraciones" } },
      { label: "Errores", autogenerate: { directory: "errores" } },
      { label: "Referencia de API", link: "/api/" },
    ],
  },
  // Referencia de API, servida en /api. El spec se genera desde las
  // colecciones de Postman del repo (ver packages/openapi y tools/build_openapi.py).
  api: [
    {
      collection: "api",
      spec: "../../packages/openapi/openapi.json",
      routes: {
        convention: "resource-action-v2",
        stripPathPrefixes: ["/api/v1"],
      },
    },
  ],
});

export default defineConfig({
  // nimbus:adapter
  output: "static",
  // Tailwind v4 via its Vite plugin (the integration Astro recommends for
  // Tailwind v4 — replaces the PostCSS plugin, which doesn't build under
  // Astro 7's Vite 8 bundler).
  vite: {
    plugins: [tailwindcss()],
  },
  // Hover-prefetch link targets so full-page navigations feel instant without
  // a client-side router.
  prefetch: {
    prefetchAll: true,
    defaultStrategy: "hover",
  },
  integrations: [
    nimbus(nimbusConfig, {
      rules: {
        "nimbus/frontmatter-shape": "error",
        "nimbus/internal-link": "error",
      },
      markdown: {
        // Wrap wide tables so they scroll instead of overflowing the page
        // (styled by `.nb-table-scroll` in src/styles/prose.css).
        hastPlugins: [
          tableScroll(),
          // ```mermaid fences become diagrams (drawn by src/scripts/mermaid.ts).
          mermaidFences(),
        ],
      },
    }),
  ],
});
