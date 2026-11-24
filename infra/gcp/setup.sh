#!/usr/bin/env bash
# Prepara Google Cloud para desplegar el sitio de documentación en Cloud Run.
# Se corre UNA vez, a mano, con una cuenta que pueda crear proyectos en la
# organización y vincular facturación:
#
#   bash infra/gcp/setup.sh
#
# Si el proyecto no existe, lo crea (hace falta BILLING_ACCOUNT=XXXXXX-XXXXXX-XXXXXX).
#
# Es idempotente: si un recurso ya existe, lo deja como está.
set -euo pipefail

PROJECT_ID="${PROJECT_ID:-sovra-docs}"
REGION="${REGION:-us-central1}"
ORG_ID="${ORG_ID:-94676565138}"            # sovra.io
GITHUB_REPO="${GITHUB_REPO:-sovrahq/id-docs}"
SERVICE="${SERVICE:-sovra-id-docs}"         # servicio de Cloud Run
AR_REPO="${AR_REPO:-docs}"                  # repositorio de Artifact Registry
DEPLOYER_SA="github-deployer"               # la usa GitHub Actions para desplegar
RUNTIME_SA="docs-runtime"                   # identidad del servicio (sin permisos)
POOL="github"
PROVIDER="github"

step() { printf '\n==> %s\n' "$*"; }
exists() { "$@" >/dev/null 2>&1; }

step "Proyecto $PROJECT_ID"
if ! exists gcloud projects describe "$PROJECT_ID"; then
  if [[ -z "${BILLING_ACCOUNT:-}" ]]; then
    echo "El proyecto no existe: pasá BILLING_ACCOUNT para crearlo (gcloud billing accounts list)." >&2
    exit 1
  fi
  gcloud projects create "$PROJECT_ID" --organization="$ORG_ID" --name="Sovra Docs"
  gcloud billing projects link "$PROJECT_ID" --billing-account="$BILLING_ACCOUNT"
fi
PROJECT_NUMBER="$(gcloud projects describe "$PROJECT_ID" --format='value(projectNumber)')"

step "APIs"
gcloud services enable --project="$PROJECT_ID" \
  run.googleapis.com \
  artifactregistry.googleapis.com \
  iam.googleapis.com \
  iamcredentials.googleapis.com \
  sts.googleapis.com \
  cloudresourcemanager.googleapis.com \
  cloudbuild.googleapis.com

step "Artifact Registry ($AR_REPO en $REGION)"
if ! exists gcloud artifacts repositories describe "$AR_REPO" --project="$PROJECT_ID" --location="$REGION"; then
  gcloud artifacts repositories create "$AR_REPO" --project="$PROJECT_ID" \
    --location="$REGION" --repository-format=docker \
    --description="Imágenes del sitio de documentación"
fi
# Conserva las últimas 10 imágenes y borra el resto.
CLEANUP_POLICY="$(mktemp)"
trap 'rm -f "$CLEANUP_POLICY"' EXIT
cat > "$CLEANUP_POLICY" <<'JSON'
[
  {"name": "conservar-ultimas-10", "action": {"type": "Keep"}, "mostRecentVersions": {"keepCount": 10}},
  {"name": "borrar-viejas", "action": {"type": "Delete"}, "condition": {"olderThan": "30d"}}
]
JSON
gcloud artifacts repositories set-cleanup-policies "$AR_REPO" --project="$PROJECT_ID" \
  --location="$REGION" --policy="$CLEANUP_POLICY" --no-dry-run

step "Cuentas de servicio"
for sa in "$DEPLOYER_SA" "$RUNTIME_SA"; do
  if ! exists gcloud iam service-accounts describe "$sa@$PROJECT_ID.iam.gserviceaccount.com" --project="$PROJECT_ID"; then
    gcloud iam service-accounts create "$sa" --project="$PROJECT_ID"
  fi
done
DEPLOYER="$DEPLOYER_SA@$PROJECT_ID.iam.gserviceaccount.com"
RUNTIME="$RUNTIME_SA@$PROJECT_ID.iam.gserviceaccount.com"

# El deployer sube imágenes al repositorio $AR_REPO y despliega el servicio $SERVICE,
# y nada más: el proyecto aloja otros servicios (sovra-docs) que no debe poder tocar.
gcloud artifacts repositories add-iam-policy-binding "$AR_REPO" --project="$PROJECT_ID" \
  --location="$REGION" --member="serviceAccount:$DEPLOYER" \
  --role=roles/artifactregistry.writer >/dev/null
if exists gcloud run services describe "$SERVICE" --project="$PROJECT_ID" --region="$REGION"; then
  gcloud run services add-iam-policy-binding "$SERVICE" --project="$PROJECT_ID" \
    --region="$REGION" --member="serviceAccount:$DEPLOYER" --role=roles/run.admin >/dev/null
else
  echo "El servicio $SERVICE todavía no existe: hacé el primer deploy a mano (ver README)" >&2
  echo "y volvé a correr este script para darle run.admin al deployer sobre el servicio." >&2
fi
# Versiones anteriores del script daban estos roles sobre todo el proyecto: se sacan.
for role in roles/run.admin roles/artifactregistry.writer; do
  gcloud projects remove-iam-policy-binding "$PROJECT_ID" \
    --member="serviceAccount:$DEPLOYER" --role="$role" --condition=None >/dev/null 2>&1 || true
done
# Puede desplegar el servicio "como" la cuenta de runtime.
gcloud iam service-accounts add-iam-policy-binding "$RUNTIME" --project="$PROJECT_ID" \
  --member="serviceAccount:$DEPLOYER" --role=roles/iam.serviceAccountUser >/dev/null

step "Workload Identity Federation para $GITHUB_REPO"
if ! exists gcloud iam workload-identity-pools describe "$POOL" --project="$PROJECT_ID" --location=global; then
  gcloud iam workload-identity-pools create "$POOL" --project="$PROJECT_ID" \
    --location=global --display-name="GitHub Actions"
fi
# Solo los workflows de main de este repo: una rama cualquiera no puede desplegar.
ATTRIBUTE_MAPPING="google.subject=assertion.sub,attribute.repository=assertion.repository,attribute.ref=assertion.ref"
ATTRIBUTE_CONDITION="assertion.repository == '$GITHUB_REPO' && assertion.ref == 'refs/heads/main'"
if exists gcloud iam workload-identity-pools providers describe "$PROVIDER" \
    --project="$PROJECT_ID" --location=global --workload-identity-pool="$POOL"; then
  gcloud iam workload-identity-pools providers update-oidc "$PROVIDER" --project="$PROJECT_ID" \
    --location=global --workload-identity-pool="$POOL" \
    --attribute-mapping="$ATTRIBUTE_MAPPING" \
    --attribute-condition="$ATTRIBUTE_CONDITION"
else
  gcloud iam workload-identity-pools providers create-oidc "$PROVIDER" --project="$PROJECT_ID" \
    --location=global --workload-identity-pool="$POOL" \
    --display-name="GitHub" \
    --issuer-uri="https://token.actions.githubusercontent.com" \
    --attribute-mapping="$ATTRIBUTE_MAPPING" \
    --attribute-condition="$ATTRIBUTE_CONDITION"
fi
# Solo los workflows de este repo pueden usar la cuenta del deployer.
gcloud iam service-accounts add-iam-policy-binding "$DEPLOYER" --project="$PROJECT_ID" \
  --role=roles/iam.workloadIdentityUser \
  --member="principalSet://iam.googleapis.com/projects/$PROJECT_NUMBER/locations/global/workloadIdentityPools/$POOL/attribute.repository/$GITHUB_REPO" >/dev/null

WIF_PROVIDER="projects/$PROJECT_NUMBER/locations/global/workloadIdentityPools/$POOL/providers/$PROVIDER"
SITE_URL="https://$SERVICE-$PROJECT_NUMBER.$REGION.run.app"

cat <<EOF

Listo. Cargá estas variables en GitHub (Settings → Secrets and variables → Actions → Variables),
o con la CLI de GitHub:

  gh variable set GCP_PROJECT_ID   --repo $GITHUB_REPO --body "$PROJECT_ID"
  gh variable set GCP_REGION       --repo $GITHUB_REPO --body "$REGION"
  gh variable set GCP_WIF_PROVIDER --repo $GITHUB_REPO --body "$WIF_PROVIDER"
  gh variable set GCP_DEPLOYER_SA  --repo $GITHUB_REPO --body "$DEPLOYER"
  gh variable set GCP_RUNTIME_SA   --repo $GITHUB_REPO --body "$RUNTIME"
  gh variable set SITE_URL         --repo $GITHUB_REPO --body "$SITE_URL"

No son secretos: la federación de identidad reemplaza a las claves de cuenta de servicio.
SITE_URL es la URL que va a tener el servicio; cambiala cuando haya dominio propio.
EOF
