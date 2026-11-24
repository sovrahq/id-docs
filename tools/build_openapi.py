"""Arma un OpenAPI 3.1 a partir de las colecciones de Postman de sovrahq/id-docs.

Uso: python3 -I build_openapi.py <resources_dir> <out.json>

Las colecciones no traen respuestas de ejemplo, así que cada operación queda con
una respuesta genérica. Los esquemas del cuerpo se infieren del ejemplo.
"""
import json
import os
import re
import sys

res, out = sys.argv[1], sys.argv[2]

TAGS = {
    "issuer": ("Credenciales", "Ciclo de vida de emisión de credenciales, acotado al workspace de la API key."),
    "schemas": ("Esquemas", "Creación y consulta de esquemas de credenciales."),
    "verifier": ("Verificaciones", "Sesiones de verificación OID4VP, acotadas al workspace de la API key."),
    "documents": ("Documentos firmados", "Emisión, visibilidad, listado y lectura pública de documentos firmados."),
    "status": ("Listas de estado", "Listas de estado de revocación y suspensión. No requieren autenticación."),
}

OP_IDS = {
    ("post", "/api/v1/issuer/credential-offer"): "createCredentialOffer",
    ("get", "/api/v1/issuer/credentials"): "listCredentials",
    ("get", "/api/v1/issuer/credentials/{credentialId}"): "getCredential",
    ("put", "/api/v1/issuer/credentials/{credentialId}/status/revoked"): "revokeCredential",
    ("put", "/api/v1/issuer/credentials/{credentialId}/status/suspended"): "suspendCredential",
    ("put", "/api/v1/issuer/credentials/{credentialId}/status/issued"): "reactivateCredential",
    ("post", "/api/v1/verifier/verifications"): "createVerification",
    ("get", "/api/v1/verifier/verifications"): "listVerifications",
    ("get", "/api/v1/verifier/verifications/{verificationId}"): "getVerification",
    ("get", "/api/v1/status/{issuerDid}/revocation"): "getRevocationStatusList",
    ("get", "/api/v1/status/{issuerDid}/suspension"): "getSuspensionStatusList",
    ("post", "/api/v1/issuer/schemas"): "createSchema",
    ("get", "/api/v1/issuer/schemas"): "listSchemas",
    ("post", "/api/v1/issuer/documents"): "issueDocument",
    ("put", "/api/v1/issuer/documents/{documentId}/visibility/public"): "makeDocumentPublic",
    ("put", "/api/v1/issuer/documents/{documentId}/visibility/private"): "makeDocumentPrivate",
    ("get", "/api/v1/issuer/documents"): "listDocuments",
    ("get", "/api/v1/documents/{documentId}"): "getPublicDocument",
}


def camel(name):
    parts = name.split("_")
    return parts[0] + "".join(p.capitalize() for p in parts[1:])


def tag_for(path):
    if "/schemas" in path:
        return "schemas"
    if "/documents" in path:
        return "documents"
    if "/status/" in path and "/issuer/" not in path:
        return "status"
    if "/verifier/" in path:
        return "verifier"
    return "issuer"


def infer(value):
    if isinstance(value, bool):
        return {"type": "boolean"}
    if isinstance(value, int):
        return {"type": "integer"}
    if isinstance(value, float):
        return {"type": "number"}
    if isinstance(value, str):
        return {"type": "string"}
    if value is None:
        return {}
    if isinstance(value, list):
        return {"type": "array", "items": infer(value[0]) if value else {}}
    return {"type": "object", "properties": {k: infer(v) for k, v in value.items()}}


def requests(items):
    for it in items:
        if "item" in it:
            yield from requests(it["item"])
        else:
            yield it


paths = {}
skipped = []
collections = [
    "sovra-credenciales.postman_collection.json",
    "sovra-documentos-firmados.postman_collection.json",
    "sovra-mdoc-custom.postman_collection.json",
]
for fname in collections:
    col = json.load(open(os.path.join(res, fname), encoding="utf-8"))
    variables = {v.get("key"): str(v.get("value") or "") for v in col.get("variable", [])}
    for it in requests(col["item"]):
        r = it["request"]
        url = r["url"]
        raw = url["raw"] if isinstance(url, dict) else url
        path = re.sub(r"^\{\{[^}]+\}\}", "", raw).split("?")[0]
        path = re.sub(r"\{\{([^}]+)\}\}", lambda m: "{" + camel(m.group(1)) + "}", path)
        method = r["method"].lower()
        if not path.startswith("/api/v1/"):
            skipped.append(f"{method.upper()} {path}")
            continue
        desc = r.get("description") or ""
        if isinstance(desc, dict):
            desc = desc.get("content", "")
        body = (r.get("body") or {}).get("raw")
        if body:
            # Variables de Postman ({{schemaId}}) -> su valor en la colección, o <schemaId>.
            body = re.sub(r"\{\{([^}]+)\}\}", lambda m: variables.get(m.group(1)) or f"<{m.group(1)}>", body)
        example = None
        if body:
            try:
                example = json.loads(body)
            except ValueError:
                example = None

        op = paths.setdefault(path, {}).get(method)
        if op is None:
            tag = tag_for(path)
            op = {
                "operationId": OP_IDS[(method, path)],
                "summary": it["name"],
                "description": desc,
                "tags": [TAGS[tag][0]],
                "parameters": [
                    {"name": p, "in": "path", "required": True, "schema": {"type": "string"}}
                    for p in re.findall(r"\{([^}]+)\}", path)
                ],
                "responses": {
                    "200": {
                        "description": "Respuesta exitosa. La colección de Postman de origen no incluye el cuerpo de la respuesta; ver la guía de referencia."
                    }
                },
            }
            if isinstance(url, dict):
                for q in url.get("query") or []:
                    op["parameters"].append({
                        "name": q["key"], "in": "query", "required": False,
                        "description": q.get("description") or "",
                        "schema": {"type": "integer" if str(q.get("value", "")).isdigit() else "string"},
                        "example": int(q["value"]) if str(q.get("value", "")).isdigit() else q.get("value"),
                    })
            if not op["parameters"]:
                del op["parameters"]
            if tag == "status" or (tag == "documents" and "/issuer/" not in path):
                op["security"] = []
            if example is not None:
                op["requestBody"] = {
                    "required": True,
                    "content": {"application/json": {"schema": infer(example), "examples": {}}},
                }
            paths[path][method] = op
        if example is not None and "requestBody" in op:
            examples = op["requestBody"]["content"]["application/json"]["examples"]
            key = f"ejemplo{len(examples) + 1}"
            examples[key] = {"summary": it["name"], "value": example}

spec = {
    "openapi": "3.1.0",
    "info": {
        "title": "Sovra ID API",
        "version": "v1",
        "description": (
            "API servidor-a-servidor de Sovra ID. Las zonas `issuer` y `verifier` se autentican con una "
            "API key de workspace (`Authorization: Bearer sovra_sk_...`).\n\n"
            "Esta referencia se generó a partir de las colecciones de Postman del repositorio "
            "`sovrahq/id-docs`. La especificación completa se publica en `GET {baseUrl}/openapi/api`."
        ),
    },
    "servers": [
        {"url": "https://test-api-sovra.flagonsa.com", "description": "Test"},
        {"url": "https://api.sovra.io", "description": "Producción"},
    ],
    "tags": [{"name": n, "description": d} for n, d in TAGS.values()],
    "security": [{"apiKey": []}],
    "paths": paths,
    "components": {
        "securitySchemes": {
            "apiKey": {
                "type": "http",
                "scheme": "bearer",
                "description": "API key de workspace con prefijo `sovra_sk_`. Se crea en el dashboard (Settings → API keys).",
            }
        }
    },
}
with open(out, "w", encoding="utf-8") as f:
    json.dump(spec, f, ensure_ascii=False, indent=2)
n = sum(len(v) for v in paths.values())
print(f"{n} operaciones en {len(paths)} rutas; omitidas: {skipped}")
