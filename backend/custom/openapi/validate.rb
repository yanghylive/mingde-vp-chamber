#!/usr/bin/env ruby
# frozen_string_literal: true

require 'psych'

spec_path = File.expand_path(ARGV[0] || 'chamber-openapi.yaml', __dir__)
html_path = File.expand_path('README.html', __dir__)
errors = []

unless File.file?(spec_path)
  warn "FAIL: OpenAPI file not found: #{spec_path}"
  exit 1
end

content = File.read(spec_path)

begin
  ast = Psych.parse_stream(content, spec_path)
rescue Psych::SyntaxError => e
  warn "FAIL: YAML syntax error: #{e.message}"
  exit 1
end

def scalar_key(node)
  node.is_a?(Psych::Nodes::Scalar) ? node.value : nil
end

def find_duplicate_keys(node, path = '$', duplicates = [])
  if node.is_a?(Psych::Nodes::Mapping)
    seen = {}
    node.children.each_slice(2) do |key_node, value_node|
      key = scalar_key(key_node)
      if key
        duplicates << "#{path}.#{key}" if seen[key]
        seen[key] = true
        find_duplicate_keys(value_node, "#{path}.#{key}", duplicates)
      else
        find_duplicate_keys(value_node, path, duplicates)
      end
    end
  elsif node.respond_to?(:children) && node.children
    node.children.each { |child| find_duplicate_keys(child, path, duplicates) }
  end
  duplicates
end

duplicates = find_duplicate_keys(ast)
errors << "duplicate YAML keys: #{duplicates.uniq.join(', ')}" unless duplicates.empty?

begin
  spec = Psych.safe_load(content, [], [], true, spec_path)
rescue Psych::Exception => e
  warn "FAIL: cannot load YAML: #{e.message}"
  exit 1
end

def dig_hash(root, *keys)
  keys.reduce(root) { |value, key| value.is_a?(Hash) ? value[key] : nil }
end

def each_node(value, path = '$', &block)
  yield value, path
  case value
  when Hash
    value.each { |key, child| each_node(child, "#{path}.#{key}", &block) }
  when Array
    value.each_with_index { |child, index| each_node(child, "#{path}[#{index}]", &block) }
  end
end

def resolve_pointer(root, reference)
  return nil unless reference.start_with?('#/')

  reference.delete_prefix('#/').split('/').reduce(root) do |value, token|
    key = token.gsub('~1', '/').gsub('~0', '~')
    value.is_a?(Hash) ? value[key] : nil
  end
end

def resolve_reference(root, node, seen_refs = {})
  return node unless node.is_a?(Hash) && node['$ref'].is_a?(String)

  reference = node['$ref']
  return nil unless reference.start_with?('#/')
  return nil if seen_refs[reference]

  resolved = resolve_pointer(root, reference)
  return nil unless resolved

  resolve_reference(root, resolved, seen_refs.merge(reference => true))
end

def collect_property_keys(root, node, path = '$', seen_refs = {})
  return [] unless node.is_a?(Hash) || node.is_a?(Array)

  if node.is_a?(Hash) && node['$ref'].is_a?(String) && node['$ref'].start_with?('#/')
    reference = node['$ref']
    return [] if seen_refs[reference]

    resolved = resolve_pointer(root, reference)
    return [] unless resolved

    return collect_property_keys(root, resolved, path, seen_refs.merge(reference => true))
  end

  if node.is_a?(Array)
    return node.each_with_index.flat_map do |child, index|
      collect_property_keys(root, child, "#{path}[#{index}]", seen_refs)
    end
  end

  found = []
  properties = node['properties']
  if properties.is_a?(Hash)
    properties.each do |name, child|
      found << ["#{path}.#{name}", name]
      found.concat(collect_property_keys(root, child, "#{path}.#{name}", seen_refs))
    end
  end
  node.each do |key, child|
    next if key == 'properties' || key == '$ref'

    found.concat(collect_property_keys(root, child, path, seen_refs))
  end
  found
end

unless spec.is_a?(Hash)
  warn 'FAIL: YAML root must be an object'
  exit 1
end

errors << 'openapi must equal 3.1.0' unless spec['openapi'] == '3.1.0'
errors << 'info.version must equal 0.5.0' unless dig_hash(spec, 'info', 'version') == '0.5.0'

expected_paths = [
  '/chamber/health',
  '/chamber/v1/bootstrap',
  '/chamber/v1/me/bootstrap',
  '/chamber/v1/me/profile',
  '/chamber/v1/me/assets',
  '/chamber/v1/me/assets/{asset_id}/content',
  '/chamber/v1/me/graduate-verifications',
  '/chamber/admin/v1/graduate-verifications',
  '/chamber/admin/v1/member-assets/{asset_id}/content',
  '/chamber/admin/v1/graduate-verifications/{application_id}',
  '/chamber/admin/v1/graduate-verifications/{application_id}/reviews',
  '/chamber/v1/membership/plans',
  '/chamber/v1/membership/checkouts',
  '/chamber/v1/me/membership',
  '/chamber/v1/events',
  '/chamber/v1/events/{event_id}',
  '/chamber/v1/events/{event_id}/registrations',
  '/chamber/v1/me/event-registrations',
  '/chamber/v1/me/event-registrations/{registration_id}',
  '/chamber/v1/events/{event_id}/checkins',
  '/chamber/admin/v1/events',
  '/chamber/admin/v1/events/{event_id}',
  '/chamber/admin/v1/events/{event_id}/publish',
  '/chamber/admin/v1/events/{event_id}/cancel',
  '/chamber/admin/v1/events/{event_id}/checkin-token',
  '/chamber/admin/v1/events/{event_id}/checkins/manual'
]
actual_paths = spec.fetch('paths', {}).keys.sort
errors << "paths must contain only #{expected_paths.join(', ')}" unless actual_paths == expected_paths.sort

expected_operations = [
  ['/chamber/health', 'get', 'getChamberHealth', 'implemented', '200', nil],
  ['/chamber/v1/bootstrap', 'get', 'getChamberBootstrap', 'implemented', '200', nil],
  ['/chamber/v1/me/bootstrap', 'post', 'bootstrapChamberMember', 'implemented', '200', 'CrmebBearerAuth'],
  ['/chamber/v1/me/profile', 'get', 'getChamberMemberProfile', 'implemented', '200', 'CrmebBearerAuth'],
  ['/chamber/v1/me/profile', 'patch', 'updateChamberMemberProfile', 'implemented', '200', 'CrmebBearerAuth'],
  ['/chamber/v1/me/assets', 'post', 'uploadChamberMemberAsset', 'implemented', '201', 'CrmebBearerAuth'],
  ['/chamber/v1/me/assets/{asset_id}/content', 'get', 'getChamberMemberAssetContent', 'implemented', '200', 'CrmebBearerAuth'],
  ['/chamber/v1/me/graduate-verifications', 'get', 'getGraduateVerification', 'implemented', '200', 'CrmebBearerAuth'],
  ['/chamber/v1/me/graduate-verifications', 'post', 'submitGraduateVerification', 'implemented', '201', 'CrmebBearerAuth'],
  ['/chamber/admin/v1/graduate-verifications', 'get', 'listGraduateVerificationsForAdmin', 'implemented', '200', 'CrmebAdminBearerAuth'],
  ['/chamber/admin/v1/member-assets/{asset_id}/content', 'get', 'getChamberMemberAssetContentForAdmin', 'implemented', '200', 'CrmebAdminBearerAuth'],
  ['/chamber/admin/v1/graduate-verifications/{application_id}', 'get', 'getGraduateVerificationForAdmin', 'implemented', '200', 'CrmebAdminBearerAuth'],
  ['/chamber/admin/v1/graduate-verifications/{application_id}/reviews', 'post', 'reviewGraduateVerification', 'implemented', '200', 'CrmebAdminBearerAuth'],
  ['/chamber/v1/membership/plans', 'get', 'listMembershipPlans', 'implemented', '200', 'CrmebBearerAuth'],
  ['/chamber/v1/membership/checkouts', 'post', 'createMembershipCheckout', 'implemented', '201', 'CrmebBearerAuth'],
  ['/chamber/v1/me/membership', 'get', 'getMembershipSummary', 'implemented', '200', 'CrmebBearerAuth'],
  ['/chamber/v1/events', 'get', 'listEvents', 'implemented', '200', 'CrmebBearerAuth'],
  ['/chamber/v1/events/{event_id}', 'get', 'showEvent', 'implemented', '200', 'CrmebBearerAuth'],
  ['/chamber/v1/events/{event_id}/registrations', 'post', 'createEventRegistration', 'planned', '201', 'CrmebBearerAuth'],
  ['/chamber/v1/me/event-registrations', 'get', 'listMyEventRegistrations', 'implemented', '200', 'CrmebBearerAuth'],
  ['/chamber/v1/me/event-registrations/{registration_id}', 'get', 'showMyEventRegistration', 'implemented', '200', 'CrmebBearerAuth'],
  ['/chamber/v1/events/{event_id}/checkins', 'post', 'createEventCheckin', 'implemented', '201', 'CrmebBearerAuth'],
  ['/chamber/admin/v1/events', 'post', 'createEventForAdmin', 'planned', '201', 'CrmebAdminBearerAuth', 'chamber.event.manage'],
  ['/chamber/admin/v1/events/{event_id}', 'patch', 'updateEventForAdmin', 'planned', '200', 'CrmebAdminBearerAuth', 'chamber.event.manage'],
  ['/chamber/admin/v1/events/{event_id}/publish', 'post', 'publishEventForAdmin', 'planned', '200', 'CrmebAdminBearerAuth', 'chamber.event.manage'],
  ['/chamber/admin/v1/events/{event_id}/cancel', 'post', 'cancelEventForAdmin', 'planned', '200', 'CrmebAdminBearerAuth', 'chamber.event.manage'],
  ['/chamber/admin/v1/events/{event_id}/checkin-token', 'post', 'issueEventCheckinTokenForAdmin', 'planned', '201', 'CrmebAdminBearerAuth', 'chamber.event.checkin'],
  ['/chamber/admin/v1/events/{event_id}/checkins/manual', 'post', 'createManualEventCheckinForAdmin', 'planned', '201', 'CrmebAdminBearerAuth', 'chamber.event.checkin']
]
expected_operation_contracts = {
  ['/chamber/health', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/HealthSuccess'
  },
  ['/chamber/v1/bootstrap', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/BootstrapSuccess'
  },
  ['/chamber/v1/me/bootstrap', 'post'] => {
    request_schema: '#/components/schemas/MemberBootstrapRequest',
    success_response: '#/components/responses/MemberBootstrapSuccess'
  },
  ['/chamber/v1/me/profile', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/MemberProfileSuccess'
  },
  ['/chamber/v1/me/profile', 'patch'] => {
    request_schema: '#/components/schemas/MemberProfilePatch',
    success_response: '#/components/responses/MemberProfileSuccess'
  },
  ['/chamber/v1/me/assets', 'post'] => {
    request_schema: '#/components/schemas/MemberAssetUploadRequest',
    request_content_type: 'multipart/form-data',
    success_response: '#/components/responses/MemberAssetCreated'
  },
  ['/chamber/v1/me/assets/{asset_id}/content', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/MemberAssetContent'
  },
  ['/chamber/v1/me/graduate-verifications', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/GraduateVerificationSuccess'
  },
  ['/chamber/v1/me/graduate-verifications', 'post'] => {
    request_schema: '#/components/schemas/GraduateVerificationSubmission',
    success_response: '#/components/responses/GraduateVerificationCreated'
  },
  ['/chamber/admin/v1/graduate-verifications', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/GraduateVerificationAdminListSuccess'
  },
  ['/chamber/admin/v1/member-assets/{asset_id}/content', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/MemberAssetContent'
  },
  ['/chamber/admin/v1/graduate-verifications/{application_id}', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/GraduateVerificationAdminDetailSuccess'
  },
  ['/chamber/admin/v1/graduate-verifications/{application_id}/reviews', 'post'] => {
    request_schema: '#/components/schemas/GraduateVerificationReviewRequest',
    success_response: '#/components/responses/GraduateVerificationReviewSuccess'
  },
  ['/chamber/v1/membership/plans', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/MembershipPlansSuccess'
  },
  ['/chamber/v1/membership/checkouts', 'post'] => {
    request_schema: '#/components/schemas/MembershipCheckoutRequest',
    success_response: '#/components/responses/MembershipCheckoutCreated'
  },
  ['/chamber/v1/me/membership', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/MembershipSummarySuccess'
  },
  ['/chamber/v1/events', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/EventListSuccess'
  },
  ['/chamber/v1/events/{event_id}', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/EventDetailSuccess'
  },
  ['/chamber/v1/events/{event_id}/registrations', 'post'] => {
    request_schema: '#/components/schemas/EventRegistrationRequest',
    success_response: '#/components/responses/EventRegistrationCreated'
  },
  ['/chamber/v1/me/event-registrations', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/EventRegistrationListSuccess'
  },
  ['/chamber/v1/me/event-registrations/{registration_id}', 'get'] => {
    request_schema: nil,
    success_response: '#/components/responses/EventRegistrationSuccess'
  },
  ['/chamber/v1/events/{event_id}/checkins', 'post'] => {
    request_schema: '#/components/schemas/EventCheckinRequest',
    success_response: '#/components/responses/EventCheckinCreated'
  },
  ['/chamber/admin/v1/events', 'post'] => {
    request_schema: '#/components/schemas/AdminEventCreateRequest',
    success_response: '#/components/responses/AdminEventCreated'
  },
  ['/chamber/admin/v1/events/{event_id}', 'patch'] => {
    request_schema: '#/components/schemas/AdminEventUpdateRequest',
    success_response: '#/components/responses/AdminEventUpdated'
  },
  ['/chamber/admin/v1/events/{event_id}/publish', 'post'] => {
    request_schema: nil,
    success_response: '#/components/responses/AdminEventActionSuccess'
  },
  ['/chamber/admin/v1/events/{event_id}/cancel', 'post'] => {
    request_schema: '#/components/schemas/AdminEventCancelRequest',
    success_response: '#/components/responses/AdminEventActionSuccess'
  },
  ['/chamber/admin/v1/events/{event_id}/checkin-token', 'post'] => {
    request_schema: '#/components/schemas/EventCheckinTokenRequest',
    success_response: '#/components/responses/EventCheckinTokenCreated'
  },
  ['/chamber/admin/v1/events/{event_id}/checkins/manual', 'post'] => {
    request_schema: '#/components/schemas/AdminManualEventCheckinRequest',
    success_response: '#/components/responses/AdminEventManualCheckinCreated'
  }
}
operation_ids = []
expected_operations.each do |path, method, operation_id, implementation_status, success_status, security_scheme, admin_permission|
  operation = dig_hash(spec, 'paths', path, method)
  if !operation.is_a?(Hash)
    errors << "missing #{method.upcase} operation for #{path}"
    next
  end

  errors << "#{path} operationId must be #{operation_id}" unless operation['operationId'] == operation_id
  errors << "#{method.upcase} #{path} implementation status differs" unless operation['x-implementation-status'] == implementation_status
  operation_ids << operation['operationId']
  responses = operation['responses'].is_a?(Hash) ? operation['responses'] : {}
  errors << "#{method.upcase} #{path} must define a #{success_status} response" unless responses.key?(success_status)

  contract = expected_operation_contracts.fetch([path, method])
  expected_success = { '$ref' => contract.fetch(:success_response) }
  unless responses[success_status] == expected_success
    errors << "#{method.upcase} #{path} #{success_status} response must be #{contract.fetch(:success_response)}"
  end

  expected_request_schema = contract.fetch(:request_schema)
  if expected_request_schema.nil?
    errors << "#{method.upcase} #{path} must not define requestBody" if operation.key?('requestBody')
  else
    request_body = operation['requestBody']
    if !request_body.is_a?(Hash)
      errors << "#{method.upcase} #{path} must define requestBody"
    else
      errors << "#{method.upcase} #{path} requestBody must be required" unless request_body['required'] == true
      content = request_body['content'].is_a?(Hash) ? request_body['content'] : {}
      content_types = content.keys
      request_content_type = contract.fetch(:request_content_type, 'application/json')
      unless content_types == [request_content_type]
        errors << "#{method.upcase} #{path} requestBody must use only #{request_content_type}"
      end
      request_schema = dig_hash(request_body, 'content', request_content_type, 'schema')
      unless request_schema == { '$ref' => expected_request_schema }
        errors << "#{method.upcase} #{path} requestBody schema must be #{expected_request_schema}"
      end
    end
  end

  parameter_refs = Array(operation['parameters']).map { |item| item.is_a?(Hash) ? item['$ref'] : nil }.compact
  %w[RequestIdHeader CorrelationIdHeader].each do |name|
    reference = "#/components/parameters/#{name}"
    errors << "#{method.upcase} #{path} is missing #{reference}" unless parameter_refs.include?(reference)
  end

  # Authenticated contract invariants apply equally before and after delivery.
  next unless security_scheme

  errors << "#{method.upcase} #{path} must require tenant context" unless operation['x-tenant-context'] == 'required'
  errors << "#{method.upcase} #{path} must enforce all-or-none signed headers" unless operation['x-signed-headers-all-or-none'] == true
  errors << "#{method.upcase} #{path} must require #{security_scheme}" unless operation['security'] == [{ security_scheme => [] }]
  if security_scheme == 'CrmebAdminBearerAuth'
    expected_permission = admin_permission || 'chamber.graduate_verification.review'
    errors << "#{method.upcase} #{path} x-admin-permission must be #{expected_permission}" unless operation['x-admin-permission'] == expected_permission
  end
  %w[ChamberTenantHeader ChamberChannelHeader ChamberTimestampHeader ChamberNonceHeader ChamberSignatureHeader].each do |name|
    reference = "#/components/parameters/#{name}"
    errors << "#{method.upcase} #{path} is missing #{reference}" unless parameter_refs.include?(reference)
  end
  if %w[post patch].include?(method)
    errors << "#{method.upcase} #{path} is missing Idempotency-Key" unless parameter_refs.include?('#/components/parameters/IdempotencyKeyHeader')
  end
  expected_error_responses = {
    '400' => '#/components/responses/ChamberRequestError',
    '401' => '#/components/responses/ChamberRequestError',
    '403' => '#/components/responses/ChamberRequestError',
    '500' => '#/components/responses/InternalServerError',
    '503' => '#/components/responses/TenantServiceUnavailable'
  }
  if path == '/chamber/v1/membership/checkouts' && method == 'post'
    expected_error_responses['503'] = '#/components/responses/MemberTransactionError'
  end
  expected_error_responses.each do |status, reference|
    unless responses[status] == { '$ref' => reference }
      errors << "#{method.upcase} #{path} #{status} response must be #{reference}"
    end
  end
  %w[404 409 422].each do |status|
    next unless responses.key?(status)

    reference = '#/components/responses/MemberTransactionError'
    unless responses[status] == { '$ref' => reference }
      errors << "#{method.upcase} #{path} #{status} response must be #{reference}"
    end
  end
end
errors << 'operationId values must be unique' unless operation_ids.compact.uniq.length == operation_ids.compact.length

http_methods = %w[get put post delete options head patch trace]
actual_operation_pairs = spec.fetch('paths', {}).flat_map do |path, path_item|
  next [] unless path_item.is_a?(Hash)

  path_item.keys.select { |key| http_methods.include?(key) }.map { |method| [path, method] }
end.sort
expected_operation_pairs = expected_operations.map { |operation| operation.first(2) }.sort
unexpected_operations = actual_operation_pairs - expected_operation_pairs
missing_operations = expected_operation_pairs - actual_operation_pairs
errors << "unexpected HTTP operations: #{unexpected_operations.map { |pair| pair.join(' ') }.join(', ')}" unless unexpected_operations.empty?
errors << "missing HTTP operations: #{missing_operations.map { |pair| pair.join(' ') }.join(', ')}" unless missing_operations.empty?

implemented_count = expected_operations.count { |operation| operation[3] == 'implemented' }
planned_count = expected_operations.count { |operation| operation[3] == 'planned' }

bootstrap_refs = dig_hash(spec, 'paths', '/chamber/v1/bootstrap', 'get', 'parameters') || []
bootstrap_refs = bootstrap_refs.map { |item| item.is_a?(Hash) ? item['$ref'] : nil }.compact
%w[ChamberTenantHeader ChamberChannelHeader ChamberTimestampHeader ChamberNonceHeader ChamberSignatureHeader].each do |name|
  reference = "#/components/parameters/#{name}"
  errors << "bootstrap is missing #{reference}" unless bootstrap_refs.include?(reference)
end

bearer_auth = dig_hash(spec, 'components', 'securitySchemes', 'CrmebBearerAuth') || {}
errors << 'CrmebBearerAuth must use HTTP bearer' unless bearer_auth['type'] == 'http' && bearer_auth['scheme'] == 'bearer'
admin_bearer_auth = dig_hash(spec, 'components', 'securitySchemes', 'CrmebAdminBearerAuth') || {}
errors << 'CrmebAdminBearerAuth must use HTTP bearer' unless admin_bearer_auth['type'] == 'http' && admin_bearer_auth['scheme'] == 'bearer'

expected_parameter_contracts = {
  'RequestIdHeader' => {
    name: 'X-Request-Id', required: false,
    schema: { '$ref' => '#/components/schemas/RequestId' }
  },
  'CorrelationIdHeader' => {
    name: 'X-Correlation-Id', required: false,
    schema: { '$ref' => '#/components/schemas/CorrelationId' }
  },
  'ChamberTenantHeader' => {
    name: 'X-Chamber-Tenant', required: false,
    schema: { '$ref' => '#/components/schemas/TenantSlug' }
  },
  'ChamberChannelHeader' => {
    name: 'X-Chamber-Channel', required: false,
    schema: { '$ref' => '#/components/schemas/ChannelCode' }
  },
  'ChamberTimestampHeader' => {
    name: 'X-Chamber-Timestamp', required: false,
    schema: { 'type' => 'string', 'pattern' => '^[0-9]{10}$' }
  },
  'ChamberNonceHeader' => {
    name: 'X-Chamber-Nonce', required: false,
    schema: {
      'type' => 'string', 'minLength' => 16, 'maxLength' => 128,
      'pattern' => '^[A-Za-z0-9._~-]{16,128}$'
    }
  },
  'ChamberSignatureHeader' => {
    name: 'X-Chamber-Signature', required: false,
    schema: { 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' }
  },
  'IdempotencyKeyHeader' => {
    name: 'Idempotency-Key', required: true,
    schema: { '$ref' => '#/components/schemas/IdempotencyKey' }
  }
}
expected_parameter_contracts.each do |component_name, expected|
  parameter = dig_hash(spec, 'components', 'parameters', component_name) || {}
  errors << "#{component_name} name must be #{expected.fetch(:name)}" unless parameter['name'] == expected.fetch(:name)
  errors << "#{component_name} must be a header parameter" unless parameter['in'] == 'header'
  unless parameter['required'] == expected.fetch(:required)
    errors << "#{component_name} required must be #{expected.fetch(:required)}"
  end
  parameter_schema = parameter['schema'].is_a?(Hash) ? parameter['schema'] : {}
  semantic_schema = parameter_schema.reject { |key, _value| key == 'example' }
  unless semantic_schema == expected.fetch(:schema)
    errors << "#{component_name} schema differs from the frozen contract"
  end
end

each_node(spec) do |node, path|
  next unless node.is_a?(Hash) && node.key?('$ref')

  reference = node['$ref']
  if !reference.is_a?(String) || !reference.start_with?('#/')
    errors << "external or invalid ref at #{path}: #{reference.inspect}"
  elsif resolve_pointer(spec, reference).nil?
    errors << "unresolved ref at #{path}: #{reference}"
  end
end

schemas = dig_hash(spec, 'components', 'schemas') || {}
required_envelope_fields = %w[status msg data request_id]
actual_envelope_fields = schemas.dig('ResponseEnvelopeBase', 'required') || []
missing_fields = required_envelope_fields - actual_envelope_fields
errors << "response envelope missing required fields: #{missing_fields.join(', ')}" unless missing_fields.empty?

expected_identifier_schemas = {
  'RequestId' => {
    'type' => 'string', 'minLength' => 8, 'maxLength' => 128,
    'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$'
  },
  'CorrelationId' => {
    'type' => 'string', 'minLength' => 8, 'maxLength' => 128,
    'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$'
  },
  'TenantSlug' => {
    'type' => 'string', 'minLength' => 1, 'maxLength' => 63,
    'pattern' => '^[a-z0-9][a-z0-9-]{0,62}$'
  },
  'ChannelCode' => {
    'type' => 'string', 'minLength' => 1, 'maxLength' => 64,
    'pattern' => '^[a-z0-9][a-z0-9_-]{0,63}$'
  },
  'IdempotencyKey' => {
    'type' => 'string', 'minLength' => 8, 'maxLength' => 128,
    'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$'
  }
}
expected_identifier_schemas.each do |schema_name, expected|
  schema = schemas[schema_name] || {}
  expected.each do |key, value|
    errors << "#{schema_name} #{key} differs from the frozen contract" unless schema[key] == value
  end
end

idempotency_key = schemas['IdempotencyKey'] || {}
expected_idempotency_scope = 'tenant-operation-principal'
expected_idempotency_derivation = 'sha256-v1'
unless idempotency_key['x-internal-scope'] == expected_idempotency_scope
  errors << "IdempotencyKey x-internal-scope must be #{expected_idempotency_scope}"
end
unless idempotency_key['x-internal-key-derivation'] == expected_idempotency_derivation
  errors << "IdempotencyKey x-internal-key-derivation must be #{expected_idempotency_derivation}"
end

expected_tenant_reasons = %w[
  bad_signature conflicting_context cors_origin_denied cross_channel_access cross_tenant_access
  inactive_tenant incomplete_signature invalid_input missing_context
  replay_guard_unavailable replayed_request signing_unavailable stale_signature unknown_tenant
].sort
actual_tenant_reasons = Array(schemas.dig('TenantErrorReason', 'enum')).sort
errors << 'TenantErrorReason enum differs from the frozen vocabulary' unless actual_tenant_reasons == expected_tenant_reasons

expected_enums = {
  'MembershipTier' => %w[L1 L2 L3 L4],
  'MembershipPlanTier' => %w[L3 L4],
  'MembershipDurationUnit' => %w[year],
  'MembershipTermStatus' => %w[scheduled active expired revoked refunded],
  'GraduateVerificationStatus' => %w[draft pending approved returned rejected revoked],
  'GraduateVerificationReviewAction' => %w[approve return reject revoke],
  'MemberAssetPurpose' => %w[graduate_verification_proof],
  'EventType' => %w[growth industry public_welfare],
  'EventStatus' => %w[draft published registration_closed ended cancelled],
  'EventRegistrationStatus' => %w[pending_payment registered cancelled refunded waitlisted completed],
  'OrderLifecycleStatus' => %w[pending_payment paid fulfilling completed cancelled refund_pending partially_refunded refunded],
  'CommerceEventType' => %w[
    commerce.order.completed.v1 commerce.refund.requested.v1 commerce.refund.cancelled.v1
    commerce.refund.processing.v1 commerce.refund.completed.v1 commerce.refund.failed.v1
  ],
  'CommerceCompletionKind' => %w[paid zero_amount],
  'RefundLifecycleState' => %w[none requested cancelled processing partially_completed completed failed],
  'MemberTransactionErrorCode' => %w[
    authentication_required permission_denied idempotency_key_required idempotency_conflict request_validation_failed
    member_not_found member_disabled member_attribution_locked invite_code_invalid profile_invalid
    unsupported_media_type asset_upload_invalid asset_quota_exceeded proof_asset_invalid asset_already_consumed asset_not_found
    asset_integrity_failed tenant_scope_denied consent_document_stale
    verification_already_pending verification_application_not_found verification_transition_invalid verification_supersedes_mismatch
    membership_tier_required membership_verification_required membership_plan_unavailable membership_downgrade_not_allowed
    membership_order_inconsistent membership_order_unavailable
    event_not_found event_not_open signup_not_open signup_closed event_started event_full
    channel_not_eligible points_required role_required
    event_create_failed event_update_failed event_edit_locked event_publish_locked event_publish_failed
    event_cancel_locked event_cancel_has_registrations event_ticket_create_failed event_serialization_failed
    event_ticket_not_found event_payment_unavailable event_registration_failed registration_already_exists
    registration_not_found event_reward_failed checkin_token_unavailable checkin_token_invalid checkin_already_completed
  ]
}
expected_enums.each do |name, values|
  actual = Array(schemas.dig(name, 'enum'))
  errors << "#{name} enum differs from the frozen vocabulary" unless actual == values
end

consent_acceptance = schemas['ConsentAcceptanceRequest'] || {}
errors << 'ConsentAcceptanceRequest must reject unknown fields' unless consent_acceptance['additionalProperties'] == false
expected_consent_fields = %w[document_code document_version accepted]
unless Array(consent_acceptance['required']) == expected_consent_fields
  errors << 'ConsentAcceptanceRequest required fields differ from the frozen contract'
end
consent_properties = consent_acceptance['properties'].is_a?(Hash) ? consent_acceptance['properties'] : {}
expected_document_pattern = '^[A-Za-z0-9][A-Za-z0-9._-]*$'
%w[document_code document_version].each do |field|
  unless consent_properties.dig(field, 'pattern') == expected_document_pattern
    errors << "ConsentAcceptanceRequest #{field} pattern differs from the frozen contract"
  end
end
errors << 'ConsentAcceptanceRequest accepted must be fixed at true' unless consent_properties.dig('accepted', 'const') == true

forbidden_identity_keys = %w[tenantid channelid uid userid memberid accountid openid]
actual_operation_pairs.each do |path, method|
  path_item = dig_hash(spec, 'paths', path) || {}
  operation = path_item[method] || {}
  parameters = Array(path_item['parameters']) + Array(operation['parameters'])
  parameters.each_with_index do |parameter_or_ref, index|
    parameter = resolve_reference(spec, parameter_or_ref)
    next unless parameter.is_a?(Hash) && %w[query path header cookie].include?(parameter['in'])

    normalized_name = parameter['name'].to_s.gsub(/[^A-Za-z0-9]/, '').downcase
    next unless forbidden_identity_keys.include?(normalized_name)

    errors << "#{method.upcase} #{path} parameter #{index} accepts trusted identity field #{parameter['name']}"
  end
end

expected_application_id = {
  'name' => 'application_id',
  'in' => 'path',
  'required' => true,
  'schema' => { '$ref' => '#/components/schemas/PositiveId' }
}
[
  ['/chamber/admin/v1/graduate-verifications/{application_id}', 'get'],
  ['/chamber/admin/v1/graduate-verifications/{application_id}/reviews', 'post']
].each do |path, method|
  parameters = Array(dig_hash(spec, 'paths', path, method, 'parameters'))
  unless parameters.include?(expected_application_id)
    errors << "#{method.upcase} #{path} must require the PositiveId application_id path parameter"
  end
end

expected_asset_id = {
  'name' => 'asset_id',
  'in' => 'path',
  'required' => true,
  'schema' => { '$ref' => '#/components/schemas/PositiveId' }
}
[
  ['/chamber/v1/me/assets/{asset_id}/content', 'get'],
  ['/chamber/admin/v1/member-assets/{asset_id}/content', 'get']
].each do |path, method|
  parameters = Array(dig_hash(spec, 'paths', path, method, 'parameters'))
  unless parameters.include?(expected_asset_id)
    errors << "#{method.upcase} #{path} must require the PositiveId asset_id path parameter"
  end
  operation = dig_hash(spec, 'paths', path, method) || {}
  errors << "#{method.upcase} #{path} must declare a binary response" unless operation['x-binary-response'] == true
  unless parameters.include?({ '$ref' => '#/components/parameters/DownloadQuery' })
    errors << "#{method.upcase} #{path} must declare the DownloadQuery parameter"
  end
end

admin_asset_content_parameters = Array(dig_hash(
  spec,
  'paths',
  '/chamber/admin/v1/member-assets/{asset_id}/content',
  'get',
  'parameters'
))
admin_application_query_ref = { '$ref' => '#/components/parameters/AdminAssetApplicationIdQuery' }
unless admin_asset_content_parameters.include?(admin_application_query_ref)
  errors << 'GET admin member asset content must require AdminAssetApplicationIdQuery'
end
owner_asset_content_parameters = Array(dig_hash(
  spec,
  'paths',
  '/chamber/v1/me/assets/{asset_id}/content',
  'get',
  'parameters'
))
if owner_asset_content_parameters.include?(admin_application_query_ref)
  errors << 'GET owner member asset content must not accept AdminAssetApplicationIdQuery'
end

asset_upload_schema = dig_hash(
  spec,
  'paths',
  '/chamber/v1/me/assets',
  'post',
  'requestBody',
  'content',
  'multipart/form-data',
  'schema'
)
unless asset_upload_schema == { '$ref' => '#/components/schemas/MemberAssetUploadRequest' }
  errors << 'member asset upload must use the frozen multipart request schema'
end
asset_upload_encoding = dig_hash(
  spec,
  'paths',
  '/chamber/v1/me/assets',
  'post',
  'requestBody',
  'content',
  'multipart/form-data',
  'encoding'
)
unless asset_upload_encoding == { 'file' => { 'contentType' => 'image/jpeg, image/png' } }
  errors << 'new member asset uploads must accept only JPEG and PNG multipart file content'
end

%w[
  /chamber/admin/v1/graduate-verifications
  /chamber/admin/v1/member-assets/{asset_id}/content
  /chamber/admin/v1/graduate-verifications/{application_id}
  /chamber/admin/v1/graduate-verifications/{application_id}/reviews
].each do |path|
  method = path.end_with?('/reviews') ? 'post' : 'get'
  operation = dig_hash(spec, 'paths', path, method) || {}
  unless operation['x-admin-scope'] == 'level-0-super-administrator-only'
    errors << "#{method.upcase} #{path} must remain super-administrator-only until tenant grants exist"
  end
end

admin_list_parameters = Array(dig_hash(
  spec,
  'paths',
  '/chamber/admin/v1/graduate-verifications',
  'get',
  'parameters'
))
admin_list_query_parameters = admin_list_parameters.map do |parameter_or_ref|
  parameter = resolve_reference(spec, parameter_or_ref)
  parameter if parameter.is_a?(Hash) && parameter['in'] == 'query'
end.compact
expected_admin_list_queries = %w[keyword page per_page status]
actual_admin_list_queries = admin_list_query_parameters.map { |parameter| parameter['name'] }.sort
unless actual_admin_list_queries == expected_admin_list_queries
  errors << 'graduate-verification admin list query parameters differ from the frozen contract'
end
status_query = admin_list_query_parameters.find { |parameter| parameter['name'] == 'status' } || {}
unless status_query['schema'] == { '$ref' => '#/components/schemas/GraduateVerificationStatus' }
  errors << 'graduate-verification admin list status must use GraduateVerificationStatus'
end

expected_operations.each do |path, method, _operation_id, _status, _success, _security|
  request_schema = dig_hash(spec, 'paths', path, method, 'requestBody', 'content', 'application/json', 'schema')
  next unless request_schema

  forbidden_paths = collect_property_keys(spec, request_schema).select do |_property_path, name|
    forbidden_identity_keys.include?(name.gsub(/[^A-Za-z0-9]/, '').downcase)
  end.map(&:first)
  errors << "#{method.upcase} #{path} accepts trusted identity fields: #{forbidden_paths.join(', ')}" unless forbidden_paths.empty?
end

expected_member_envelopes = %w[
  MemberBootstrapEnvelope MemberProfileEnvelope MemberAssetEnvelope GraduateVerificationQueryEnvelope
  GraduateVerificationCreatedEnvelope GraduateVerificationReviewEnvelope
  GraduateVerificationAdminListEnvelope GraduateVerificationAdminDetailEnvelope MembershipPlansEnvelope
  MembershipCheckoutEnvelope MembershipSummaryEnvelope MemberTransactionErrorEnvelope
  ChamberRequestErrorEnvelope
]
missing_member_envelopes = expected_member_envelopes.reject { |name| schemas[name].is_a?(Hash) }
errors << "member envelopes missing: #{missing_member_envelopes.join(', ')}" unless missing_member_envelopes.empty?

money = schemas['MoneyAmount'] || {}
errors << 'MoneyAmount must be a string' unless money['type'] == 'string'
errors << 'MoneyAmount must require exactly two decimal places' unless money['pattern'] == '^(0|[1-9][0-9]{0,13})\.[0-9]{2}$'

timestamp = schemas['UnixTimestampSeconds'] || {}
errors << 'UnixTimestampSeconds must be integer/int64' unless timestamp['type'] == 'integer' && timestamp['format'] == 'int64'
errors << 'UnixTimestampSeconds must fit MySQL UNSIGNED INT' unless timestamp['maximum'] == 4_294_967_295

object_storage_key = schemas['ObjectStorageKey'] || {}
expected_object_storage_key_pattern = '^(?!https?://)(?!/)(?!.*//)(?!.*(?:^|/)\.{1,2}(?:/|$))(?!.*\/$)[A-Za-z0-9][A-Za-z0-9._/-]*$'
errors << 'ObjectStorageKey normalization guards differ from the frozen contract' unless object_storage_key['pattern'] == expected_object_storage_key_pattern
begin
  object_storage_key_regexp = Regexp.new(expected_object_storage_key_pattern)
  %w[member/42/avatar.jpg verification/2026/proof_01.png].each do |sample|
    errors << "ObjectStorageKey rejects valid sample: #{sample}" unless object_storage_key_regexp.match?(sample)
  end
  ['https://example.test/a.jpg', '/absolute/key', 'a//b', 'a/', 'a/./b', 'a/../secret'].each do |sample|
    errors << "ObjectStorageKey accepts ambiguous sample: #{sample}" if object_storage_key_regexp.match?(sample)
  end
rescue RegexpError => e
  errors << "ObjectStorageKey pattern is invalid: #{e.message}"
end

asset_upload = schemas['MemberAssetUploadRequest'] || {}
errors << 'MemberAssetUploadRequest must reject unknown fields' unless asset_upload['additionalProperties'] == false
unless Array(asset_upload['required']) == %w[purpose file]
  errors << 'MemberAssetUploadRequest required fields differ from the frozen contract'
end
expected_asset_upload_file = {
  'type' => 'string',
  'format' => 'binary',
  'description' => 'New uploads accept JPEG and PNG images only; authorized reads of previously stored PDF assets remain supported.'
}
unless asset_upload.dig('properties', 'file') == expected_asset_upload_file
  errors << 'MemberAssetUploadRequest file must be binary and document the JPEG/PNG-only upload policy'
end
member_asset = schemas['MemberAsset'] || {}
expected_member_asset_fields = %w[id object_key original_name mime_type size available]
unless Array(member_asset['required']) == expected_member_asset_fields
  errors << 'MemberAsset required fields differ from the frozen contract'
end
errors << 'MemberAsset must reject unknown fields' unless member_asset['additionalProperties'] == false
unless member_asset.dig('properties', 'size', 'maximum') == 10_485_760
  errors << 'MemberAsset maximum size must remain 10 MiB'
end
unless Array(member_asset.dig('properties', 'mime_type', 'enum')) == %w[image/jpeg image/png application/pdf]
  errors << 'MemberAsset MIME allowlist differs from the frozen contract'
end
errors << 'MemberAsset availability must be boolean' unless member_asset.dig('properties', 'available', 'type') == 'boolean'
member_asset_content_types = (dig_hash(
  spec,
  'components',
  'responses',
  'MemberAssetContent',
  'content'
) || {}).keys.sort
unless member_asset_content_types == %w[application/pdf image/jpeg image/png]
  errors << 'MemberAssetContent must retain authenticated JPEG, PNG, and historical PDF reads'
end

upload_responses = dig_hash(spec, 'paths', '/chamber/v1/me/assets', 'post', 'responses') || {}
unless upload_responses['413'] == { '$ref' => '#/components/responses/MemberTransactionError' }
  errors << 'member asset upload must declare the JSON HTTP 413 response'
end

%w[GraduateVerificationApplication GraduateVerificationAdminApplication].each do |schema_name|
  schema = schemas[schema_name] || {}
  unless Array(schema['required']).include?('proof_assets')
    errors << "#{schema_name} must require proof_assets"
  end
  unless schema.dig('properties', 'proof_assets', 'items', '$ref') == '#/components/schemas/MemberAsset'
    errors << "#{schema_name} proof_assets must use MemberAsset"
  end
end

page_meta_required = Array(schemas.dig('PageMeta', 'required'))
expected_page_meta = %w[page limit total total_pages has_more]
errors << 'PageMeta required fields differ from the frozen contract' unless page_meta_required == expected_page_meta
errors << 'limit maximum must be 100' unless dig_hash(spec, 'components', 'parameters', 'LimitQuery', 'schema', 'maximum') == 100
errors << 'per_page maximum must be 100' unless dig_hash(spec, 'components', 'parameters', 'PerPageQuery', 'schema', 'maximum') == 100
unless dig_hash(spec, 'components', 'parameters', 'DownloadQuery', 'schema', 'enum') == [0, 1]
  errors << 'download query must be limited to 0 or 1'
end
expected_admin_application_query = {
  'name' => 'application_id',
  'in' => 'query',
  'required' => true,
  'description' => 'Graduate-verification application that owns this proof access and receives the read audit.',
  'schema' => { '$ref' => '#/components/schemas/PositiveId' }
}
unless dig_hash(spec, 'components', 'parameters', 'AdminAssetApplicationIdQuery') == expected_admin_application_query
  errors << 'admin asset application query must be a required PositiveId'
end

{
  ['/chamber/v1/me/profile', 'get'] => '409',
  ['/chamber/v1/me/graduate-verifications', 'post'] => '404'
}.each do |(path, method), status|
  responses = dig_hash(spec, 'paths', path, method, 'responses') || {}
  errors << "#{method.upcase} #{path} must declare runtime response #{status}" unless responses.key?(status)
end

commerce_event = schemas['CommerceEvent'] || {}
expected_commerce_fields = %w[
  event_id payload_hash source source_event_id event_type schema_version occurred_at tenant_id
  channel_id order_pk order_no uid business_type context_id currency paid_amount correlation_id
]
errors << 'CommerceEvent must reject unknown fields' unless commerce_event['additionalProperties'] == false
missing_commerce_fields = expected_commerce_fields - Array(commerce_event['required'])
errors << "CommerceEvent required fields are incomplete: #{missing_commerce_fields.join(', ')}" unless missing_commerce_fields.empty?
commerce_properties = commerce_event['properties'] || {}
errors << 'CommerceEvent event_id must be a lowercase SHA-256' unless commerce_properties.dig('event_id', 'pattern') == '^[a-f0-9]{64}$'
errors << 'CommerceEvent payload_hash must be a lowercase SHA-256' unless commerce_properties.dig('payload_hash', 'pattern') == '^[a-f0-9]{64}$'
errors << 'CommerceEvent schema_version must be fixed at 1' unless commerce_properties.dig('schema_version', 'const') == 1
forbidden_commerce_fields = %w[real_name nickname phone user_phone mobile email address user_address id_card identity_card openid open_id]
present_forbidden_fields = forbidden_commerce_fields & commerce_properties.keys
errors << "CommerceEvent exposes PII fields: #{present_forbidden_fields.join(', ')}" unless present_forbidden_fields.empty?

commerce_families = Array(commerce_event['oneOf'])
if commerce_families.length != 2
  errors << 'CommerceEvent must define exactly two event-family branches'
else
  order_family, refund_family = commerce_families
  expected_order_fields = %w[completion_kind pay_type trade_no paid_at]
  expected_refund_fields = %w[
    refund_pk refund_no provider_refund_no refund_status refund_delta cumulative_refunded_amount
    completion_id completion_source provider_status
  ]
  errors << 'CommerceEvent order family required fields differ' unless Array(order_family['required']) == expected_order_fields
  errors << 'CommerceEvent refund family required fields differ' unless Array(refund_family['required']) == expected_refund_fields
  errors << 'CommerceEvent order family event_type is not fixed' unless order_family.dig('properties', 'event_type', 'const') == 'commerce.order.completed.v1'
  expected_refund_types = expected_enums['CommerceEventType'].drop(1)
  errors << 'CommerceEvent refund family event types differ' unless Array(refund_family.dig('properties', 'event_type', 'enum')) == expected_refund_types
  order_forbidden = Array(order_family.dig('not', 'anyOf')).flat_map { |rule| Array(rule['required']) }
  refund_forbidden = Array(refund_family.dig('not', 'anyOf')).flat_map { |rule| Array(rule['required']) }
  errors << 'CommerceEvent order family does not forbid every refund field' unless order_forbidden == expected_refund_fields
  errors << 'CommerceEvent refund family does not forbid every order field' unless refund_forbidden == expected_order_fields
end

each_node(spec) do |node, path|
  next unless node.is_a?(Hash)

  errors << "floating point schema is forbidden at #{path}" if node['type'] == 'number' || %w[float double].include?(node['format'])
end

response_components = dig_hash(spec, 'components', 'responses') || {}
response_components.each do |name, response|
  next unless response.is_a?(Hash)

  headers = response['headers'] || {}
  %w[X-Request-Id X-Correlation-Id].each do |header|
    errors << "response component #{name} is missing #{header}" unless headers.key?(header)
  end
end

unless File.file?(html_path)
  errors << "HTML guide not found: #{html_path}"
else
  html = File.read(html_path)
  errors << 'HTML guide must start with a doctype' unless html.lstrip.start_with?('<!DOCTYPE html>')
  errors << 'HTML guide must link chamber-openapi.yaml' unless html.include?('href="chamber-openapi.yaml"')
  errors << 'HTML guide must document runtime contract alignment' unless html.include?('运行时已对齐')
end

if errors.empty?
  puts "PASS: #{spec_path}"
  puts "  OpenAPI: #{spec['openapi']}"
  puts "  Contract version: #{dig_hash(spec, 'info', 'version')}"
  puts "  Paths: #{actual_paths.length} (#{implemented_count} implemented, #{planned_count} planned operations)"
  puts "  Component schemas: #{schemas.length}"
  puts "  Contract references: resolved"
  puts "  Governance invariants: satisfied"
  exit 0
end

warn "FAIL: #{errors.length} contract validation error(s)"
errors.each { |error| warn "  - #{error}" }
exit 1
