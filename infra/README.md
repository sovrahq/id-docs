# infra

Despliegue del sitio de documentación (`apps/docs`).

| Carpeta | Qué hay |
|---|---|
| [`gcp/`](gcp/) | **Producción.** Cloud Run: `nginx.conf` del contenedor y `setup.sh`, la configuración de un solo uso del proyecto. El `Dockerfile` está en la raíz. |
| [`cloudflare/`](cloudflare/) | Alternativa, sin CI: `wrangler.jsonc` para publicar `apps/docs/dist` en Cloudflare con `npm run deploy:cloudflare`. |
