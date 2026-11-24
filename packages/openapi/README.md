# @sovra-id/openapi

Spec OpenAPI 3 de la API de Sovra ID (`/api/v1`). La lee `apps/docs` para la
referencia en `/api`.

Se genera con `npm run openapi` desde las colecciones de Postman de
`docs/resources/`. Como las colecciones no traen respuestas de ejemplo, los
esquemas están inferidos; reemplazar `openapi.json` por la salida de
`GET {baseUrl}/openapi/api` lo corrige.
