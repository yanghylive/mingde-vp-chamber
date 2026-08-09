<?php

declare(strict_types=1);

namespace app\chamber\coaching;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\AiUsageRecorder;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use think\facade\Log;
use Throwable;

/**
 * 小薇认知教练核心服务。
 * 流程：早间生成(3问+微优化+挑战) → 会员回传 → 晚间复盘 → 控速机制。
 * 每位会员数据隔离（ch_coaching_*），DISCERN 共享（ch_discern_config）。
 */
final class CoachingService
{
    private const GENERATION_QUOTA_PREFIX = 'chamber:coaching:gen:';
    private const DEFAULT_FALLBACK = [
        'questions' => [
            '今天，你最想推进的事业主线是什么？',
            '哪个旧习惯正在拖慢你？今天可以如何做结实而非做快？',
            '回顾昨日，哪一步真正接近了你的目标？',
        ],
        'micro_optimization' => '把手机放远 10 分钟，专注完成今天最重要的一件事。',
        'challenge' => '今晚复盘前，记录今天为事业主线做出的 1 个具体行动。',
        'challenge_criteria' => '完成标准：写下行动内容（一句话即可）。',
        'closing' => '完成挑战后，晚间小薇会自动为你复盘。',
    ];

    public function today(TenantContext $tenant, AuthenticatedUserContext $auth): array
    {
        $ctx = $this->context($tenant, $auth);
        $date = date('Y-m-d');
        $daily = $this->daily($ctx, $date);
        $streak = $this->streak($ctx, $date);

        return [
            'date' => $date,
            'brand' => $this->brand($tenant),
            'morning' => $daily !== null ? $daily['morning_challenge'] : null,
            'responses' => $daily !== null ? $daily['responses'] : null,
            'respond_status' => $daily !== null ? (int) $daily['respond_status'] : 0,
            'evening_review' => $daily !== null ? $daily['evening_review'] : null,
            'streak' => $streak,
            'cooldown_mode' => $streak >= $this->streakThreshold($tenant),
        ];
    }

    public function morning(TenantContext $tenant, AuthenticatedUserContext $auth, bool $force = false): array
    {
        $ctx = $this->context($tenant, $auth);
        $date = date('Y-m-d');
        $daily = $this->daily($ctx, $date);

        if ($daily !== null && is_array($daily['morning_challenge']) && !$force) {
            return $daily['morning_challenge'];
        }

        // 成本防护：真正调用付费网关前校验每日生成配额（force=true 同样计数）
        $this->assertGenerationQuota($ctx);

        $profile = $this->profile($ctx);
        $config = $this->config($ctx);
        $yesterday = $this->yesterday($ctx, $date);
        $discern = $this->discern($tenant);
        $streak = $this->streak($ctx, $date);

        $prompt = new CoachingPrompt();
        $system = $prompt->buildMorningSystem(
            $discern['content'],
            $discern['brand_name'],
            $discern['voice_style'],
            $streak
        );
        $user = $prompt->buildMorningUser($profile, $config, $yesterday, $date);

        $generated = $this->generate($system, $user, $discern['brand_name'], $ctx, 'morning');

        // 回写当日记录（morning_challenge 存档，供晚间精准对照）
        $morningPayload = [
            'questions' => $generated['questions'] ?? self::DEFAULT_FALLBACK['questions'],
            'micro_optimization' => $generated['micro_optimization'] ?? self::DEFAULT_FALLBACK['micro_optimization'],
            'challenge' => $generated['challenge'] ?? self::DEFAULT_FALLBACK['challenge'],
            'challenge_criteria' => $generated['challenge_criteria'] ?? self::DEFAULT_FALLBACK['challenge_criteria'],
            'closing' => $generated['closing'] ?? self::DEFAULT_FALLBACK['closing'],
            'generated_at' => time(),
            'model' => $this->modelName(),
        ];
        $this->upsertMorning($ctx, $date, $morningPayload);

        return $morningPayload;
    }

    public function respond(TenantContext $tenant, AuthenticatedUserContext $auth, array $input): array
    {
        $ctx = $this->context($tenant, $auth);
        $date = date('Y-m-d');
        $daily = $this->daily($ctx, $date);
        if ($daily === null || !is_array($daily['morning_challenge'])) {
            throw new MemberTransactionException(400, 'morning_not_generated', '今日早间内容尚未生成');
        }

        $answers = $input['answers'] ?? [];
        if (!is_array($answers) || count($answers) > 5) {
            throw new MemberTransactionException(400, 'invalid_answers', 'answers 必须是数组且不超过 5 项');
        }
        $challengeResult = (string) ($input['challenge_result'] ?? 'none');
        if (!in_array($challengeResult, ['done', 'partial', 'none'], true)) {
            $challengeResult = 'none';
        }
        $note = trim((string) ($input['note'] ?? ''));
        if ($note !== '' && mb_strlen($note) > 500) {
            throw new MemberTransactionException(400, 'note_too_long', 'note 不能超过 500 字');
        }

        // 最低门槛：只回一个数字也算回传（控速降门槛设计）
        $isMinimal = $answers === [] && $note === '';
        $respondStatus = $isMinimal ? 1 : 2;

        $responses = [
            'answers' => $answers,
            'challenge_result' => $challengeResult,
            'note' => $note,
            'updated_at' => time(),
        ];

        $streak = $this->streakBeforeToday($ctx, $date) + 1;
        $this->upsertResponses($ctx, $date, $responses, $respondStatus, $streak);

        return [
            'respond_status' => $respondStatus,
            'streak' => $streak,
            'responses' => $responses,
            'date' => $date,
        ];
    }

    public function evening(TenantContext $tenant, AuthenticatedUserContext $auth, bool $force = false): array
    {
        $ctx = $this->context($tenant, $auth);
        $date = date('Y-m-d');
        $daily = $this->daily($ctx, $date);
        if ($daily === null || !is_array($daily['morning_challenge'])) {
            throw new MemberTransactionException(400, 'morning_not_generated', '今日早间内容尚未生成');
        }
        if (is_array($daily['evening_review']) && !$force) {
            return $daily['evening_review'];
        }

        // 成本防护：真正调用付费网关前校验每日生成配额（force=true 同样计数）
        $this->assertGenerationQuota($ctx);

        $responses = is_array($daily['responses']) ? $daily['responses'] : [
            'answers' => [],
            'challenge_result' => 'none',
            'note' => '（今日未回传）',
        ];
        $discern = $this->discern($tenant);

        $prompt = new CoachingPrompt();
        $system = $prompt->buildEveningSystem($discern['voice_style']);
        $user = $prompt->buildEveningUser($daily['morning_challenge'], $responses);

        $review = $this->generate($system, $user, $discern['brand_name'], $ctx, 'evening');
        $reviewPayload = [
            'summary' => $review['summary'] ?? '今日辛苦了，复盘记录已存档。',
            'praise' => $review['praise'] ?? '',
            'blocker' => $review['blocker'] ?? '',
            'tomorrow_hint' => $review['tomorrow_hint'] ?? '明天见，小薇继续陪你。',
            'reviewed_at' => time(),
            'model' => $this->modelName(),
        ];
        $this->upsertEvening($ctx, $date, $reviewPayload);

        return $reviewPayload;
    }

    public function status(TenantContext $tenant, AuthenticatedUserContext $auth): array
    {
        $ctx = $this->context($tenant, $auth);
        $date = date('Y-m-d');
        $streak = $this->streak($ctx, $date);
        $threshold = $this->streakThreshold($tenant);

        return [
            'date' => $date,
            'streak' => $streak,
            'threshold' => $threshold,
            'cooldown_mode' => $streak >= $threshold,
            'message' => $streak >= $threshold
                ? '小薇降低门槛啦——今天回一个数字（0-10 今日状态）或一句话就够'
                : '小薇每天 3 条追问，等你的回应',
        ];
    }

    // ---------- private ----------

    /**
     * Redis 原子计数限流：每位会员每天 AI 生成次数（morning+evening 合计，含 force 重新生成）。
     * 超过 coaching.daily_generation_limit 抛 429，防止 force=true 被循环调用刷爆付费网关（成本型攻击）。
     * 计数发生在真正调用网关之前，无论生成成功或降级兜底都计入一次。
     * Redis 不可用时 fail-open（记录告警放行）——限流是成本保护而非数据边界，不能让限流器故障挡住正常会员。
     */
    private function assertGenerationQuota(array $ctx): void
    {
        $limit = (int) Config::get('coaching.daily_generation_limit', 10);
        if ($limit < 1) {
            return;
        }
        $key = self::GENERATION_QUOTA_PREFIX . $ctx['tenant_id'] . ':' . $ctx['member_id'] . ':' . date('Y-m-d');
        try {
            $redis = Cache::store('redis')->handler();
            $count = $redis->incr($key);
            if ($count === 1) {
                // 首次计数：过期时间设为当天 23:59:59，次日自动重置
                $redis->expire($key, (int) strtotime('tomorrow') - time());
            }
            if ($count > $limit) {
                throw new MemberTransactionException(429, 'generation_limit_exceeded', '今日 AI 生成次数已达上限，请明天再来');
            }
        } catch (MemberTransactionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('coaching generation quota check failed, allowing request', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function context(TenantContext $tenant, AuthenticatedUserContext $auth): array
    {
        $member = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid())
            ->find();
        if (!is_array($member)) {
            throw new MemberTransactionException(404, 'member_not_found', 'Member account was not initialized');
        }

        return [
            'tenant_id' => (int) $tenant->tenantId(),
            'channel_id' => (int) $tenant->channelId(),
            'member_id' => (int) $member['id'],
            'uid' => $auth->uid(),
        ];
    }

    private function daily(array $ctx, string $date): ?array
    {
        $row = Db::table('ch_coaching_daily')
            ->where('tenant_id', $ctx['tenant_id'])
            ->where('channel_id', $ctx['channel_id'])
            ->where('member_id', $ctx['member_id'])
            ->where('record_date', $date)
            ->find();
        if (!is_array($row)) {
            return null;
        }
        $row['morning_challenge'] = $this->decodeJson($row['morning_challenge'] ?? null);
        $row['responses'] = $this->decodeJson($row['responses'] ?? null);
        $row['evening_review'] = $this->decodeJson($row['evening_review'] ?? null);

        return $row;
    }

    private function yesterday(array $ctx, string $date): ?array
    {
        $yesterdayDate = date('Y-m-d', strtotime($date . ' -1 day'));
        $row = Db::table('ch_coaching_daily')
            ->where('tenant_id', $ctx['tenant_id'])
            ->where('channel_id', $ctx['channel_id'])
            ->where('member_id', $ctx['member_id'])
            ->where('record_date', $yesterdayDate)
            ->find();
        if (!is_array($row)) {
            return null;
        }
        $row['morning_challenge'] = $this->decodeJson($row['morning_challenge'] ?? null);
        $row['responses'] = $this->decodeJson($row['responses'] ?? null);
        $row['evening_review'] = $this->decodeJson($row['evening_review'] ?? null);

        return $row;
    }

    private function profile(array $ctx): array
    {
        $row = Db::table('ch_coaching_profile')
            ->where('tenant_id', $ctx['tenant_id'])
            ->where('channel_id', $ctx['channel_id'])
            ->where('member_id', $ctx['member_id'])
            ->find();
        if (!is_array($row)) {
            return [];
        }
        unset($row['id'], $row['tenant_id'], $row['channel_id'], $row['member_id'], $row['uid'], $row['status'], $row['add_time'], $row['update_time']);

        return $row;
    }

    private function config(array $ctx): array
    {
        $row = Db::table('ch_coaching_config')
            ->where('tenant_id', $ctx['tenant_id'])
            ->where('channel_id', $ctx['channel_id'])
            ->where('member_id', $ctx['member_id'])
            ->find();
        if (!is_array($row)) {
            return [];
        }

        return $this->decodeJson($row['pillars'] ?? null) ?? [];
    }

    private function discern(TenantContext $tenant): array
    {
        $row = Db::table('ch_discern_config')
            ->where('tenant_id', $tenant->tenantId())
            ->find();
        if (!is_array($row)) {
            return [
                'brand_name' => Config::get('coaching.default_brand_name', '小薇'),
                'voice_style' => '知性温柔、精简、有温度、带点可爱灵气',
                'content' => [],
                'streak_threshold' => (int) Config::get('coaching.default_streak_threshold', 3),
            ];
        }

        return [
            'brand_name' => (string) $row['brand_name'],
            'voice_style' => (string) $row['voice_style'],
            'content' => [
                'four_traits' => $this->decodeJson($row['four_traits'] ?? null),
                'five_principles' => $this->decodeJson($row['five_principles'] ?? null),
                'six_beliefs' => $this->decodeJson($row['six_beliefs'] ?? null),
                'extra' => $this->decodeJson($row['extra'] ?? null),
            ],
            'streak_threshold' => (int) ($row['streak_threshold'] ?: Config::get('coaching.default_streak_threshold', 3)),
        ];
    }

    private function brand(TenantContext $tenant): array
    {
        $discern = $this->discern($tenant);

        return [
            'name' => $discern['brand_name'],
            'voice_style' => $discern['voice_style'],
        ];
    }

    private function streak(array $ctx, string $date): int
    {
        $rows = Db::table('ch_coaching_daily')
            ->where('tenant_id', $ctx['tenant_id'])
            ->where('channel_id', $ctx['channel_id'])
            ->where('member_id', $ctx['member_id'])
            ->where('record_date', '<=', $date)
            ->order('record_date', 'desc')
            ->limit(14)
            ->column('respond_status', 'record_date');
        if ($rows === []) {
            return 0;
        }

        $streak = 0;
        $cursor = $date;
        for ($i = 0; $i < 14; $i++) {
            $status = $rows[$cursor] ?? null;
            if ($status === null) {
                break;
            }
            if ((int) $status === 0) {
                $streak++;
            } else {
                break;
            }
            $cursor = date('Y-m-d', strtotime($cursor . ' -1 day'));
        }

        return $streak;
    }

    private function streakBeforeToday(array $ctx, string $date): int
    {
        $rows = Db::table('ch_coaching_daily')
            ->where('tenant_id', $ctx['tenant_id'])
            ->where('channel_id', $ctx['channel_id'])
            ->where('member_id', $ctx['member_id'])
            ->where('record_date', '<', $date)
            ->order('record_date', 'desc')
            ->limit(14)
            ->column('respond_status', 'record_date');
        if ($rows === []) {
            return 0;
        }

        $streak = 0;
        $cursor = date('Y-m-d', strtotime($date . ' -1 day'));
        for ($i = 0; $i < 14; $i++) {
            $status = $rows[$cursor] ?? null;
            if ($status === null) {
                break;
            }
            if ((int) $status === 0) {
                $streak++;
            } else {
                break;
            }
            $cursor = date('Y-m-d', strtotime($cursor . ' -1 day'));
        }

        return $streak;
    }

    private function streakThreshold(TenantContext $tenant): int
    {
        $discern = $this->discern($tenant);

        return (int) $discern['streak_threshold'];
    }

    private function modelName(): string
    {
        return (string) Config::get('coaching.kaypal_model', 'kaypal-fast');
    }

    private function generate(string $system, string $user, string $brandName, array $ctx, string $scene): array
    {
        $started = microtime(true);
        $usageOut = [];
        try {
            $gateway = new KaypalGateway();
            $raw = $gateway->chat($system, $user, 1600, 0.8, $usageOut);
            $parsed = $this->parseJsonPayload($raw);

            // 服务端权威 usage 计费流水（网关响应带回，不信任客户端）
            $this->recordUsage($ctx, $scene, $usageOut, false, $started);

            return $parsed;
        } catch (RuntimeException $exception) {
            Log::warning('coaching generate failed, fallback used', [
                'brand' => $brandName,
                'scene' => $scene,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            // 网关调用失败也记录一次流水（fallback_used=1，tokens 为空），供成本/故障对账
            $this->recordUsage($ctx, $scene, $usageOut, true, $started);

            // 降级：兜底模板（PRD 6 非功能需求：生成失败时用兜底模板，不影响推送）
            return [
                'questions' => self::DEFAULT_FALLBACK['questions'],
                'micro_optimization' => self::DEFAULT_FALLBACK['micro_optimization'],
                'challenge' => self::DEFAULT_FALLBACK['challenge'],
                'challenge_criteria' => self::DEFAULT_FALLBACK['challenge_criteria'],
                'closing' => self::DEFAULT_FALLBACK['closing'],
                'fallback' => true,
            ];
        }
    }

    /**
     * 记录 AI usage 流水（记录失败不影响主流程）。
     */
    private function recordUsage(array $ctx, string $scene, array $usage, bool $fallback, float $started): void
    {
        (new AiUsageRecorder())->record(
            $ctx,
            $scene,
            $usage,
            $this->modelName(),
            $fallback,
            (int) ((microtime(true) - $started) * 1000)
        );
    }

    private function parseJsonPayload(string $raw): array
    {
        $trimmed = trim($raw);
        // 去掉可能的 markdown 代码块围栏
        $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed);
        $trimmed = preg_replace('/\s*```$/', '', $trimmed);
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            // 尝试提取第一个 { ... } 块
            if (preg_match('/\{.*\}/s', $trimmed, $match)) {
                $decoded = json_decode($match[0], true);
            }
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('coaching model output is not valid JSON');
        }

        return $decoded;
    }

    private function decodeJson($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function upsertMorning(array $ctx, string $date, array $payload): void
    {
        $now = time();
        $existing = $this->daily($ctx, $date);
        if ($existing !== null) {
            Db::table('ch_coaching_daily')
                ->where('id', $existing['id'])
                ->update([
                    'morning_challenge' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'update_time' => $now,
                ]);

            return;
        }

        Db::table('ch_coaching_daily')->insert([
            'tenant_id' => $ctx['tenant_id'],
            'channel_id' => $ctx['channel_id'],
            'member_id' => $ctx['member_id'],
            'uid' => $ctx['uid'],
            'record_date' => $date,
            'morning_challenge' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'responses' => null,
            'evening_review' => null,
            'respond_status' => 0,
            'streak' => 0,
            'status' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);
    }

    private function upsertResponses(array $ctx, string $date, array $payload, int $status, int $streak): void
    {
        $now = time();
        $existing = $this->daily($ctx, $date);
        $data = [
            'responses' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'respond_status' => $status,
            'streak' => $streak,
            'update_time' => $now,
        ];
        if ($existing !== null) {
            Db::table('ch_coaching_daily')->where('id', $existing['id'])->update($data);

            return;
        }
        $data = array_merge([
            'tenant_id' => $ctx['tenant_id'],
            'channel_id' => $ctx['channel_id'],
            'member_id' => $ctx['member_id'],
            'uid' => $ctx['uid'],
            'record_date' => $date,
            'morning_challenge' => null,
            'evening_review' => null,
            'status' => 1,
            'add_time' => $now,
        ], $data);
        Db::table('ch_coaching_daily')->insert($data);
    }

    private function upsertEvening(array $ctx, string $date, array $payload): void
    {
        $now = time();
        $existing = $this->daily($ctx, $date);
        if ($existing !== null) {
            Db::table('ch_coaching_daily')
                ->where('id', $existing['id'])
                ->update([
                    'evening_review' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'update_time' => $now,
                ]);

            return;
        }
        Db::table('ch_coaching_daily')->insert([
            'tenant_id' => $ctx['tenant_id'],
            'channel_id' => $ctx['channel_id'],
            'member_id' => $ctx['member_id'],
            'uid' => $ctx['uid'],
            'record_date' => $date,
            'morning_challenge' => null,
            'responses' => null,
            'evening_review' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'respond_status' => 0,
            'streak' => 0,
            'status' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);
    }
}
