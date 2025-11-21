<p align="center">
  <a href="http://nestjs.com/" target="blank"><img src="https://nestjs.com/img/logo-small.svg" width="120" alt="Nest Logo" /></a>
</p>

[circleci-image]: https://img.shields.io/circleci/build/github/nestjs/nest/master?token=abc123def456
[circleci-url]: https://circleci.com/gh/nestjs/nest

  <p align="center">A progressive <a href="http://nodejs.org" target="_blank">Node.js</a> framework for building efficient and scalable server-side applications.</p>
    <p align="center">
<a href="https://www.npmjs.com/~nestjscore" target="_blank"><img src="https://img.shields.io/npm/v/@nestjs/core.svg" alt="NPM Version" /></a>
<a href="https://www.npmjs.com/~nestjscore" target="_blank"><img src="https://img.shields.io/npm/l/@nestjs/core.svg" alt="Package License" /></a>
<a href="https://www.npmjs.com/~nestjscore" target="_blank"><img src="https://img.shields.io/npm/dm/@nestjs/common.svg" alt="NPM Downloads" /></a>
<a href="https://circleci.com/gh/nestjs/nest" target="_blank"><img src="https://img.shields.io/circleci/build/github/nestjs/nest/master" alt="CircleCI" /></a>
<a href="https://coveralls.io/github/nestjs/nest?branch=master" target="_blank"><img src="https://coveralls.io/repos/github/nestjs/nest/badge.svg?branch=master#9" alt="Coverage" /></a>
<a href="https://discord.gg/G7Qnnhy" target="_blank"><img src="https://img.shields.io/badge/discord-online-brightgreen.svg" alt="Discord"/></a>
<a href="https://opencollective.com/nest#backer" target="_blank"><img src="https://opencollective.com/nest/backers/badge.svg" alt="Backers on Open Collective" /></a>
<a href="https://opencollective.com/nest#sponsor" target="_blank"><img src="https://opencollective.com/nest/sponsors/badge.svg" alt="Sponsors on Open Collective" /></a>
  <a href="https://paypal.me/kamilmysliwiec" target="_blank"><img src="https://img.shields.io/badge/Donate-PayPal-ff3f59.svg" alt="Donate us"/></a>
    <a href="https://opencollective.com/nest#sponsor"  target="_blank"><img src="https://img.shields.io/badge/Support%20us-Open%20Collective-41B883.svg" alt="Support us"></a>
  <a href="https://twitter.com/nestframework" target="_blank"><img src="https://img.shields.io/twitter/follow/nestframework.svg?style=social&label=Follow" alt="Follow us on Twitter"></a>
</p>
  <!--[![Backers on Open Collective](https://opencollective.com/nest/backers/badge.svg)](https://opencollective.com/nest#backer)
  [![Sponsors on Open Collective](https://opencollective.com/nest/sponsors/badge.svg)](https://opencollective.com/nest#sponsor)-->

## Description

Backend NestJS con integración a Sovra ID para gestión de credenciales verificables.

### Características

- ✅ Registro de usuarios con generación de token
- ✅ Solicitud de credenciales a Sovra ID
- ✅ Webhook para recibir notificaciones cuando se asocia una credencial
- ✅ Almacenamiento de `invitationId` y `holderDid` en la base de datos

## Configuración

### 1. Variables de Entorno

Crea un archivo `.env` en la raíz del proyecto basándote en `.env.example`:

```bash
# Database Configuration
DB_HOST=localhost
DB_PORT=5432
DB_USERNAME=postgres
DB_PASSWORD=postgres
DB_DATABASE=sovra_db

# Sovra ID Configuration
SOVRA_API_KEY=your_api_key_here
SOVRA_API_URL=https://id.api.sandbox.sovra.io/api
SOVRA_WORKSPACE_ID=your_workspace_id_here

# Application
NODE_ENV=development
PORT=3000
```

### 2. Base de Datos

Asegúrate de tener PostgreSQL corriendo y crear la base de datos:

```sql
CREATE DATABASE sovra_db;
```

### 3. Instalación

```bash
$ pnpm install
```

## Compile and run the project

```bash
# development
$ pnpm run start

# watch mode
$ pnpm run start:dev

# production mode
$ pnpm run start:prod
```

## Uso de la API

### 1. Registrar un Usuario

```bash
POST /users
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password123",
  "firstName": "John",
  "lastName": "Doe"
}
```

**Respuesta:**
```json
{
  "id": "uuid",
  "email": "user@example.com",
  "firstName": "John",
  "lastName": "Doe",
  "token": "generated_token_here",
  "invitationId": null,
  "credentialId": null,
  "holderDid": null,
  "createdAt": "2025-01-01T00:00:00.000Z",
  "updatedAt": "2025-01-01T00:00:00.000Z"
}
```

### 2. Solicitar una Credencial

```bash
POST /users/{userId}/credentials
Content-Type: application/json

{
  "credential": {
    "@context": [
      "https://www.w3.org/2018/credentials/v1",
      "https://www.w3.org/2018/credentials/examples/v1",
      "https://w3id.org/security/bbs/v1"
    ],
    "type": ["VerifiableCredential"],
    "expirationDate": "2027-10-15",
    "credentialSubject": {
      "givenName": "John",
      "familyName": "Doe"
    }
  },
  "outputDescriptor": {
    "id": "credential_output",
    "schema": "https://example.com/schema",
    "display": {
      "title": { "text": "Credential Title" },
      "subtitle": { "text": "Credential Subtitle" },
      "description": { "text": "Credential Description" },
      "properties": [
        {
          "path": ["$.credentialSubject.givenName"],
          "fallback": "Unknown",
          "label": "Nombre(s)",
          "schema": { "type": "string" }
        }
      ]
    },
    "styles": {
      "text": { "color": "#ffffff" },
      "hero": {
        "uri": "https://storage.googleapis.com/sovra_brand/BG.png",
        "alt": "Background"
      },
      "background": { "color": "#0b1f45" },
      "thumbnail": {
        "uri": "https://storage.googleapis.com/sovra_brand/Logo.png",
        "alt": "Logo"
      }
    }
  }
}
```

**Respuesta:**
```json
{
  "id": "credential_id",
  "invitation_wallet": {
    "invitationId": "invitation_id",
    "invitationContent": "didcomm://?..."
  },
  "credential": { ... },
  "outputDescriptor": { ... },
  "user": {
    "id": "user_id",
    "email": "user@example.com"
  }
}
```

El `invitationId` se guarda automáticamente en el usuario. Usa `invitationContent` para generar el QR que el usuario escaneará.

### 3. Webhook

El webhook está disponible en:

```
POST /webhooks
```

Cuando un usuario escanea el QR y asocia la credencial a su wallet, Sovra ID enviará un webhook a esta URL. El sistema actualizará automáticamente el campo `holderDid` del usuario.

**Nota:** Asegúrate de configurar la URL del webhook en tu workspace de Sovra ID:
```
https://tu-dominio.com/webhooks
```

### 4. Consultar Usuario

```bash
GET /users/{userId}
```

Verás el `holderDid` actualizado después de que el usuario escanee el QR.

## Run tests

```bash
# unit tests
$ pnpm run test

# e2e tests
$ pnpm run test:e2e

# test coverage
$ pnpm run test:cov
```

## Resources

Check out a few resources that may come in handy when working with NestJS:

- Visit the [NestJS Documentation](https://docs.nestjs.com) to learn more about the framework.
- For questions and support, please visit our [Discord channel](https://discord.gg/G7Qnnhy).
- To dive deeper and get more hands-on experience, check out our official video [courses](https://courses.nestjs.com/).
- Visualize your application graph and interact with the NestJS application in real-time using [NestJS Devtools](https://devtools.nestjs.com).
- Need help with your project (part-time to full-time)? Check out our official [enterprise support](https://enterprise.nestjs.com).
- To stay in the loop and get updates, follow us on [X](https://x.com/nestframework) and [LinkedIn](https://linkedin.com/company/nestjs).
- Looking for a job, or have a job to offer? Check out our official [Jobs board](https://jobs.nestjs.com).

## Support

Nest is an MIT-licensed open source project. It can grow thanks to the sponsors and support by the amazing backers. If you'd like to join them, please [read more here](https://docs.nestjs.com/support).

## Stay in touch

- Author - [Kamil Myśliwiec](https://twitter.com/kammysliwiec)
- Website - [https://nestjs.com](https://nestjs.com/)
- Twitter - [@nestframework](https://twitter.com/nestframework)

## License

Nest is [MIT licensed](https://github.com/nestjs/nest/blob/master/LICENSE).
