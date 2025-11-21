# Sovra ID - Boilerplate Next.js + NestJS

Boilerplate completo para autenticación con wallet usando credenciales verificables de Sovra ID. Incluye frontend en Next.js y backend en NestJS con integración a Supabase para eventos en tiempo real.

## 📋 Tabla de Contenidos

- [Descripción](#descripción)
- [Características](#características)
- [Arquitectura](#arquitectura)
- [Requisitos Previos](#requisitos-previos)
- [Instalación](#instalación)
- [Configuración](#configuración)
- [Ejecución](#ejecución)
- [Flujo de Trabajo](#flujo-de-trabajo)
- [API Endpoints](#api-endpoints)
- [Estructura del Proyecto](#estructura-del-proyecto)

## 🎯 Descripción

Este proyecto implementa un sistema completo de autenticación basado en credenciales verificables (Verifiable Credentials) usando Sovra ID. Los usuarios pueden:

1. **Registrarse** con email y contraseña
2. **Conectar su wallet** escaneando un código QR
3. **Iniciar sesión** usando su wallet mediante verificación de credenciales

El sistema utiliza Supabase para escuchar eventos en tiempo real cuando se completan las verificaciones o se conectan las wallets.

## 🏛️ Características

### Backend (NestJS)
- ✅ Registro de usuarios con JWT
- ✅ Generación de credenciales verificables
- ✅ Webhooks para recibir notificaciones de Sovra ID
- ✅ Gestión de credenciales separada de usuarios
- ✅ Sistema de verificación para login
- ✅ Autenticación basada en JWT

### Frontend (Next.js)
- ✅ Registro de usuarios
- ✅ Conexión de wallet mediante QR
- ✅ Login con wallet mediante QR
- ✅ Dashboard básico
- ✅ Escucha de eventos en tiempo real con Supabase
- ✅ UI moderna con shadcn/ui

## 🏗️ Arquitectura

```
┌─────────────┐         ┌─────────────┐         ┌─────────────┐
│   Frontend  │────────▶│   Backend   │────────▶│  Sovra ID   │
│  (Next.js)  │  HTTP   │  (NestJS)   │  API    │     API     │
└─────────────┘         └─────────────┘         └─────────────┘
      │                        │                        │
      │                        │                        │
      │                        ▼                        │
      │                 ┌─────────────┐                │
      │                 │  PostgreSQL │                │
      │                 │  Database   │                │
      │                 └─────────────┘                │
      │                        │                        │
      │                        │                        │
      └────────────────────────┼────────────────────────┘
                               │
                               ▼
                        ┌─────────────┐
                        │  Supabase   │
                        │  (Real-time)│
                        └─────────────┘
```

## 📦 Requisitos Previos

- **Node.js** >= 18.x
- **pnpm** >= 8.x (o npm/yarn)
- **PostgreSQL** >= 14.x
- **Cuenta de Sovra ID** con API Key y Workspace ID
- **Cuenta de Supabase** (opcional, para eventos en tiempo real)

## 🚀 Instalación

### 1. Clonar el repositorio

```bash
git clone <repository-url>
cd boilerplate/templates/nextjs-nestjs
```

### 2. Instalar dependencias del Backend

```bash
cd backend
pnpm install
```

### 3. Instalar dependencias del Frontend

```bash
cd ../frontend
pnpm install
```

## ⚙️ Configuración

### Backend - Variables de Entorno

Crea un archivo `.env` en la carpeta `backend/`:

```bash
# Database Configuration
DB_HOST=localhost
DB_PORT=5432
DB_USERNAME=postgres
DB_PASSWORD=postgres
DB_DATABASE=sovra_db
DB_SCHEMA=public

# Sovra ID Configuration
SOVRA_ID_API_KEY=your_sovra_api_key_here
SOVRA_API_URL=https://id.api.sandbox.sovra.io/api
SOVRA_ID_WORKSPACE_ID=your_workspace_id_here

# JWT Configuration (opcional, se genera automáticamente)
JWT_SECRET=your_jwt_secret_here

# Application
NODE_ENV=development
PORT=4000
```

### Frontend - Variables de Entorno

Crea un archivo `.env.local` en la carpeta `frontend/`:

```bash
# API Configuration
NEXT_PUBLIC_API_BASE_URL=http://localhost:4000

# Supabase Configuration (para eventos en tiempo real)
NEXT_PUBLIC_SUPABASE_URL=your_supabase_url_here
NEXT_PUBLIC_SUPABASE_ANON_KEY=your_supabase_anon_key_here
NEXT_PUBLIC_DB_SCHEMA=public
```

### Base de Datos

1. Asegúrate de tener PostgreSQL corriendo
2. Crea la base de datos:

```sql
CREATE DATABASE sovra_db;
```

3. El backend creará automáticamente las tablas usando TypeORM `synchronize: true`

### Configuración de Supabase

1. Crea un proyecto en [Supabase](https://supabase.com)
2. Conecta tu base de datos PostgreSQL a Supabase o usa la base de datos de Supabase
3. Habilita Realtime para las tablas `credentials` y `verifications`:
   - Ve a Database → Replication
   - Habilita la replicación para `credentials` y `verifications`
4. Obtén tu URL y Anon Key desde Settings → API

### Configuración de Sovra ID

1. Obtén tu API Key desde el dashboard de Sovra ID
2. Obtén tu Workspace ID
3. Configura el webhook en tu workspace de Sovra ID:
   ```
   https://tu-dominio.com/webhooks
   ```
   
   **Nota para desarrollo local:** Usa [ngrok](https://ngrok.com) o similar para exponer tu backend:
   ```bash
   ngrok http 4000
   # Usa la URL de ngrok en la configuración del webhook
   ```

## 🏃 Ejecución

### Desarrollo

#### Terminal 1 - Backend

```bash
cd backend
pnpm run start:dev
```

El backend estará disponible en `http://localhost:4000`

#### Terminal 2 - Frontend

```bash
cd frontend
pnpm dev
```

El frontend estará disponible en `http://localhost:3000`

### Producción

#### Backend

```bash
cd backend
pnpm run build
pnpm run start:prod
```

#### Frontend

```bash
cd frontend
pnpm build
pnpm start
```

## 🔄 Flujo de Trabajo

### 1. Registro de Usuario

```
Usuario → Frontend (/register)
  ↓
POST /users (Backend)
  ↓
Crea usuario en DB
  ↓
Genera JWT token
  ↓
Retorna token al frontend
  ↓
Redirige a /link-wallet
```

### 2. Conexión de Wallet

```
Usuario → Frontend (/link-wallet)
  ↓
POST /users/link-wallet (Backend) [Requiere JWT]
  ↓
Genera credencial en Sovra ID
  ↓
Crea registro en tabla `credentials`
  ↓
Retorna QR code (invitationContent)
  ↓
Frontend muestra QR
  ↓
Usuario escanea QR con wallet
  ↓
Sovra ID → Webhook POST /webhooks
  ↓
Backend actualiza `credentials.holder_did`
  ↓
Supabase emite evento UPDATE
  ↓
Frontend detecta cambio → Muestra éxito → Redirige a /dashboard
```

### 3. Login con Wallet

```
Usuario → Frontend (/login)
  ↓
POST /verifications/login (Backend)
  ↓
Crea verificación en Sovra ID
  ↓
Crea registro en tabla `verifications`
  ↓
Retorna QR code (presentationContent)
  ↓
Frontend muestra QR y escucha Supabase
  ↓
Usuario escanea QR con wallet
  ↓
Sovra ID → Webhook POST /webhooks
  ↓
Backend actualiza `verifications.verified = true`
  ↓
Supabase emite evento UPDATE
  ↓
Frontend detecta cambio
  ↓
POST /auth/login/by-verification
  ↓
Backend valida y genera JWT
  ↓
Frontend guarda token → Redirige a /dashboard
```

## 📡 API Endpoints

### Usuarios

- `POST /users` - Registrar nuevo usuario
  ```json
  {
    "first_name": "Juan",
    "last_name": "Pérez",
    "email": "juan@example.com",
    "password": "password123"
  }
  ```

- `POST /users/link-wallet` - Conectar wallet (Requiere JWT)
  - Headers: `Authorization: Bearer <token>`
  - Retorna: `{ invitationId, invitationContent }`

### Verificaciones

- `POST /verifications/login` - Crear verificación de login
  - Retorna: `{ presentationId, presentationContent }`

### Autenticación

- `POST /auth/login/by-verification` - Completar login con verificación
  ```json
  {
    "presentation_id": "uuid",
    "user_id": "uuid"
  }
  ```
  - Retorna: `{ token }`

### Webhooks

- `POST /webhooks` - Recibir notificaciones de Sovra ID
  - Eventos: `credential-issued`, `verifiable-presentation-finished`

## 📁 Estructura del Proyecto

```
nextjs-nestjs/
├── backend/
│   ├── src/
│   │   ├── auth/              # Autenticación JWT
│   │   ├── credentials/       # Gestión de credenciales
│   │   ├── users/             # Gestión de usuarios
│   │   ├── verifications/     # Gestión de verificaciones
│   │   ├── webhooks/          # Manejo de webhooks
│   │   ├── sovra/             # Integración con Sovra ID
│   │   └── app.module.ts
│   ├── .env
│   └── package.json
│
├── frontend/
│   ├── src/
│   │   ├── app/
│   │   │   ├── register/      # Página de registro
│   │   │   ├── login/         # Página de login
│   │   │   ├── link-wallet/   # Página de conexión wallet
│   │   │   ├── dashboard/     # Dashboard del usuario
│   │   │   └── layout.tsx
│   │   ├── components/        # Componentes UI (shadcn)
│   │   └── lib/
│   │       ├── api.ts         # Cliente API
│   │       └── supabase.ts    # Cliente Supabase
│   ├── .env.local
│   └── package.json
│
└── README.md
```

## 🗄️ Base de Datos

### Tablas

- **users**: Información de usuarios
  - `id`, `email`, `password`, `first_name`, `last_name`, `created_at`, `updated_at`

- **credentials**: Credenciales verificables vinculadas a usuarios
  - `id`, `invitation_id`, `credential_id`, `holder_did`, `created_at`, `updated_at`

- **verifications**: Verificaciones para login
  - `id`, `presentation_id`, `verified`, `holder_did`, `user_id`, `expires_at`, `used_at`, `created_at`, `updated_at`

### Relaciones

- Un usuario puede tener múltiples credenciales (`users` 1:N `credentials`)
- Las verificaciones están vinculadas a usuarios y credenciales

## 🔒 Seguridad

- Las contraseñas se hashean con bcrypt
- Los tokens JWT se usan para autenticación
- Las credenciales se almacenan en una tabla separada (no en `users`)
- Supabase usa Row Level Security (RLS) para proteger datos
- El `anon key` de Supabase es seguro para uso público (con RLS configurado)

## 🛠️ Tecnologías

### Backend
- **NestJS** - Framework Node.js
- **TypeORM** - ORM para PostgreSQL
- **JWT** - Autenticación
- **Axios** - Cliente HTTP
- **bcrypt** - Hash de contraseñas

### Frontend
- **Next.js 16** - Framework React
- **TypeScript** - Tipado estático
- **Tailwind CSS** - Estilos
- **shadcn/ui** - Componentes UI
- **Supabase** - Eventos en tiempo real
- **Axios** - Cliente HTTP
- **qrcode.react** - Generación de QR
- **jwt-decode** - Decodificación de JWT

## 📝 Notas

- El backend usa `synchronize: true` en desarrollo. En producción, usa migraciones.
- Las variables de entorno con `NEXT_PUBLIC_` son públicas en el frontend.
- El `anon key` de Supabase está diseñado para ser público (con RLS).
- Asegúrate de configurar RLS en Supabase para proteger tus datos.
- Para desarrollo local, usa ngrok para exponer el webhook.

## 🐛 Troubleshooting

### Error de hidratación en Next.js
- Asegúrate de usar `suppressHydrationWarning` en el `<body>` del layout
- No accedas a `localStorage` durante el render inicial

### Supabase no detecta cambios
- Verifica que la replicación esté habilitada para las tablas
- Confirma que las variables de entorno estén correctas
- Revisa la consola del navegador para errores

### Webhooks no funcionan
- Verifica que la URL del webhook esté configurada en Sovra ID
- Asegúrate de que el backend sea accesible públicamente (usa ngrok en desarrollo)
- Revisa los logs del backend para ver si llegan los webhooks

### Error de CORS
- El backend tiene CORS habilitado por defecto
- Si tienes problemas, verifica la configuración en `main.ts`

## 📄 Licencia

MIT

