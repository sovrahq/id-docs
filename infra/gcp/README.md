# Google Cloud — Cloud Run

El sitio es estático: el `Dockerfile` de la raíz lo compila con Astro y lo sirve con
nginx (`nginx.conf`) en un contenedor de Cloud Run.

| | |
|---|---|
| Proyecto | `sovra-docs` (organización sovra.io) |
| Región | `us-central1` |
| Servicio de Cloud Run | `sovra-id-docs` (público, escala a cero, máx. 3 instancias) — https://sovra-id-docs-1044960690985.us-central1.run.app |
| Imágenes | Artifact Registry `us-central1-docker.pkg.dev/sovra-docs/docs/sovra-id-docs:<sha>` |
| Deploy | `.github/workflows/deploy.yml`, en cada push a `main` que toque el sitio |

## 1. Configuración de un solo uso

El proyecto `sovra-docs` ya existe y aloja también el servicio `sovra-docs` (otro sitio: no lo toques). El repositorio `docs` de Artifact Registry ya está creado. Con una cuenta con permisos de owner en él:

```sh
bash infra/gcp/setup.sh
```

El script habilita las APIs, crea el repositorio de Artifact
Registry (con limpieza de imágenes viejas), dos cuentas de servicio y la
federación de identidad para que **solo** GitHub Actions de `sovrahq/id-docs`
pueda desplegar, sin claves guardadas en GitHub:

| Cuenta de servicio | Permisos |
|---|---|
| `github-deployer` | `run.admin`, `artifactregistry.writer`, y actuar como `docs-runtime` |
| `docs-runtime` | Ninguno: es la identidad del servicio, que solo sirve archivos |

Al final imprime los `gh variable set …` para cargar las variables del repo:
`GCP_PROJECT_ID`, `GCP_REGION`, `GCP_WIF_PROVIDER`, `GCP_DEPLOYER_SA`,
`GCP_RUNTIME_SA` y `SITE_URL`.

## 2. Desplegar

Push a `main`, o **Actions → Deploy docs → Run workflow**. El job construye la
imagen, la sube, despliega y hace un smoke test de `/`, `/conceptos/` y `/api/`.

## Desplegar a mano (sin GitHub)

Así se hizo el primer deploy. La imagen se construye en tu máquina para `linux/amd64`
(la arquitectura de Cloud Run, aunque tengas una Mac con ARM) y se sube con tu cuenta:

```sh
gcloud auth configure-docker us-central1-docker.pkg.dev   # una sola vez
TAG=$(date +%Y%m%d-%H%M)
IMAGE=us-central1-docker.pkg.dev/sovra-docs/docs/sovra-id-docs:$TAG
docker buildx build --platform linux/amd64 \
  --build-arg SITE_URL=https://sovra-id-docs-1044960690985.us-central1.run.app \
  -t $IMAGE --push .
gcloud run deploy sovra-id-docs --project=sovra-docs --region=us-central1 --image=$IMAGE \
  --allow-unauthenticated --port=8080 --cpu=1 --memory=256Mi --min-instances=0 --max-instances=3
```

`cloudbuild.yaml` construye la imagen en Cloud Build en vez de en tu máquina, pero hoy
la cuenta de servicio por defecto del proyecto no tiene acceso al bucket de Cloud Build.
Para usarlo, dale a esa cuenta `roles/storage.objectViewer`, `roles/artifactregistry.writer`
y `roles/logging.logWriter`, o usá `--service-account` con una cuenta dedicada.

## Probar la imagen en local

```sh
npm run docker:build
npm run docker:run        # http://localhost:8080
```

## Si algo falla

- **`--allow-unauthenticated` rechazado** (`iam.allowedPolicyMemberDomains`): la
  organización no permite `allUsers`. Hace falta una excepción de esa política
  para el proyecto `sovra-docs`, o poner el sitio detrás de un balanceador con IAP.
- **`Permission 'iam.serviceAccounts.getAccessToken' denied`** en el paso de auth:
  revisá que `GCP_WIF_PROVIDER` y `GCP_DEPLOYER_SA` sean los que imprimió el script y
  que el repo sea `sovrahq/id-docs`.
- **Dominio propio**: mapealo al servicio (`gcloud beta run domain-mappings create`)
  o con un balanceador, y actualizá la variable `SITE_URL`.
