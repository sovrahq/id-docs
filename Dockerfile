# Sitio de documentación (apps/docs) como contenedor estático para Cloud Run.
# Se construye desde la raíz del monorepo: docker build -t sovra-id-docs .

# --- build: compila el sitio con Astro ---
FROM node:22-slim@sha256:be979e5523c76fa4d5ca4ebd3099078f220aa47ec39c48ad5b5df9314debd6eb AS build
WORKDIR /repo

# Primero los manifiestos, para cachear `npm ci` mientras no cambien las dependencias.
COPY package.json package-lock.json turbo.json ./
COPY apps/docs/package.json apps/docs/
COPY packages/openapi/package.json packages/openapi/
RUN npm ci

COPY apps/docs apps/docs
COPY packages/openapi packages/openapi

# URL pública del sitio: canónicas, sitemap, robots.txt y llms.txt dependen de ella.
ARG SITE_URL
ENV SITE_URL=${SITE_URL}
RUN npx turbo run build --filter=@sovra-id/docs

# --- runtime: nginx sirve dist/ ---
FROM nginx:1.27-alpine@sha256:65645c7bb6a0661892a8b03b89d0743208a18dd2f3f17a54ef4b76fb8e2f2a10
COPY infra/gcp/nginx.conf /etc/nginx/templates/default.conf.template
COPY --from=build /repo/apps/docs/dist /usr/share/nginx/html
# Cloud Run inyecta PORT; la imagen oficial de nginx sustituye ${PORT} en la plantilla.
ENV PORT=8080
EXPOSE 8080
