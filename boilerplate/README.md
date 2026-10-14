# Boilerplate 

Esta carpeta contiene plantillas de código y ejemplos para acelerar el desarrollo de aplicaciones.

## 📁 Estructura

### `/templates/`

Plantillas de código listas para usar:

| Template | Qué hace | Plataforma |
|---|---|---|
| [`whatsapp-meta/`](templates/whatsapp-meta/) | Bot de WhatsApp que verifica credenciales dentro del chat, directo contra la Cloud API de Meta (sin BSP). Node + TypeScript + Express. | ✅ Actual (SD-JWT VC, OID4VP + DCQL, `Bearer sovra_sk_...`) |
| [`moodle-local-plugin/`](templates/moodle-local-plugin/) | Plugin local de Moodle que emite una credencial al completar un curso: notificación, QR o enlace directo a la cartera, webhook opcional con reconciliación. PHP, Moodle 4.5 LTS y 5.x. | ✅ Actual (SD-JWT VC, OID4VCI, `Bearer sovra_sk_...`) |
| [`nextjs-nestjs/`](templates/nextjs-nestjs/) | Proyecto full-stack: frontend Next.js + backend Nest.js, con emisión y verificación. | ⚠️ Anterior (`x-api-key`, `did:quarkid`) — pendiente de migrar |

La documentación de la API vive en [`../docs/guides/`](../docs/guides/).



