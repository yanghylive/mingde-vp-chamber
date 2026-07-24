#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXPECTED_CRMEB_SHA="7dcddffff73ec542d689f159724296351f29ea9a"

fail() {
  printf 'ERROR: %s\n' "$1" >&2
  exit 1
}

required_files=(
  "index.html"
  "PROJECT_MANIFEST.json"
  "docs/index.html"
  "docs/PRD-v2.0.html"
  "docs/CRMEB-二次开发说明书-v1.0.html"
  "docs/CRMEB-API资料索引.html"
  "docs/开发目录与工作流.html"
  "docs/开工基线与首轮任务.html"
  "docs/本地开发环境基线.html"
  "docs/Codex-Agent团队全量开发计划-v1.0.html"
  "docs/开发任务板.html"
  "docs/ADR-索引.html"
  "docs/CRMEB-支付退款事件核验.html"
  "docs/G1-会员交易开发基线.html"
  "docs/G1-会员初始化开发基线.html"
  "backend/custom/README.html"
  "backend/custom/app/chamber/ChamberExceptionHandle.php"
  "backend/custom/app/chamber/provider.php"
  "backend/custom/app/chamber/route/route.php"
  "backend/custom/app/chamber/config/consent.php"
  "backend/custom/app/chamber/controller/MemberBootstrapController.php"
  "backend/custom/app/chamber/exceptions/MemberTransactionException.php"
  "backend/custom/app/chamber/identity/AuthenticatedUserContext.php"
  "backend/custom/app/chamber/identity/BearerTokenExtractor.php"
  "backend/custom/app/chamber/membership/BootstrapIdempotency.php"
  "backend/custom/app/chamber/membership/ConsentDocument.php"
  "backend/custom/app/chamber/membership/MemberBootstrapRequest.php"
  "backend/custom/app/chamber/membership/MemberContext.php"
  "backend/custom/app/chamber/middleware/ChamberCorsMiddleware.php"
  "backend/custom/app/chamber/middleware/CrmebAuthTokenMiddleware.php"
  "backend/custom/app/chamber/middleware/TenantContextMiddleware.php"
  "backend/custom/app/chamber/middleware/RequestTraceMiddleware.php"
  "backend/custom/app/chamber/services/ConsentDocumentRegistry.php"
  "backend/custom/app/chamber/services/MemberBootstrapService.php"
  "backend/custom/app/chamber/tests/run.php"
  "backend/custom/app/chamber/tests/auth_context_run.php"
  "backend/custom/app/chamber/tests/bootstrap_domain_run.php"
  "backend/custom/app/chamber/tests/member_bootstrap_fixture.php"
  "backend/custom/app/chamber/tests/commerce_run.php"
  "backend/custom/app/chamber/tests/commerce_db_run.php"
  "backend/custom/app/chamber/commerce/CommerceEvent.php"
  "backend/custom/app/chamber/commerce/CommerceEventReceipt.php"
  "backend/custom/app/chamber/commerce/CommerceEventType.php"
  "backend/custom/app/chamber/commerce/Money.php"
  "backend/custom/app/chamber/commerce/RefundLifecycle.php"
  "backend/custom/app/chamber/membership/MemberTier.php"
  "backend/custom/app/chamber/membership/GraduateVerificationState.php"
  "backend/custom/app/chamber/membership/MembershipTermState.php"
  "backend/custom/app/chamber/membership/OrderContextState.php"
  "backend/custom/app/chamber/tests/membership_run.php"
  "backend/custom/app/chamber/contracts/CommerceEventStoreInterface.php"
  "backend/custom/app/chamber/exceptions/CommerceEventConflictException.php"
  "backend/custom/app/chamber/services/CommerceEventRecorder.php"
  "backend/custom/app/chamber/services/InMemoryCommerceEventStore.php"
  "backend/custom/app/chamber/services/ThinkDbCommerceEventStore.php"
  "backend/custom/commerce/README.html"
  "backend/custom/commerce/audit_crmeb_v6.rb"
  "backend/custom/database/README.html"
  "backend/custom/database/migrations/202607210001_create_chamber_core.up.sql"
  "backend/custom/database/migrations/202607210001_create_chamber_core.verify.sql"
  "backend/custom/database/migrations/202607210001_create_chamber_core.down.sql"
  "backend/custom/database/migrations/202607210002_create_commerce_event_baseline.up.sql"
  "backend/custom/database/migrations/202607210002_create_commerce_event_baseline.verify.sql"
  "backend/custom/database/migrations/202607210002_create_commerce_event_baseline.down.sql"
  "backend/custom/database/migrations/202607210003_create_membership_commerce.up.sql"
  "backend/custom/database/migrations/202607210003_create_membership_commerce.verify.sql"
  "backend/custom/database/migrations/202607210003_create_membership_commerce.down.sql"
  "backend/custom/database/migrations/202607210004_harden_member_verification_projection.up.sql"
  "backend/custom/database/migrations/202607210004_harden_member_verification_projection.verify.sql"
  "backend/custom/database/migrations/202607210004_harden_member_verification_projection.down.sql"
  "backend/custom/database/seeds/202607210001_local_ci_baseline.sql"
  "backend/custom/database/seeds/202607210001_local_ci_baseline.verify.sql"
  "backend/custom/openapi/README.html"
  "backend/custom/openapi/chamber-openapi.yaml"
  "backend/custom/openapi/validate.rb"
  "frontend/custom/README.html"
  "frontend/custom/shared/README.html"
  "frontend/custom/shared/tenant-brand.js"
  "frontend/custom/shared/vue2-tenant-brand.js"
  "frontend/custom/shared/tests/tenant-brand.test.js"
  "ai-service/README.html"
  "deployment/README.html"
  "deployment/local/README.html"
  "deployment/local/docker-compose.crmeb.yml"
  "deployment/local/nginx-vhost.conf"
  "scripts/check-local-env.sh"
  "scripts/prepare-local-crmeb-runtime.sh"
  "scripts/manage-local-database.sh"
  "scripts/prepare-local-frontend.sh"
  "scripts/check-g0-baseline.sh"
  "scripts/check-g1-membership-baseline.sh"
  "scripts/check-g1-member-bootstrap.sh"
)

required_executables=(
  "scripts/check-local-env.sh"
  "scripts/check-project.sh"
  "scripts/prepare-local-crmeb-runtime.sh"
  "scripts/manage-local-database.sh"
  "scripts/prepare-local-frontend.sh"
  "scripts/check-g0-baseline.sh"
  "scripts/check-g1-membership-baseline.sh"
  "scripts/check-g1-member-bootstrap.sh"
)

for file in "${required_files[@]}"; do
  [[ -s "$ROOT/$file" ]] || fail "missing or empty required file: $file"
done

for file in "${required_executables[@]}"; do
  [[ -x "$ROOT/$file" ]] || fail "required script is not executable: $file"
done

[[ -f "$ROOT/.gitmodules" ]] || fail "missing .gitmodules"
grep -Fq 'path = backend/crmeb' "$ROOT/.gitmodules" || fail "CRMEB submodule path is not registered"
[[ -d "$ROOT/backend/crmeb/.git" || -f "$ROOT/backend/crmeb/.git" ]] || fail "CRMEB submodule is not initialized"

actual_sha="$(git -C "$ROOT/backend/crmeb" rev-parse HEAD)"
[[ "$actual_sha" == "$EXPECTED_CRMEB_SHA" ]] || fail "CRMEB SHA mismatch: expected $EXPECTED_CRMEB_SHA, got $actual_sha"

submodule_changes="$(git -C "$ROOT/backend/crmeb" status --porcelain)"
[[ -z "$submodule_changes" ]] || fail "CRMEB submodule has uncommitted changes"

for file in "${required_files[@]}"; do
  [[ "$file" == *.html ]] || continue
  grep -Fqi '<!DOCTYPE html>' "$ROOT/$file" || fail "HTML doctype missing: $file"
  grep -Fqi '</html>' "$ROOT/$file" || fail "closing html tag missing: $file"
done

printf 'Project baseline OK\n'
printf 'CRMEB: %s\n' "$actual_sha"
printf 'Required project artifacts: %s\n' "${#required_files[@]}"
