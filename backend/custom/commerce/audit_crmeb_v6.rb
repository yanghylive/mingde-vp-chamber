#!/usr/bin/env ruby
# frozen_string_literal: true

require "open3"

class AuditError < StandardError; end

MethodSegment = Struct.new(:name, :text, :start_index, :end_index, :start_line, :end_line)
CheckResult = Struct.new(:id, :title, :risk, :expected, :observed, :evidence, :matched)

class PhpSource
  attr_reader :path, :relative_path, :raw

  def initialize(path, relative_path)
    @path = path
    @relative_path = relative_path
    @raw = File.binread(path)
    @masked = self.class.mask_non_code(@raw, relative_path)
    validate_delimiters!
  end

  def self.mask_non_code(raw, label)
    masked = raw.dup
    state = :code
    index = 0

    while index < raw.bytesize
      byte = raw.getbyte(index)
      following = index + 1 < raw.bytesize ? raw.getbyte(index + 1) : nil

      case state
      when :code
        if byte == 47 && following == 47 # //
          masked.setbyte(index, 32)
          masked.setbyte(index + 1, 32)
          state = :line_comment
          index += 2
          next
        elsif byte == 47 && following == 42 # /*
          masked.setbyte(index, 32)
          masked.setbyte(index + 1, 32)
          state = :block_comment
          index += 2
          next
        elsif byte == 35 && following != 91 # #, but not PHP 8 attribute #[
          masked.setbyte(index, 32)
          state = :line_comment
        elsif byte == 39
          masked.setbyte(index, 32)
          state = :single_quote
        elsif byte == 34
          masked.setbyte(index, 32)
          state = :double_quote
        end
      when :line_comment
        if byte == 10
          state = :code
        else
          masked.setbyte(index, 32)
        end
      when :block_comment
        if byte == 42 && following == 47
          masked.setbyte(index, 32)
          masked.setbyte(index + 1, 32)
          state = :code
          index += 2
          next
        end
        masked.setbyte(index, 32) unless byte == 10
      when :single_quote, :double_quote
        quote = state == :single_quote ? 39 : 34
        if byte == 92 && following
          masked.setbyte(index, 32)
          masked.setbyte(index + 1, 32) unless following == 10
          index += 2
          next
        end
        masked.setbyte(index, 32) unless byte == 10
        state = :code if byte == quote
      end

      index += 1
    end

    unless state == :code || state == :line_comment
      raise AuditError, "#{label}: unterminated #{state}"
    end

    masked
  end

  def method_segment(name)
    pattern = /\b(?:public|protected|private)\s+(?:static\s+)?function\s+#{Regexp.escape(name)}\s*\(/
    matches = @masked.enum_for(:scan, pattern).map { Regexp.last_match }
    raise AuditError, "#{relative_path}: method #{name} not found" if matches.empty?
    raise AuditError, "#{relative_path}: method #{name} is ambiguous" if matches.length > 1

    match = matches.first
    opening = @masked.index("{", match.end(0))
    semicolon = @masked.index(";", match.end(0))
    if opening.nil? || (!semicolon.nil? && semicolon < opening)
      raise AuditError, "#{relative_path}: method #{name} has no body"
    end

    closing = matching_brace(opening)
    MethodSegment.new(
      name,
      raw.byteslice(match.begin(0)..closing),
      match.begin(0),
      closing,
      line_at(match.begin(0)),
      line_at(closing)
    )
  end

  def defines_method?(name)
    pattern = /\b(?:public|protected|private)\s+(?:static\s+)?function\s+#{Regexp.escape(name)}\s*\(/
    @masked.match?(pattern)
  end

  def line_at(index)
    raw.byteslice(0, index).count("\n") + 1
  end

  def evidence(segment, pattern = nil)
    line = segment.start_line
    if pattern
      local_index = segment.text.index(pattern)
      line = line_at(segment.start_index + local_index) if local_index
    end
    "#{relative_path}:#{line}"
  end

  private

  def validate_delimiters!
    opening_for = { 41 => 40, 93 => 91, 125 => 123 }
    closing = opening_for.keys
    opening = opening_for.values
    stack = []

    @masked.bytes.each_with_index do |byte, index|
      stack << [byte, index] if opening.include?(byte)
      next unless closing.include?(byte)

      expected = opening_for.fetch(byte)
      actual = stack.pop
      unless actual && actual.first == expected
        raise AuditError, "#{relative_path}: mismatched delimiter at line #{line_at(index)}"
      end
    end

    return if stack.empty?

    raise AuditError, "#{relative_path}: unclosed delimiter at line #{line_at(stack.last.last)}"
  end

  def matching_brace(opening)
    depth = 0
    index = opening
    while index < @masked.bytesize
      byte = @masked.getbyte(index)
      depth += 1 if byte == 123
      if byte == 125
        depth -= 1
        return index if depth.zero?
      end
      index += 1
    end
    raise AuditError, "#{relative_path}: method body starting at line #{line_at(opening)} is unclosed"
  end
end

class CrmebV6Audit
  LOCKED_COMMIT = "7dcddffff73ec542d689f159724296351f29ea9a"
  LOCKED_TAG = "v6.0.0"

  TARGETS = {
    store_success: "app/services/order/StoreOrderSuccessServices.php",
    pay_notify: "app/services/pay/PayNotifyServices.php",
    offline: "app/services/pay/OrderOfflineServices.php",
    other_order: "app/services/order/OtherOrderServices.php",
    refund: "app/services/order/StoreOrderRefundServices.php",
    v3: "crmeb/services/pay/storage/V3WechatPay.php",
    ali: "crmeb/services/pay/storage/AliPay.php",
    allin: "crmeb/services/pay/storage/AllinPay.php",
    v2: "crmeb/services/pay/storage/WechatPay.php",
    wechat_service: "crmeb/services/app/WechatService.php"
  }.freeze

  def initialize
    @project_root = File.expand_path("../../..", __dir__)
    @submodule_root = File.join(@project_root, "backend", "crmeb")
    @source_root = File.join(@submodule_root, "crmeb")
    @sources = {}
    @checks = []
  end

  def run
    puts "CRMEB v6.0.0 source compatibility audit"
    puts "Project: #{@project_root}"
    preflight!
    verify_source_lock!
    evaluate_checks
    print_matrix

    failed = @checks.reject(&:matched)
    if failed.empty?
      puts "\nResult: #{@checks.length}/#{@checks.length} locked expectations matched."
      return 0
    end

    puts "\nResult: #{failed.length} compatibility expectation(s) drifted: #{failed.map(&:id).join(', ')}"
    1
  rescue AuditError => e
    warn "PRECHECK FAILED: #{e.message}"
    2
  end

  private

  def preflight!
    raise AuditError, "source root not found: #{@source_root}" unless Dir.exist?(@source_root)

    missing = TARGETS.values.reject do |relative|
      path = File.join(@source_root, relative)
      File.file?(path) && File.readable?(path) && File.size?(path)
    end
    raise AuditError, "missing or unreadable source file(s): #{missing.join(', ')}" unless missing.empty?

    TARGETS.each do |key, relative|
      @sources[key] = PhpSource.new(File.join(@source_root, relative), relative)
    end
    puts "Preflight: PASS (#{TARGETS.length} files present; strings/comments and delimiters structurally valid)"
  end

  def verify_source_lock!
    commit = git("rev-parse", "HEAD")
    tag = git("describe", "--tags", "--exact-match", "HEAD")
    dirty = git("status", "--porcelain", "--untracked-files=no")

    raise AuditError, "expected commit #{LOCKED_COMMIT}, got #{commit}" unless commit == LOCKED_COMMIT
    raise AuditError, "expected tag #{LOCKED_TAG}, got #{tag}" unless tag == LOCKED_TAG
    raise AuditError, "tracked CRMEB source is dirty: #{dirty.lines.first.to_s.strip}" unless dirty.empty?

    puts "Source lock: PASS (#{tag} @ #{commit})"
  end

  def git(*arguments)
    stdout, stderr, status = Open3.capture3("git", "-C", @submodule_root, *arguments)
    raise AuditError, "git #{arguments.join(' ')} failed: #{stderr.strip}" unless status.success?

    stdout.strip
  rescue Errno::ENOENT
    raise AuditError, "git executable is required to verify the locked source"
  end

  def evaluate_checks
    unified_store_event
    pay_notify_race
    missing_unified_events
    synchronous_refund_confirmation
    missing_refund_query_consumer
    provider_query_capabilities
    refund_event_pii
  end

  def unified_store_event
    source = @sources.fetch(:store_success)
    method = source.method_segment("paySuccess")
    pattern = /event\s*\(\s*['"]OrderPaySuccessListener['"]/
    matched = method.text.match?(pattern)
    add_check(
      "PAY-01", "StoreOrder unified payment event", "INFO",
      "StoreOrderSuccessServices#paySuccess emits OrderPaySuccessListener",
      matched ? "event is emitted" : "event is absent",
      source.evidence(method, pattern), matched
    )
  end

  def pay_notify_race
    notify_source = @sources.fetch(:pay_notify)
    notify = notify_source.method_segment("wechatProduct")
    store_source = @sources.fetch(:store_success)
    success = store_source.method_segment("paySuccess")

    paid_check = notify.text.index(/if\s*\(\s*\$orderInfo->paid\s*\)\s*return\s+true\s*;/)
    success_call = notify.text.index(/return\s+\$services->paySuccess\s*\(/)
    id_only_update = success.text.index(/\$this->dao->update\s*\(\s*\$orderInfo\[['"]id['"]\]\s*,\s*\$updata\s*\)/)
    no_atomic_guard = !success.text.match?(/(?:transaction|lockForUpdate|lock)\s*\(/) &&
                      !success.text.match?(/['"]paid['"]\s*=>\s*0/)
    matched = paid_check && success_call && paid_check < success_call && id_only_update && no_atomic_guard

    add_check(
      "PAY-02", "PayNotify check-then-act race", "HIGH",
      "paid is prechecked, then paySuccess performs an ID-only update without lock/CAS",
      matched ? "known race shape is present" : "race shape changed",
      [notify_source.evidence(notify, /\$orderInfo->paid/), store_source.evidence(success, /\$this->dao->update/)].join(", "),
      !!matched
    )
  end

  def missing_unified_events
    pattern = /event\s*\(\s*['"]OrderPaySuccessListener['"]/

    offline_source = @sources.fetch(:offline)
    offline = offline_source.method_segment("orderOffline")
    offline_matched = !offline.text.match?(pattern)
    add_check(
      "PAY-03", "Offline unified event coverage", "HIGH",
      "OrderOfflineServices#orderOffline does not emit OrderPaySuccessListener",
      offline_matched ? "unified event is absent" : "unified event is now present",
      offline_source.evidence(offline), offline_matched
    )

    other_source = @sources.fetch(:other_order)
    other = other_source.method_segment("paySuccess")
    other_matched = !other.text.match?(pattern)
    add_check(
      "PAY-04", "OtherOrder unified event coverage", "HIGH",
      "OtherOrderServices#paySuccess does not emit OrderPaySuccessListener",
      other_matched ? "unified event is absent" : "unified event is now present",
      other_source.evidence(other), other_matched
    )
  end

  def synchronous_refund_confirmation
    source = @sources.fetch(:refund)
    method = source.method_segment("agreeRefund")
    refund_patterns = [
      /\$pay->refund\s*\(/,
      /\$this->yueRefund\s*\(/,
      /AliPayService::instance\(\)->refund\s*\(/
    ]
    refund_positions = refund_patterns.map { |pattern| method.text.index(pattern) }
    status_position = method.text.index(/['"]refund_status['"]\s*=>\s*2/)
    event_pattern = /['"]admin_order_refund_success['"]/
    event_position = method.text.index(event_pattern)
    no_query_between = if refund_positions.compact.empty? || status_position.nil?
                         false
                       else
                         start = refund_positions.compact.min
                         !method.text.byteslice(start...status_position).match?(/queryRefund\s*\(/)
                       end
    matched = refund_positions.all? && status_position && event_position &&
              refund_positions.max < status_position && status_position < event_position && no_query_between

    add_check(
      "REF-01", "Synchronous refund confirmation", "CRITICAL",
      "refund calls are followed by refund_status=2 and admin_order_refund_success without query confirmation",
      matched ? "provider acceptance is treated as final refund success" : "refund finalization flow changed",
      [source.evidence(method, refund_patterns.first), source.evidence(method, /['"]refund_status['"]\s*=>\s*2/), source.evidence(method, event_pattern)].join(", "),
      !!matched
    )
  end

  def missing_refund_query_consumer
    calls = []
    Dir.glob(File.join(@source_root, "app", "**", "*.php")).sort.each do |path|
      raw = File.binread(path)
      match = raw.match(/(?:->|::)\s*queryRefund\s*\(/)
      next unless match

      relative = path.delete_prefix(@source_root + File::SEPARATOR)
      line = raw.byteslice(0, match.begin(0)).count("\n") + 1
      calls << "#{relative}:#{line}"
    end
    matched = calls.empty?
    add_check(
      "REF-02", "Business refund-query consumer", "CRITICAL",
      "the app business layer has no queryRefund call site",
      matched ? "no app/**/*.php consumer found" : "consumer found at #{calls.join(', ')}",
      "app/**/*.php", matched
    )
  end

  def provider_query_capabilities
    v3_source = @sources.fetch(:v3)
    v3 = v3_source.method_segment("queryRefund")
    v3_pattern = /->queryRefund\s*\(\s*\$outTradeNo\s*\)/
    v3_matched = v3.text.match?(v3_pattern)
    add_check(
      "CAP-01", "WeChat V3 refund query", "INFO",
      "V3WechatPay#queryRefund delegates to the V3 client",
      v3_matched ? "query capability exists" : "delegation is absent",
      v3_source.evidence(v3, v3_pattern), v3_matched
    )

    ali_source = @sources.fetch(:ali)
    ali = ali_source.method_segment("queryRefund")
    ali_pattern = /AliPayService::instance\(\)->queryRefund\s*\(/
    ali_matched = ali.text.match?(ali_pattern)
    add_check(
      "CAP-02", "Alipay refund query", "INFO",
      "AliPay#queryRefund delegates to AliPayService",
      ali_matched ? "query capability exists" : "delegation is absent",
      ali_source.evidence(ali, ali_pattern), ali_matched
    )

    allin_source = @sources.fetch(:allin)
    allin = allin_source.method_segment("queryRefund")
    allin_matched = allin.text.match?(/TODO:\s*Implement queryRefund/) &&
                    !allin.text.match?(/(?:->|::)\s*queryRefund\s*\(/) &&
                    !allin.text.match?(/\breturn\b/)
    add_check(
      "CAP-03", "AllinPay refund query", "HIGH",
      "AllinPay#queryRefund remains an unimplemented TODO",
      allin_matched ? "query capability is not implemented" : "implementation state changed",
      allin_source.evidence(allin, /TODO:\s*Implement queryRefund/), allin_matched
    )

    v2_source = @sources.fetch(:v2)
    v2 = v2_source.method_segment("queryRefund")
    service_source = @sources.fetch(:wechat_service)
    wrapper_pattern = /WechatService::queryRefund\s*\(/
    v2_matched = v2.text.match?(wrapper_pattern) && !service_source.defines_method?("queryRefund")
    add_check(
      "CAP-04", "WeChat V2 refund-query wrapper", "CRITICAL",
      "WechatPay calls WechatService::queryRefund, but WechatService has no such method",
      v2_matched ? "wrapper targets a missing method" : "wrapper/service contract changed",
      [v2_source.evidence(v2, wrapper_pattern), "#{service_source.relative_path}:method-absent"].join(", "),
      v2_matched
    )
  end

  def refund_event_pii
    source = @sources.fetch(:refund)
    method = source.method_segment("agreeRefund")
    event_pattern = /event\s*\(\s*['"]CustomEventListener['"]\s*,\s*\[\s*['"]admin_order_refund_success['"]/
    event_position = method.text.index(event_pattern)
    event_slice = event_position ? method.text.byteslice(event_position, 1_500) : ""
    pii_fields = %w[real_name user_phone user_address]
    present = pii_fields.select { |field| event_slice.match?(/['"]#{field}['"]\s*=>/) }
    matched = event_position && present.sort == pii_fields.sort
    add_check(
      "PRIV-01", "Refund event PII payload", "HIGH",
      "admin_order_refund_success includes real_name, user_phone, and user_address",
      matched ? "PII fields present: #{present.join(', ')}" : "PII payload changed: #{present.join(', ')}",
      source.evidence(method, event_pattern), !!matched
    )
  end

  def add_check(id, title, risk, expected, observed, evidence, matched)
    @checks << CheckResult.new(id, title, risk, expected, observed, evidence, matched)
  end

  def print_matrix
    puts "\nCapability matrix (MATCH means the locked observation is unchanged, not that it is safe)"
    puts format("%-8s %-8s %-9s %s", "ID", "RESULT", "RISK", "CAPABILITY")
    puts "-" * 88
    @checks.each do |check|
      result = check.matched ? "MATCH" : "DRIFT"
      puts format("%-8s %-8s %-9s %s", check.id, result, check.risk, check.title)
      puts "         expected: #{check.expected}"
      puts "         observed: #{check.observed}"
      puts "         evidence: #{check.evidence}"
    end
  end
end

exit CrmebV6Audit.new.run
