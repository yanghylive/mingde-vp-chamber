#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXPECTED_CRMEB_SHA="7dcddffff73ec542d689f159724296351f29ea9a"

fail() {
  printf 'ERROR: %s\n' "$1" >&2
  exit 1
}

for command in git grep ruby; do
  command -v "$command" >/dev/null 2>&1 || fail "required command not found: $command"
done

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
  "docs/PRD-开发进度总结-2026-07-26.html"
  "docs/PRD-开发进度总结-2026-07-28.html"
  "docs/PRD-开发进度总结-2026-07-25.html"
  "docs/PRD-开发进度总结-2026-07-24.html"
  "docs/ADR-索引.html"
  "docs/CRMEB-支付退款事件核验.html"
  "docs/G1-会员交易开发基线.html"
  "docs/G1-会员初始化开发基线.html"
  "docs/G1-个人资料与毕业认证开发基线.html"
  "docs/G1-会籍计划与下单开发基线.html"
  "docs/G1-会籍支付与权益开发基线.html"
  "docs/G2-活动核心开发基线.html"
  "backend/custom/README.html"
  "backend/custom/app/chamber/ChamberExceptionHandle.php"
  "backend/custom/app/chamber/provider.php"
  "backend/custom/app/api/provider.php"
  "backend/custom/app/provider.php"
  "backend/custom/app/chamber/route/route.php"
  "backend/custom/app/chamber/config/consent.php"
  "backend/custom/app/chamber/controller/MemberBootstrapController.php"
  "backend/custom/app/chamber/controller/MemberProfileController.php"
  "backend/custom/app/chamber/controller/MemberAssetController.php"
  "backend/custom/app/chamber/controller/MemberAssetAdminController.php"
  "backend/custom/app/chamber/controller/GraduateVerificationController.php"
  "backend/custom/app/chamber/controller/GraduateVerificationAdminController.php"
  "backend/custom/app/chamber/controller/GraduateVerificationReviewController.php"
  "backend/custom/app/chamber/controller/MembershipPlanController.php"
  "backend/custom/app/chamber/controller/MembershipCheckoutController.php"
  "backend/custom/app/chamber/controller/MembershipSummaryController.php"
  "backend/custom/app/chamber/activity/EventCheckinRequest.php"
  "backend/custom/app/chamber/activity/EventCheckinToken.php"
  "backend/custom/app/chamber/activity/EventEligibility.php"
  "backend/custom/app/chamber/activity/EventListQuery.php"
  "backend/custom/app/chamber/activity/EventRegistrationListQuery.php"
  "backend/custom/app/chamber/activity/EventRegistrationRequest.php"
  "backend/custom/app/chamber/controller/EventAdminController.php"
  "backend/custom/app/chamber/controller/EventCheckinController.php"
  "backend/custom/app/chamber/controller/EventController.php"
  "backend/custom/app/chamber/controller/EventRegistrationController.php"
  "backend/custom/app/chamber/exceptions/MemberTransactionException.php"
  "backend/custom/app/chamber/identity/AuthenticatedUserContext.php"
  "backend/custom/app/chamber/identity/AuthenticatedAdminContext.php"
  "backend/custom/app/chamber/identity/BearerTokenExtractor.php"
  "backend/custom/app/chamber/membership/BootstrapIdempotency.php"
  "backend/custom/app/chamber/membership/EncryptedIdempotencyResult.php"
  "backend/custom/app/chamber/membership/MemberProfilePatch.php"
  "backend/custom/app/chamber/membership/MemberProfilePrivacy.php"
  "backend/custom/app/chamber/membership/MemberProfileSnapshot.php"
  "backend/custom/app/chamber/membership/ConsentDocument.php"
  "backend/custom/app/chamber/membership/MemberBootstrapRequest.php"
  "backend/custom/app/chamber/membership/MemberContext.php"
  "backend/custom/app/chamber/membership/MembershipCheckoutIdempotency.php"
  "backend/custom/app/chamber/membership/MembershipCheckoutRequest.php"
  "backend/custom/app/chamber/membership/MembershipPlanSnapshot.php"
  "backend/custom/app/chamber/membership/MembershipPurchasePolicy.php"
  "backend/custom/app/chamber/middleware/ChamberCorsMiddleware.php"
  "backend/custom/app/chamber/middleware/CrmebAuthTokenMiddleware.php"
  "backend/custom/app/chamber/middleware/CrmebAdminAuthTokenMiddleware.php"
  "backend/custom/app/chamber/middleware/TenantContextMiddleware.php"
  "backend/custom/app/chamber/middleware/RequestTraceMiddleware.php"
  "backend/custom/app/chamber/services/ConsentDocumentRegistry.php"
  "backend/custom/app/chamber/services/MemberBootstrapService.php"
  "backend/custom/app/chamber/services/MemberProfileService.php"
  "backend/custom/app/chamber/services/MemberAssetIdempotency.php"
  "backend/custom/app/chamber/services/MemberAssetService.php"
  "backend/custom/app/chamber/services/GraduateVerificationIdempotency.php"
  "backend/custom/app/chamber/services/GraduateVerificationService.php"
  "backend/custom/app/chamber/services/CrmebMembershipOrderGateway.php"
  "backend/custom/app/chamber/services/MembershipCheckoutService.php"
  "backend/custom/app/chamber/services/MembershipNativeOrderGuard.php"
  "backend/custom/app/chamber/services/GuardedStoreCartServices.php"
  "backend/custom/app/chamber/services/GuardedStoreOrderCartInfoServices.php"
  "backend/custom/app/chamber/services/GuardedStoreOrderCreateServices.php"
  "backend/custom/app/chamber/services/GuardedStoreOrderRefundServices.php"
  "backend/custom/app/chamber/services/GuardedStoreOrderDeliveryServices.php"
  "backend/custom/app/chamber/services/GuardedStoreOrderSuccessServices.php"
  "backend/custom/app/chamber/services/GuardedOutStoreOrderServices.php"
  "backend/custom/app/chamber/services/GuardedStoreOrderServices.php"
  "backend/custom/app/chamber/services/GuardedStoreOrderTakeServices.php"
  "backend/custom/app/chamber/services/MembershipPaymentCompletionService.php"
  "backend/custom/app/chamber/services/MembershipEntitlementService.php"
  "backend/custom/app/chamber/services/EventAdminService.php"
  "backend/custom/app/chamber/services/EventCheckinService.php"
  "backend/custom/app/chamber/services/EventIdempotency.php"
  "backend/custom/app/chamber/services/EventRegistrationService.php"
  "backend/custom/app/chamber/services/EventRewardService.php"
  "backend/custom/app/chamber/services/EventService.php"
  "backend/custom/app/chamber/jobs/MembershipOrderContextRepairJob.php"
  "backend/custom/app/chamber/assets/LocalPrivateAssetStorage.php"
  "backend/custom/app/chamber/assets/MemberAssetContent.php"
  "backend/custom/app/chamber/assets/MemberAssetContentResponder.php"
  "backend/custom/app/chamber/assets/MemberAssetPurpose.php"
  "backend/custom/app/chamber/assets/MemberAssetRecord.php"
  "backend/custom/app/chamber/assets/MemberAssetUpload.php"
  "backend/custom/app/chamber/assets/PrivateMemberAssetResponse.php"
  "backend/custom/app/chamber/assets/StoredMemberAsset.php"
  "backend/custom/app/chamber/verification/GraduateVerificationAdminQuery.php"
  "backend/custom/app/chamber/verification/GraduateVerificationApplication.php"
  "backend/custom/app/chamber/verification/GraduateVerificationReviewRequest.php"
  "backend/custom/app/chamber/verification/GraduateVerificationSubmission.php"
  "backend/custom/app/chamber/verification/GraduateVerificationValidationException.php"
  "backend/custom/app/chamber/tests/run.php"
  "backend/custom/app/chamber/tests/auth_context_run.php"
  "backend/custom/app/chamber/tests/bootstrap_domain_run.php"
  "backend/custom/app/chamber/tests/member_bootstrap_fixture.php"
  "backend/custom/app/chamber/tests/member_profile_run.php"
  "backend/custom/app/chamber/tests/member_asset_run.php"
  "backend/custom/app/chamber/tests/member_asset_db_run.php"
  "backend/custom/app/chamber/tests/graduate_verification_run.php"
  "backend/custom/app/chamber/tests/graduate_verification_db_run.php"
  "backend/custom/app/chamber/tests/membership_checkout_run.php"
  "backend/custom/app/chamber/tests/membership_order_gateway_run.php"
  "backend/custom/app/chamber/tests/membership_checkout_db_run.php"
  "backend/custom/app/chamber/tests/membership_checkout_fixture.php"
  "backend/custom/app/chamber/tests/commerce_run.php"
  "backend/custom/app/chamber/tests/commerce_db_run.php"
  "backend/custom/app/chamber/tests/event_run.php"
  "backend/custom/app/chamber/tests/event_db_run.php"
  "backend/custom/app/chamber/tests/event_registration_db_run.php"
  "backend/custom/app/chamber/tests/event_registration_concurrency_run.php"
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
  "backend/custom/app/chamber/contracts/MembershipOrderGatewayInterface.php"
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
  "backend/custom/database/migrations/202607250001_register_chamber_admin_menu.up.sql"
  "backend/custom/database/migrations/202607250001_register_chamber_admin_menu.verify.sql"
  "backend/custom/database/migrations/202607250001_register_chamber_admin_menu.down.sql"
  "backend/custom/database/migrations/202607250002_normalize_member_profile_json.up.sql"
  "backend/custom/database/migrations/202607250002_normalize_member_profile_json.verify.sql"
  "backend/custom/database/migrations/202607250002_normalize_member_profile_json.down.sql"
  "backend/custom/database/migrations/202607250003_create_member_asset.up.sql"
  "backend/custom/database/migrations/202607250003_create_member_asset.verify.sql"
  "backend/custom/database/migrations/202607250003_create_member_asset.down.sql"
  "backend/custom/database/migrations/202607250004_link_order_context_idempotency.up.sql"
  "backend/custom/database/migrations/202607250004_link_order_context_idempotency.verify.sql"
  "backend/custom/database/migrations/202607250004_link_order_context_idempotency.down.sql"
  "backend/custom/database/migrations/202607250005_register_membership_repair_timer.up.sql"
  "backend/custom/database/migrations/202607250005_register_membership_repair_timer.verify.sql"
  "backend/custom/database/migrations/202607250005_register_membership_repair_timer.down.sql"
  "backend/custom/database/migrations/202607260001_create_activity_runtime.up.sql"
  "backend/custom/database/migrations/202607260001_create_activity_runtime.verify.sql"
  "backend/custom/database/migrations/202607260001_create_activity_runtime.down.sql"
  "backend/custom/database/migrations/202607280001_add_event_checkin_rewards.up.sql"
  "backend/custom/database/migrations/202607280001_add_event_checkin_rewards.verify.sql"
  "backend/custom/database/migrations/202607280001_add_event_checkin_rewards.down.sql"
  "backend/custom/database/migrations/202607280002_create_event_payment_runtime.up.sql"
  "backend/custom/database/migrations/202607280002_create_event_payment_runtime.verify.sql"
  "backend/custom/database/migrations/202607280002_create_event_payment_runtime.down.sql"
  "backend/custom/database/migrations/202607280003_register_event_reservation_timer.up.sql"
  "backend/custom/database/migrations/202607280003_register_event_reservation_timer.verify.sql"
  "backend/custom/database/migrations/202607280003_register_event_reservation_timer.down.sql"
  "backend/custom/database/seeds/202607210001_local_ci_baseline.sql"
  "backend/custom/database/seeds/202607210001_local_ci_baseline.verify.sql"
  "backend/custom/database/seeds/202607250002_local_membership_checkout.sql"
  "backend/custom/database/seeds/202607250002_local_membership_checkout.verify.sql"
  "backend/custom/openapi/README.html"
  "backend/custom/openapi/chamber-openapi.yaml"
  "backend/custom/openapi/validate.rb"
  "frontend/custom/README.html"
  "frontend/custom/shared/README.html"
  "frontend/custom/shared/tenant-brand.js"
  "frontend/custom/shared/vue2-tenant-brand.js"
  "frontend/custom/shared/member-ui.js"
  "frontend/custom/shared/tests/tenant-brand.test.js"
  "frontend/custom/shared/tests/member-ui.test.js"
  "frontend/custom/admin/src/api/chamber/graduateVerification.js"
  "frontend/custom/admin/src/pages/chamber/graduateVerification/index.vue"
  "frontend/custom/admin/src/router/modules/chamber.js"
  "frontend/custom/uniapp/api/chamber/member.js"
  "frontend/custom/uniapp/chamber-pages.json"
  "frontend/custom/uniapp/components/chamberMemberEntry/index.vue"
  "frontend/custom/uniapp/overlays/apply-user-center-entry.js"
  "frontend/custom/uniapp/pages/chamber/profile/index.vue"
  "frontend/custom/uniapp/pages/chamber/graduate_verification/index.vue"
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
  "scripts/check-g1-profile-verification.sh"
  "scripts/check-g1-membership-checkout.sh"
  "scripts/check-g1-membership-entitlement.sh"
  "scripts/check-g2-activity-core.sh"
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
  "scripts/check-g1-profile-verification.sh"
  "scripts/check-g1-membership-checkout.sh"
  "scripts/check-g1-membership-entitlement.sh"
  "scripts/check-g2-activity-core.sh"
)

for file in "${required_files[@]}"; do
  [[ -s "$ROOT/$file" ]] || fail "missing or empty required file: $file"
done

for file in "${required_executables[@]}"; do
  [[ -x "$ROOT/$file" ]] || fail "required script is not executable: $file"
done

ruby -rjson - "$ROOT/PROJECT_MANIFEST.json" <<'RUBY'
manifest = JSON.parse(File.read(ARGV.fetch(0)))
project = manifest.fetch('project')
documents = manifest.fetch('documents')
baseline = manifest.fetch('g1_membership_baseline')

expected_documents = {
  'g1_profile_verification_baseline' => 'docs/G1-个人资料与毕业认证开发基线.html',
  'g1_membership_checkout_baseline' => 'docs/G1-会籍计划与下单开发基线.html',
  'g1_membership_entitlement_baseline' => 'docs/G1-会籍支付与权益开发基线.html'
}
expected_baseline = {
  'completed_on' => '2026-07-26',
  'database_tables_total' => 180,
  'chamber_domain_tables' => 22,
  'migration_registry_tables' => 1,
  'chamber_tables_including_registry' => 23,
  'admin_menu_structural_checks' => 4,
  'profile_json_structural_checks' => 5,
  'member_asset_structural_checks' => 28,
  'order_context_idempotency_structural_checks' => 3,
  'repair_timer_structural_checks' => 3,
  'profile_verification_structural_checks' => 37,
  'g1_migration_structural_checks' => 272,
  'migration_structural_checks_total' => 437,
  'membership_checkout_domain_tests' => 15,
  'membership_order_gateway_tests' => 11,
  'membership_checkout_database_assertions' => 85,
  'membership_entitlement_completed_on' => '2026-07-26',
  'membership_payment_replay_attempts' => 10,
  'membership_concurrent_renewals' => 2,
  'membership_refund_replay_attempts' => 1,
  'member_profile_domain_tests' => 16,
  'member_asset_domain_tests' => 10,
  'member_asset_database_assertions' => 44,
  'graduate_verification_domain_tests' => 12,
  'graduate_verification_database_assertions' => 119,
  'member_ui_tests' => 18,
  'tenant_brand_tests' => 6,
  'openapi_version' => '0.5.0',
  'openapi_paths' => 14,
  'openapi_operations_total' => 16,
  'openapi_operations_implemented' => 16,
  'openapi_operations_planned' => 0,
  'openapi_schemas' => 85,
  'profile_verification_gate' => 'scripts/check-g1-profile-verification.sh',
  'membership_checkout_gate' => 'scripts/check-g1-membership-checkout.sh',
  'membership_entitlement_gate' => 'scripts/check-g1-membership-entitlement.sh'
}

errors = []
errors << "project.status=#{project['status'].inspect}" unless project['status'].is_a?(String) && !project['status'].empty?
expected_documents.each do |key, value|
  errors << "documents.#{key}=#{documents[key].inspect}" unless documents[key] == value
end
expected_baseline.each do |key, value|
  errors << "g1_membership_baseline.#{key}=#{baseline[key].inspect}" unless baseline[key] == value
end
errors << 'Chamber table arithmetic differs' unless baseline['chamber_domain_tables'] + baseline['migration_registry_tables'] == baseline['chamber_tables_including_registry']
errors << 'migration check arithmetic differs' unless 165 + baseline['g1_migration_structural_checks'] == baseline['migration_structural_checks_total']
errors << 'OpenAPI operation arithmetic differs' unless baseline['openapi_operations_implemented'] + baseline['openapi_operations_planned'] == baseline['openapi_operations_total']
if project['status'].start_with?('g2-')
  g2 = manifest.fetch('g2_activity_baseline')
  errors << 'g2_activity_baseline.scope must remain backend_core' unless g2['scope'] == 'backend_core'
  errors << 'G2 Chamber table arithmetic differs' unless g2['chamber_domain_tables'] + g2['migration_registry_tables'] == g2['chamber_tables_including_registry']
  errors << 'G2 database table arithmetic differs' unless g2['crm_tables'] + g2['chamber_tables_including_registry'] == g2['database_tables_total']
  errors << 'G2 OpenAPI operation arithmetic differs' unless g2['openapi_operations_implemented'] + g2['openapi_operations_planned'] == g2['openapi_operations_total']
  errors << 'G2 database assertion arithmetic differs' unless g2['activity_database_assertions'] + g2['registration_database_assertions'] + g2['registration_concurrency_assertions'] == g2['database_assertions_total']
  errors << 'g2_activity_baseline.gate differs' unless g2['gate'] == 'scripts/check-g2-activity-core.sh'
end
abort "PROJECT_MANIFEST baseline drift: #{errors.join('; ')}" unless errors.empty?
RUBY

while IFS= read -r document; do
  [[ -s "$ROOT/$document" ]] || fail "PROJECT_MANIFEST document is missing or empty: $document"
done < <(ruby -rjson -e '
  manifest = JSON.parse(File.read(ARGV.fetch(0)))
  manifest.fetch("documents").each_value { |path| puts path }
' "$ROOT/PROJECT_MANIFEST.json")

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
