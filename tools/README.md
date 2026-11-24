# tools

| Script | Entrada | Salida |
|---|---|---|
| `build_openapi.py` | `docs/resources/*.postman_collection.json` | `packages/openapi/openapi.json` |

```sh
npm run openapi   # colecciones de Postman → OpenAPI
```

`build_openapi.py` arma el spec desde las colecciones de Postman. Los esquemas del
cuerpo se infieren de los ejemplos; cuando exista la spec real del backend
(`GET {baseUrl}/openapi/api`), reemplazá `packages/openapi/openapi.json` por ella.
