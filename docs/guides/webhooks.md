# Webhooks

## Tabla de Contenidos
1. [Introducción](#introducción)
2. [Configuración](#configuración)
3. [Eventos Disponibles](#eventos-disponibles)
   - 3.1 [credential-issued](#31-credential-issued)
   - 3.2 [verifiable-presentation-finished](#32-verifiable-presentation-finished)
4. [Estructura del Payload](#estructura-del-payload)
5. [Seguridad](#seguridad)
6. [Implementación](#implementación)

---

## Introducción

Los webhooks de Sovra ID te permiten recibir notificaciones en tiempo real cuando ocurren eventos importantes en tu workspace. En lugar de hacer polling constante a la API, tu servidor recibirá automáticamente una petición HTTP POST cuando:

- Una credencial es emitida exitosamente a un holder
- Una verificación de credencial es completada

### Beneficios

- **Tiempo real**: Recibe notificaciones instantáneas de eventos
- **Eficiencia**: No necesitas hacer polling constante a la API
- **Automatización**: Permite crear flujos automatizados basados en eventos

---

## Configuración

La configuración de webhooks se encuentra en tu workspace. Puedes obtener la información de tu webhook consultando el endpoint `/workspaces/status`:

```json
{
  "webhook_url": "https://tu-servidor.com/webhooks",
  "webhook_secret": "tu_secreto_webhook"
}
```

| Campo | Descripción |
|-------|-------------|
| `webhook_url` | URL de tu servidor donde se enviarán los eventos |
| `webhook_secret` | Secreto para validar la autenticidad de los webhooks |

Para configurar o actualizar la URL del webhook, contacta al equipo de soporte o configúralo desde la aplicación de Sovra ID.

---

## Eventos Disponibles

### 3.1 credential-issued

Este evento se dispara cuando una credencial ha sido emitida exitosamente y el holder la ha aceptado en su wallet.

**Payload de ejemplo:**

```json
{
  "eventType": "credential-issued",
  "eventData": {
    "vc": {
      "@context": [
        "https://www.w3.org/2018/credentials/v1",
        "https://www.w3.org/2018/credentials/examples/v1",
        "https://w3id.org/security/bbs/v1",
        {
          "participantType": {
            "@id": "https://example.org/vocab#participantType",
            "@type": "xsd:string"
          }
        }
      ],
      "id": "https://example.com/123123",
      "type": ["VerifiableCredential"],
      "issuanceDate": "2025-01-16T01:50:37.24-03:00",
      "expirationDate": "2025-12-31",
      "credentialSubject": {
        "givenName": "Santiago",
        "familyName": "Rdz",
        "participantType": "Startup",
        "id": "did:quarkid:EiD9QAEn52DplsVKnn03F1IvIwjwG5bMTM0EXSlhYv1jsw"
      },
      "proof": {
        "type": "BbsBlsSignature2020",
        "created": "2025-05-21T23:15:11Z",
        "proofPurpose": "assertionMethod",
        "proofValue": "iMPWFLsE1GsY4SqApZyw9390WiVJHBvg9kgRp9LaRtZB55GhP8KZaZS3++8p2FB+YUHlKfzccE4m7waZyoLEkBLFiK2g54Q2i+CdtYBgDdkUDsoULSBMcH1MwGHwdjfXpldFNFrHFx/IAvLVniyeMQ==",
        "verificationMethod": "did:quarkid:EiD7gc9YasFm0WV1iqPFnM2kbxyI2Yzfmv7kVJGI6Pvbzg#vc-bbsbls"
      },
      "credentialStatus": {}
    },
    "holderDID": "did:quarkid:EiD9QAEn52DplsVKnn03F1IvIwjwG5bMTM0EXSlhYv1jsw",
    "invitationId": "c64a00b4-969a-4d6c-a6c3-7180dafe5e13"
  }
}
```

**Campos del evento:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `eventType` | string | Tipo de evento: `credential-issued` |
| `eventData.vc` | object | La credencial verificable completa con su prueba criptográfica |
| `eventData.vc.@context` | array | Contextos JSON-LD de la credencial |
| `eventData.vc.id` | string | ID único de la credencial |
| `eventData.vc.type` | array | Tipos de la credencial |
| `eventData.vc.issuanceDate` | string | Fecha de emisión |
| `eventData.vc.expirationDate` | string | Fecha de expiración |
| `eventData.vc.credentialSubject` | object | Datos del portador de la credencial |
| `eventData.vc.proof` | object | Prueba criptográfica de la credencial |
| `eventData.holderDID` | string | DID del portador de la credencial |
| `eventData.invitationId` | string | ID de la invitación utilizada para emitir la credencial |

---

### 3.2 verifiable-presentation-finished

Este evento se dispara cuando una verificación de credencial ha sido completada, ya sea exitosa o fallida.

**Payload de ejemplo:**

```json
{
  "eventType": "verifiable-presentation-finished",
  "eventData": {
    "role": "VERIFIER",
    "verified": true,
    "holderDID": "did:quarkid:EiCyEqmt68P5hYxcUZb5cbI-0jGeNMU8Ti5GnOow7tUQBA",
    "verifierDID": "did:quarkid:EiCMsbgQsIgb_YgZlQfhI3_lzJ_VcBms7ZkYD_1dt_uK3A",
    "invitationId": "093adec6-c86e-4a7a-81c0-fa2a7e9901c0",
    "verifiableCredentials": [
      {
        "@context": [
          "https://www.w3.org/2018/credentials/v1",
          "https://www.w3.org/2018/credentials/examples/v1",
          "https://w3id.org/security/bbs/v1",
          {
            "participantType": {
              "@id": "https://example.org/vocab#participantType",
              "@type": "xsd:string"
            }
          }
        ],
        "id": "https://example.com/123123",
        "type": ["VerifiableCredential"],
        "issuanceDate": "2025-01-16T01:50:37.24-03:00",
        "expirationDate": "2025-12-31",
        "credentialSubject": {
          "givenName": "Santiago",
          "familyName": "Rdz",
          "participantType": "Startup",
          "id": "did:quarkid:EiCyEqmt68P5hYxcUZb5cbI-0jGeNMU8Ti5GnOow7tUQBA"
        },
        "proof": {
          "type": "BbsBlsSignature2020",
          "created": "2025-05-22T23:25:09Z",
          "proofPurpose": "assertionMethod",
          "proofValue": "r5KogTOAbfvvgT7iTuB0bNL7EGQHMvynb4Bi3p/HPXtJmZfuA5Trx0jCVXQop9acYUHlKfzccE4m7waZyoLEkBLFiK2g54Q2i+CdtYBgDdkUDsoULSBMcH1MwGHwdjfXpldFNFrHFx/IAvLVniyeMQ==",
          "verificationMethod": "did:quarkid:EiCMsbgQsIgb_YgZlQfhI3_lzJ_VcBms7ZkYD_1dt_uK3A#vc-bbsbls"
        },
        "credentialStatus": {}
      }
    ]
  }
}
```

**Campos del evento:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `eventType` | string | Tipo de evento: `verifiable-presentation-finished` |
| `eventData.role` | string | Rol en la verificación: `VERIFIER` |
| `eventData.verified` | boolean | Indica si la verificación fue exitosa (`true`) o fallida (`false`) |
| `eventData.holderDID` | string | DID del portador de la credencial verificada |
| `eventData.verifierDID` | string | DID del verificador (tu workspace) |
| `eventData.invitationId` | string | ID de la presentación (corresponde al `presentationId` retornado al crear la verificación) |
| `eventData.verifiableCredentials` | array | Array de credenciales verificables presentadas |

**Nota importante:** El `invitationId` corresponde al `presentationId` que se obtiene al crear una verificación. Usa este ID para correlacionar el evento con la verificación original en tu sistema.

---

## Estructura del Payload

Todos los webhooks siguen la misma estructura base:

```typescript
interface Payload<T = any> {
  eventType: string;
  eventData: T;
  eventWebhookResponse?: any;
}
```

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `eventType` | string | Identificador del tipo de evento |
| `eventData` | object | Datos específicos del evento |
| `eventWebhookResponse` | any | Respuesta adicional del webhook (opcional) |

---

## Seguridad

Para garantizar que los webhooks provienen de Sovra ID:

1. **Verificar el origen**: Valida que las peticiones provengan de los servidores de Sovra ID
2. **Usar HTTPS**: Configura tu endpoint webhook con HTTPS para encriptar la comunicación
3. **Validar el webhook_secret**: Utiliza el `webhook_secret` de tu workspace para validar la autenticidad de los eventos

**Recomendaciones:**

- Responde rápidamente (< 5 segundos) para evitar reintentos
- Implementa idempotencia para manejar posibles duplicados
- Registra todos los eventos recibidos para debugging

---

## Implementación

### Ejemplo en Node.js/Express

```javascript
const express = require('express');
const app = express();

app.use(express.json());

app.post('/webhooks', (req, res) => {
  const { eventType, eventData } = req.body;

  switch (eventType) {
    case 'credential-issued':
      console.log('Credencial emitida:', eventData.vc.id);
      console.log('Holder DID:', eventData.holderDID);
      // Tu lógica de negocio aquí
      break;

    case 'verifiable-presentation-finished':
      console.log('Verificación completada');
      console.log('Verificado:', eventData.verified);
      console.log('Holder DID:', eventData.holderDID);
      // Tu lógica de negocio aquí
      break;

    default:
      console.log('Evento desconocido:', eventType);
  }

  // Responder con 200 para confirmar recepción
  res.status(200).send('OK');
});

app.listen(3000, () => {
  console.log('Webhook server listening on port 3000');
});
```

### Ejemplo en NestJS

```typescript
import { Controller, Post, Body, HttpCode } from '@nestjs/common';

interface WebhookPayload {
  eventType: string;
  eventData: any;
}

@Controller('webhooks')
export class WebhooksController {
  @Post()
  @HttpCode(200)
  handleWebhook(@Body() payload: WebhookPayload) {
    const { eventType, eventData } = payload;

    switch (eventType) {
      case 'credential-issued':
        this.handleCredentialIssued(eventData);
        break;

      case 'verifiable-presentation-finished':
        this.handleVerificationFinished(eventData);
        break;
    }

    return { received: true };
  }

  private handleCredentialIssued(eventData: any) {
    console.log('Credencial emitida:', eventData.vc.id);
    console.log('Holder DID:', eventData.holderDID);
    // Tu lógica de negocio aquí
  }

  private handleVerificationFinished(eventData: any) {
    console.log('Verificación completada');
    console.log('Verificado:', eventData.verified);
    // Tu lógica de negocio aquí
  }
}
```

---

## Ver también

- [Documentación API Sovra ID](./api-sovra-id.md) - Referencia completa de la API
- [Creación de Credenciales](./api-sovra-id.md#32-creación-y-gestión-de-credenciales) - Cómo crear credenciales
- [Creación de Verificaciones](./api-sovra-id.md#33-creación-y-gestión-de-verificación) - Cómo crear verificaciones
