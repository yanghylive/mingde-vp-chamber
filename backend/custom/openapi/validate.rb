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

unless spec.is_a?(Hash)
  warn 'FAIL: YAML root must be an object'
  exit 1
end

errors << 'openapi must equal 3.1.0' unless spec['openapi'] == '3.1.0'
errors << 'info.version must equal 0.2.0' unless dig_hash(spec, 'info', 'version') == '0.2.0'

expected_paths = ['/chamber/health', '/chamber/v1/bootstrap']
actual_paths = spec.fetch('paths', {}).keys.sort
errors << "paths must contain only #{expected_paths.join(', ')}" unless actual_paths == expected_paths.sort

expected_operations = {
  '/chamber/health' => 'getChamberHealth',
  '/chamber/v1/bootstrap' => 'getChamberBootstrap'
}
operation_ids = []
expected_operations.each do |path, operation_id|
  operation = dig_hash(spec, 'paths', path, 'get')
  if !operation.is_a?(Hash)
    errors << "missing GET operation for #{path}"
    next
  end

  errors << "#{path} operationId must be #{operation_id}" unless operation['operationId'] == operation_id
  operation_ids << operation['operationId']
  errors << "#{path} must define a 200 response" unless operation.fetch('responses', {}).key?('200')

  parameter_refs = operation.fetch('parameters', []).map { |item| item.is_a?(Hash) ? item['$ref'] : nil }.compact
  %w[RequestIdHeader CorrelationIdHeader].each do |name|
    reference = "#/components/parameters/#{name}"
    errors << "#{path} is missing #{reference}" unless parameter_refs.include?(reference)
  end
end
errors << 'operationId values must be unique' unless operation_ids.compact.uniq.length == operation_ids.compact.length

bootstrap_refs = dig_hash(spec, 'paths', '/chamber/v1/bootstrap', 'get', 'parameters') || []
bootstrap_refs = bootstrap_refs.map { |item| item.is_a?(Hash) ? item['$ref'] : nil }.compact
%w[ChamberTenantHeader ChamberChannelHeader ChamberTimestampHeader ChamberNonceHeader ChamberSignatureHeader].each do |name|
  reference = "#/components/parameters/#{name}"
  errors << "bootstrap is missing #{reference}" unless bootstrap_refs.include?(reference)
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

request_id_pattern = schemas.dig('RequestId', 'pattern')
errors << 'RequestId must define a pattern' unless request_id_pattern.is_a?(String) && !request_id_pattern.empty?

expected_tenant_reasons = %w[
  bad_signature conflicting_context cors_origin_denied cross_channel_access cross_tenant_access
  inactive_tenant incomplete_signature invalid_input missing_context
  replay_guard_unavailable replayed_request signing_unavailable stale_signature unknown_tenant
].sort
actual_tenant_reasons = Array(schemas.dig('TenantErrorReason', 'enum')).sort
errors << 'TenantErrorReason enum differs from the frozen vocabulary' unless actual_tenant_reasons == expected_tenant_reasons

expected_enums = {
  'MembershipTier' => %w[L1 L2 L3 L4],
  'GraduateVerificationStatus' => %w[draft pending approved returned rejected revoked],
  'EventType' => %w[growth industry public_welfare],
  'EventStatus' => %w[draft published registration_closed ended cancelled],
  'EventRegistrationStatus' => %w[pending_payment registered cancelled refunded waitlisted completed],
  'OrderLifecycleStatus' => %w[pending_payment paid fulfilling completed cancelled refund_pending partially_refunded refunded],
  'CommerceEventType' => %w[
    commerce.order.completed.v1 commerce.refund.requested.v1 commerce.refund.cancelled.v1
    commerce.refund.processing.v1 commerce.refund.completed.v1 commerce.refund.failed.v1
  ],
  'CommerceCompletionKind' => %w[paid zero_amount],
  'RefundLifecycleState' => %w[none requested cancelled processing partially_completed completed failed]
}
expected_enums.each do |name, values|
  actual = Array(schemas.dig(name, 'enum'))
  errors << "#{name} enum differs from the frozen vocabulary" unless actual == values
end

money = schemas['MoneyAmount'] || {}
errors << 'MoneyAmount must be a string' unless money['type'] == 'string'
errors << 'MoneyAmount must require exactly two decimal places' unless money['pattern'] == '^(0|[1-9][0-9]{0,13})\.[0-9]{2}$'

timestamp = schemas['UnixTimestampSeconds'] || {}
errors << 'UnixTimestampSeconds must be integer/int64' unless timestamp['type'] == 'integer' && timestamp['format'] == 'int64'

page_meta_required = Array(schemas.dig('PageMeta', 'required'))
expected_page_meta = %w[page limit total total_pages has_more]
errors << 'PageMeta required fields differ from the frozen contract' unless page_meta_required == expected_page_meta
errors << 'limit maximum must be 100' unless dig_hash(spec, 'components', 'parameters', 'LimitQuery', 'schema', 'maximum') == 100

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
  puts "  Implemented paths: #{actual_paths.length}"
  puts "  Component schemas: #{schemas.length}"
  puts "  Contract references: resolved"
  puts "  Governance invariants: satisfied"
  exit 0
end

warn "FAIL: #{errors.length} contract validation error(s)"
errors.each { |error| warn "  - #{error}" }
exit 1
