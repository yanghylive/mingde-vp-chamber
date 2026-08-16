<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\coaching\KaypalGateway;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\tenancy\TenantContext;
use RuntimeException;
use think\facade\Db;
use think\facade\Log;

/**
 * AI 智能分身服务：大咖/会员对话式训练 + 记忆自动沉淀 + 会员对话（积分计费）。
 *
 * 数据表（migration 202608090004）：
 *   ch_expert_ai          分身配置（人设/语气/口头禅/知识库/训练状态/积分价）
 *   ch_expert_ai_memory   记忆条目（对话训练自动提炼，人工可删）
 *   ch_expert_ai_chat     对话记录（train 训练 / member 会员对话）
 *
 * 训练模式：AI 训练师引导大咖描述自己（身份/风格/观点/知识），
 *           每轮对话由「记忆提炼器」抽取可沉淀条目自动入库（去重）。
 * 对话模式：会员与大咖分身对话，注入人设 + 记忆，按 chat_points_cost 扣会员积分（乐观锁 + 账本流水）。
 */
final class AiTwinService
{
    /** 训练对话历史保留轮次（messages 数组元素数上限） */
    private const TRAIN_MAX_HISTORY = 16;

    /** 会员对话历史保留轮次上限 */
    private const MEMBER_MAX_HISTORY = 24;

    /** 注入记忆条数上限 */
    private const MEMORY_INJECT_LIMIT = 12;

    /** 查询 embedding 进程内 LRU 缓存上限 */
    private const EMBEDDING_CACHE_MAX = 50;

    /** @var MemberIdentityService */
    private $identity;

    /** @var KaypalGateway */
    private $gateway;

    /** @var AiUsageRecorder */
    private $usage;

    /** @var array<string, array> 查询 embedding 进程内缓存（key=sha256(query)） */
    private $embeddingCache = [];

    public function __construct(?KaypalGateway $gateway = null, ?AiUsageRecorder $usage = null)
    {
        $this->gateway = $gateway ?: new KaypalGateway();
        $this->usage = $usage ?: new AiUsageRecorder();
        $this->identity = new MemberIdentityService();
    }

    // ------------------------------------------------------------------
    // 分身配置
    // ------------------------------------------------------------------

    /** 取或建分身配置（tenant+member 唯一） */
    public function ensureAi(int $tenantId, int $memberId): array
    {
        $row = Db::table('ch_expert_ai')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->find();
        if (is_array($row)) {
            return $row;
        }

        // 合法性校验：memberId 必须是「真实会员」或「ch_expert 大咖」，否则不建分身
        // （修复：此前对任意 id 都自动建空分身，导致灌垃圾数据 + 花积分对话空壳）
        $isMember = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('id', $memberId)
            ->where('is_del', 0)
            ->find();
        $expert = Db::table('ch_expert')
            ->where('tenant_id', $tenantId)
            ->where('id', $memberId)
            ->find();
        if (!is_array($isMember) && !is_array($expert)) {
            return [];
        }

        // 非会员大咖（仅 ch_expert 资料，未注册会员账号）时，用大咖资料初始化 persona，
        // 让分身「像本人」（修复：此前 persona_name 为空，对话退化成「这位大咖」空壳）
        $personaName = '';
        $personaRole = '';
        if (is_array($expert)) {
            $personaName = (string) $expert['name'];
            $personaRole = $this->roleLabel((string) ($expert['role'] ?? ''));
        }

        $now = time();
        Db::table('ch_expert_ai')->insert([
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
            'persona_name' => $personaName,
            'persona_role' => $personaRole,
            'voice_style' => '自然亲和、简洁有力',
            'catchphrases' => '',
            'knowledge_base' => '',
            'training_status' => 0,
            'training_progress' => 0,
            'chat_points_cost' => 20,
            'chat_count' => 0,
            'status' => 1,
            'is_del' => 0,
            'add_time' => $now,
            'update_time' => $now,
        ]);

        $row = Db::table('ch_expert_ai')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->find();

        return is_array($row) ? $row : [];
    }

    /** 角色 code → 中文名（mentor=导师 / coach=教练 / industry_leader=行业领袖） */
    private function roleLabel(string $code): string
    {
        $map = [
            'mentor' => '导师',
            'coach' => '教练',
            'industry_leader' => '行业领袖',
        ];

        return $map[$code] ?? '';
    }

    /**
     * 统一解析「分身归属的 member_id」。
     * 大咖详情页传的是 ch_expert.id（资料表），而分身按 ch_expert_ai.member_id 归属。
     * 若该大咖已关联会员（ch_expert.member_id > 0），用会员 member_id；否则原样返回
     * （真会员 member_id，或未关联会员的 ch_expert.id 代理）。
     */
    private function resolveTwinMemberId(int $tenantId, int $id): int
    {
        $expert = Db::table('ch_expert')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->find();
        if (is_array($expert) && (int) ($expert['member_id'] ?? 0) > 0) {
            return (int) $expert['member_id'];
        }

        return $id;
    }

    /** 大咖会员档案（ch_member_profile）用于训练上下文；档案为空时回退查 ch_expert 大咖资料 */
    public function memberProfile(int $tenantId, int $memberId): array
    {
        $profile = Db::table('ch_member_profile')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->find();

        if (is_array($profile)) {
            return $profile;
        }

        // 回退：大咖（非会员）资料在 ch_expert 表
        $expert = Db::table('ch_expert')
            ->where('tenant_id', $tenantId)
            ->where('id', $memberId)
            ->find();
        if (!is_array($expert)) {
            return [];
        }

        return [
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
            'uid' => 0,
            'real_name' => (string) ($expert['name'] ?? ''),
            'industry' => (string) ($expert['industry'] ?? ''),
            'company_name' => (string) ($expert['company'] ?? ''),
            'job_title' => (string) ($expert['title'] ?? ''),
            'bio' => (string) ($expert['bio'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------
    // Prompt 构建
    // ------------------------------------------------------------------

    /** 训练师 system prompt：引导大咖描述自己（一次一问，渐进） */
    public function trainSystem(array $ai, array $memories, array $knowledge = []): string
    {
        $name = $ai['persona_name'] !== '' ? $ai['persona_name'] : '这位会员';
        $progress = (int) $ai['training_progress'];

        $memoryText = '';
        if ($memories) {
            $lines = [];
            foreach ($memories as $m) {
                $lines[] = '- [' . $this->categoryLabel($m['category']) . '] ' . $m['content'];
            }
            $memoryText = "\n【目前已掌握的关于" . $name . "的记忆】\n" . implode("\n", $lines) . "\n";
        }

        $knowledgeText = '';
        if ($knowledge) {
            $lines = [];
            foreach ($knowledge as $k) {
                $lines[] = '【' . $k['title'] . '】' . mb_substr($k['content'], 0, 400);
            }
            $knowledgeText = "\n【" . $name . "的知识库材料】（这是对方提供的专业资料，训练时以它为准深入提问，帮助对方把这些知识讲透并沉淀为分身的专业能力）\n" . implode("\n", $lines) . "\n";
        }

        return <<<PROMPT
你是「AI 智能分身训练师」，正在为「{$name}」打造专属 AI 智能分身。
你的目标：通过聊天式对话，逐步引导对方描述自己，让分身学会他/她的：
1. 身份背景（职业、经历、成就）
2. 说话风格（语气、用词习惯、表达方式）
3. 核心观点与理念
4. 专业知识与经验
5. 口头禅与常用表达

【对话规则】
- 一次只问 1 个问题，从身份背景聊起，循序渐进，不要像问卷
- 像朋友闲聊，先共情回应对方的话，再顺势引导下一个话题
- 对方讲完一个方面后，自然过渡到下一个方面
- 当已掌握较完整信息（大约 5-8 轮后），主动说「我已经比较了解你了，我们再来补充一点细节吧」，继续挖掘口头禅、经典语录、为人处事的理念
- 对方说「结束/好了/差不多了」时，总结训练成果并礼貌收尾
- 每次回复控制在 120 字以内，只输出对对方说的话，不要输出任何标记或说明{$memoryText}{$knowledgeText}
【当前训练进度】{$progress}%
PROMPT;
    }

    /** 记忆提炼器 system prompt：从对话中抽取结构化记忆 */
    public function extractSystem(): string
    {
        return <<<'PROMPT'
你是记忆提炼器。从对话中提取关于对方（被训练者）的、可长期沉淀为 AI 分身记忆的信息。
分类（category）：
- identity：身份背景（职业、经历、成就、公司）
- style：说话风格（语气、用词、表达习惯）
- fact：客观事实（关键数字、具体经历）
- knowledge：专业知识、观点、理念、方法论
- preference：偏好、口头禅、常用表达

输出要求：
- 只输出 JSON，格式：{"memories":[{"category":"identity","content":"..."}]}
- content 用第一人称或客观陈述均可，要具体、可复用，避免空泛
- 没有可提炼的信息时输出 {"memories":[]}
- 不要输出 JSON 以外的任何内容
PROMPT;
    }

    /** 分身 system prompt：人设 + 记忆注入（会员对话用） */
    public function twinSystem(array $ai, array $memories, array $knowledge = [], array $profile = []): string
    {
        $name = $ai['persona_name'] !== '' ? $ai['persona_name'] : '这位大咖';
        $role = $ai['persona_role'] !== '' ? $ai['persona_role'] : '资深从业者';
        $voice = $ai['voice_style'] !== '' ? $ai['voice_style'] : '自然亲和、简洁有力';

        $catch = '';
        if ($ai['catchphrases'] !== '') {
            $catch = "\n【口头禅】" . $ai['catchphrases'];
        }

        // 档案注入（S2）：把会员档案里的行业/公司/职位/专长拼进人设，让分身"像本人"
        $profileText = '';
        if (is_array($profile) && $profile) {
            $bits = [];
            if (trim((string) ($profile['industry'] ?? '')) !== '') {
                $bits[] = '行业：' . $profile['industry'];
            }
            if (trim((string) ($profile['company_name'] ?? '')) !== '') {
                $bits[] = '公司：' . $profile['company_name'];
            }
            if (trim((string) ($profile['job_title'] ?? '')) !== '') {
                $bits[] = '职位：' . $profile['job_title'];
            }
            if (trim((string) ($profile['main_business'] ?? '')) !== '') {
                $bits[] = '主营业务：' . $profile['main_business'];
            }
            if (trim((string) ($profile['bio'] ?? '')) !== '') {
                $bits[] = '个人简介：' . mb_substr($profile['bio'], 0, 200);
            }
            if (trim((string) ($profile['expertise_json'] ?? '')) !== '') {
                $exp = json_decode($profile['expertise_json'], true);
                if (is_array($exp) && $exp) {
                    $expLabels = [];
                    foreach ($exp as $x) {
                        if (is_array($x)) {
                            $expLabels[] = (string) ($x['label'] ?? $x['value'] ?? '');
                        } else {
                            $expLabels[] = (string) $x;
                        }
                    }
                    $expLabels = array_values(array_filter($expLabels));
                    if ($expLabels) {
                        $bits[] = '专长：' . implode('、', $expLabels);
                    }
                }
            }
            if ($bits) {
                $profileText = "\n【他的真实档案】（回答时自然体现，让会员感觉你了解他/她）\n" . implode("\n", $bits) . "\n";
            }
        }

        $memoryText = '';
        if ($memories) {
            $lines = [];
            foreach ($memories as $m) {
                $lines[] = '- ' . $m['content'];
            }
            $memoryText = "\n【他的核心记忆】（回答时自然融入，这是你对他/她的了解）\n" . implode("\n", $lines) . "\n";
        }

        $knowledgeText = '';
        if ($knowledge) {
            $lines = [];
            foreach ($knowledge as $k) {
                $lines[] = '【' . $k['title'] . '】' . mb_substr($k['content'], 0, 500);
            }
            $knowledgeText = "\n【他的专业知识库】（会员问到相关话题时，以这些材料为准给出专业、具体的回答）\n" . implode("\n", $lines) . "\n";
        }

        return <<<PROMPT
你是「{$name}」的 AI 智能分身——{$role}。你模拟他/她的真实形象，与商会会员对话。

【人设】
- 身份定位：{$role}
- 说话语气：{$voice}{$catch}

{$profileText}
{$memoryText}
{$knowledgeText}
【对话规则】
- 以他/她的身份、语气、观点回答，不要自称是 AI，不要暴露分身身份
- 回答基于上述记忆和知识库，记忆/知识之外的话题坦诚说「这个话题我不太擅长，建议你直接和我本人约时间聊」
- 回答简洁有力（150 字以内），像真人微信聊天，不要用列表轰炸
- 不编造他/她没有说过的事实和经历
- 涉及金钱转账、个人隐私、投资承诺等敏感话题，委婉引导线下当面沟通
PROMPT;
    }

    // ------------------------------------------------------------------
    // 训练对话（大咖端）
    // ------------------------------------------------------------------

    /**
     * 训练对话：大咖跟训练师聊 → 存 train 对话 → 自动提炼记忆入库。
     *
     * @return array ['reply' => string, 'memories_added' => int, 'progress' => int, 'trained' => bool]
     */
    public function train(TenantContext $tenant, AuthenticatedUserContext $auth, string $message): array
    {
        $member = $this->identity->resolve($tenant, $auth);
        $memberId = (int) $member['id'];
        $tenantId = $tenant->tenantId();
        $ai = $this->ensureAi($tenantId, $memberId);
        $aiId = (int) $ai['id'];

        // 历史（train 类型，最近一次对话）
        $history = $this->lastHistory($tenantId, $aiId, 'train', self::TRAIN_MAX_HISTORY);

        // 已记忆
        $memories = $this->memories($tenantId, $memberId, self::MEMORY_INJECT_LIMIT);

        $profile = $this->memberProfile($tenantId, $memberId);
        if (trim((string) ($profile['real_name'] ?? '')) !== '' && $ai['persona_name'] === '') {
            Db::table('ch_expert_ai')->where('id', $aiId)->update([
                'persona_name' => (string) $profile['real_name'],
                'update_time' => time(),
            ]);
            $ai['persona_name'] = (string) $profile['real_name'];
        }

        $system = $this->trainSystem($ai, $memories, $this->knowledgeList($tenantId, $memberId, 8));
        $usage = [];
        try {
            $reply = $this->gateway->chatWithHistory($system, $history, $message, 1200, 0.8, $usage);
        } catch (RuntimeException $e) {
            Log::warning('ai_twin.train gateway error', ['err' => $e->getMessage()]);
            throw new MemberTransactionException(502, 'ai_gateway_error', 'AI 服务暂不可用，请稍后再试');
        }

        // 追加本轮消息
        $history[] = ['role' => 'user', 'content' => $message];
        $history[] = ['role' => 'assistant', 'content' => $reply];
        $history = array_slice($history, -self::TRAIN_MAX_HISTORY);

        $now = time();
        $chatId = (int) Db::table('ch_expert_ai_chat')->insertGetId([
            'tenant_id' => $tenantId,
            'expert_id' => $aiId,
            'member_id' => $memberId,
            'chat_type' => 'train',
            'user_id' => $memberId,
            'messages' => json_encode($history, JSON_UNESCAPED_UNICODE),
            'message_count' => count($history),
            'status' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);

        // 记忆提炼（独立调用，失败不阻断对话）
        $added = 0;
        try {
            $added = $this->extractAndStore($tenantId, $aiId, $memberId, $chatId, $message, $reply);
        } catch (RuntimeException $e) {
            Log::warning('ai_twin.extract error', ['err' => $e->getMessage()]);
        }

        // 训练进度：按累计训练轮数推进（封顶 100）
        $trainCount = (int) Db::table('ch_expert_ai_chat')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', $aiId)
            ->where('chat_type', 'train')
            ->count();
        $progress = min(100, 15 + $trainCount * 10);
        $trainingStatus = $progress >= 100 ? 2 : 1;
        Db::table('ch_expert_ai')->where('id', $aiId)->update([
            'training_progress' => $progress,
            'training_status' => $trainingStatus,
            'update_time' => $now,
        ]);

        $this->recordUsage(['tenant_id' => $tenantId, 'member_id' => $memberId], 'ai_twin_train', $usage);

        return [
            'reply' => $reply,
            'memories_added' => $added,
            'progress' => $progress,
            'trained' => $trainingStatus === 2,
            'memory_count' => count($this->memories($tenantId, $memberId)),
        ];
    }

    // ------------------------------------------------------------------
    // 会员对话（跟大咖分身，扣积分）
    // ------------------------------------------------------------------

    /**
     * 会员与大咖分身对话：校验大咖 → 注入人设+记忆 → AI 回复 → 扣积分 + 存对话。
     *
     * @param int $expertMemberId 目标大咖的 ch_tenant_member.id
     */
    public function chat(TenantContext $tenant, AuthenticatedUserContext $auth, int $expertMemberId, string $message): array
    {
        $member = $this->identity->resolve($tenant, $auth);
        $memberId = (int) $member['id'];
        $tenantId = $tenant->tenantId();

        $twinMemberId = $this->resolveTwinMemberId($tenantId, $expertMemberId);

        if ($twinMemberId === $memberId) {
            throw new MemberTransactionException(422, 'self_chat_not_allowed', '不能和自己的分身对话，请去训练它');
        }

        $ai = $this->ensureAi($tenantId, $twinMemberId);
        if (!$ai) {
            throw new MemberTransactionException(404, 'expert_not_found', '大咖不存在或尚未开通 AI 分身');
        }
        $aiId = (int) $ai['id'];
        $cost = max(1, (int) $ai['chat_points_cost']);

        // 大咖名（展示）
        $expertName = $ai['persona_name'] !== '' ? $ai['persona_name'] : '大咖#' . $twinMemberId;

        // 1. 预校验余额（快速失败，避免无谓上游调用）
        $account = $this->identity->pointsAccount($tenantId, $member, true);
        if ((int) $account['balance'] < $cost) {
            throw new MemberTransactionException(409, 'insufficient_points', '积分不足，需要 ' . $cost . ' 积分');
        }

        $now = time();
        // 2. 先扣积分（乐观锁 + 账本）。改为「先扣后调」：AI 失败再退，避免
        //    「先调 AI 成功后再扣积分」导致的重复请求重复消耗上游成本。
        $deductKey = hash('sha256', 'ai_twin_chat:' . $tenantId . ':' . $memberId . ':' . bin2hex(random_bytes(8)));
        Db::transaction(function () use ($tenant, $member, $cost, $expertName, $tenantId, $memberId, $now, $deductKey) {
            $account = $this->identity->pointsAccount($tenantId, $member, true);
            $balance = (int) $account['balance'];
            if ($balance < $cost) {
                throw new MemberTransactionException(409, 'insufficient_points', '积分不足，需要 ' . $cost . ' 积分');
            }

            $newBalance = $balance - $cost;
            $updated = Db::table('ch_point_account')
                ->where('id', (int) $account['id'])
                ->where('tenant_id', $tenantId)
                ->where('version', (int) $account['version'])
                ->update([
                    'balance' => $newBalance,
                    'version' => (int) $account['version'] + 1,
                    'update_time' => $now,
                ]);
            if (!$updated) {
                throw new MemberTransactionException(409, 'points_conflict', '积分账户已变动，请重试');
            }

            Db::table('ch_point_ledger')->insert([
                'tenant_id' => $tenantId,
                'account_id' => (int) $account['id'],
                'member_id' => $memberId,
                'uid' => (int) $member['uid'],
                'delta' => -$cost,
                'balance_after' => $newBalance,
                'source_type' => 'ai_twin_chat',
                'source_id' => '0',
                'remark' => '与大咖「' . $expertName . '」AI 分身对话',
                'idempotency_key' => $deductKey,
                'status' => 1,
                'reversal_id' => 0,
                'add_time' => $now,
            ]);
        });

        // 3. 调 AI（长耗时，放在事务外）
        $memories = $this->memories($tenantId, $twinMemberId, self::MEMORY_INJECT_LIMIT);
        $expertProfile = $this->memberProfile($tenantId, $twinMemberId);
        $system = $this->twinSystem($ai, $memories, $this->relevantKnowledge($tenantId, $aiId, $message, 5), $expertProfile);
        $history = $this->lastHistory($tenantId, $aiId, 'member', self::MEMBER_MAX_HISTORY);

        $usage = [];
        try {
            $reply = $this->gateway->chatWithHistory($system, $history, $message, 1000, 0.7, $usage);
        } catch (RuntimeException $e) {
            Log::warning('ai_twin.chat gateway error', ['err' => $e->getMessage()]);
            $this->refundChatPoints($member, $tenantId, $cost, $now, $deductKey);
            throw new MemberTransactionException(502, 'ai_gateway_error', 'AI 服务暂不可用，请稍后再试');
        }

        // 4. 存对话 + 关联账本 source_id + chat_count（事务）
        $history[] = ['role' => 'user', 'content' => $message];
        $history[] = ['role' => 'assistant', 'content' => $reply];
        $history = array_slice($history, -self::MEMBER_MAX_HISTORY);

        $chatId = null;
        $finalBalance = null;
        Db::transaction(function () use ($tenant, $member, $twinMemberId, $tenantId, $aiId, $history, $now, $memberId, $deductKey, &$chatId, &$finalBalance) {
            $chatId = (int) Db::table('ch_expert_ai_chat')->insertGetId([
                'tenant_id' => $tenantId,
                'expert_id' => $aiId,
                'member_id' => $twinMemberId,
                'chat_type' => 'member',
                'user_id' => $memberId,
                'messages' => json_encode($history, JSON_UNESCAPED_UNICODE),
                'message_count' => count($history),
                'status' => 1,
                'add_time' => $now,
                'update_time' => $now,
            ]);

            // 账本 source_id 关联对话 id，对账可追溯
            Db::table('ch_point_ledger')
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $deductKey)
                ->update(['source_id' => (string) $chatId]);

            Db::table('ch_expert_ai')->where('id', $aiId)->update([
                'chat_count' => Db::raw('chat_count + 1'),
                'update_time' => $now,
            ]);

            $account = $this->identity->pointsAccount($tenantId, $member, true);
            $finalBalance = (int) $account['balance'];
        });

        $this->recordUsage(['tenant_id' => $tenantId, 'member_id' => $memberId], 'ai_twin_chat', $usage);

        return [
            'reply' => $reply,
            'cost' => $cost,
            'balance' => (int) $finalBalance,
            'chat_id' => (int) $chatId,
        ];
    }

    /** AI 调用失败后退回已扣积分（乐观锁 + reversal 账本）。退款失败仅记日志，不吞原异常。 */
    private function refundChatPoints(array $member, int $tenantId, int $cost, int $now, string $deductKey): void
    {
        $memberId = (int) $member['id'];
        try {
            Db::transaction(function () use ($member, $tenantId, $memberId, $cost, $now, $deductKey) {
                $account = $this->identity->pointsAccount($tenantId, $member, true);
                $balance = (int) $account['balance'];
                $newBalance = $balance + $cost;
                $updated = Db::table('ch_point_account')
                    ->where('id', (int) $account['id'])
                    ->where('tenant_id', $tenantId)
                    ->where('version', (int) $account['version'])
                    ->update([
                        'balance' => $newBalance,
                        'version' => (int) $account['version'] + 1,
                        'update_time' => $now,
                    ]);
                if (!$updated) {
                    throw new MemberTransactionException(409, 'points_conflict', '积分账户已变动，退款失败');
                }

                Db::table('ch_point_ledger')->insert([
                    'tenant_id' => $tenantId,
                    'account_id' => (int) $account['id'],
                    'member_id' => $memberId,
                    'uid' => (int) $member['uid'],
                    'delta' => $cost,
                    'balance_after' => $newBalance,
                    'source_type' => 'ai_twin_chat_refund',
                    'source_id' => '0',
                    'remark' => 'AI 对话失败退款',
                    'idempotency_key' => hash('sha256', 'ai_twin_chat_refund:' . $tenantId . ':' . $memberId . ':' . $deductKey),
                    'status' => 1,
                    'reversal_id' => 0,
                    'add_time' => $now,
                ]);
            });
        } catch (Throwable $e) {
            Log::warning('ai_twin.chat refund failed', ['deduct_key' => $deductKey, 'err' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------

    /** 我的分身状态（大咖端） */
    public function myTwin(TenantContext $tenant, AuthenticatedUserContext $auth): array
    {
        $member = $this->identity->resolve($tenant, $auth);
        $memberId = (int) $member['id'];
        $tenantId = $tenant->tenantId();
        $ai = $this->ensureAi($tenantId, $memberId);

        $memoryCount = (int) Db::table('ch_expert_ai_memory')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', (int) $ai['id'])
            ->where('status', 1)
            ->count();
        $chatCount = (int) Db::table('ch_expert_ai_chat')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', (int) $ai['id'])
            ->count();

        return [
            'ai_id' => (int) $ai['id'],
            'persona_name' => (string) $ai['persona_name'],
            'persona_role' => (string) $ai['persona_role'],
            'voice_style' => (string) $ai['voice_style'],
            'catchphrases' => (string) $ai['catchphrases'],
            'training_status' => (int) $ai['training_status'],
            'training_progress' => (int) $ai['training_progress'],
            'chat_points_cost' => (int) $ai['chat_points_cost'],
            'chat_count' => (int) $ai['chat_count'],
            'memory_count' => $memoryCount,
            'train_chat_count' => $chatCount,
        ];
    }

    /** 分身公开信息（会员对话前查看） */
    public function twinProfile(int $tenantId, int $expertMemberId): array
    {
        $twinMemberId = $this->resolveTwinMemberId($tenantId, $expertMemberId);
        $ai = $this->ensureAi($tenantId, $twinMemberId);

        // 隐私：不返回训练记忆原文，只返回数量（原文仅服务端注入 AI 时使用）
        $memoryCount = (int) Db::table('ch_expert_ai_memory')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', (int) $ai['id'])
            ->where('status', 1)
            ->count();

        return [
            'persona_name' => (string) $ai['persona_name'],
            'persona_role' => (string) $ai['persona_role'],
            'voice_style' => (string) $ai['voice_style'],
            'training_status' => (int) $ai['training_status'],
            'training_progress' => (int) $ai['training_progress'],
            'chat_points_cost' => (int) $ai['chat_points_cost'],
            'memory_count' => $memoryCount,
        ];
    }

    /** 分身就绪度打分（S2）：档案完整度 + 知识库 + 训练进度 → 0-100 */
    public function twinReadiness(int $tenantId, int $memberId): array
    {
        $twinMemberId = $this->resolveTwinMemberId($tenantId, $memberId);
        $ai = $this->ensureAi($tenantId, $twinMemberId);
        $profile = $this->memberProfile($tenantId, $twinMemberId);

        $profileFields = ['industry', 'company_name', 'job_title', 'main_business', 'bio', 'expertise_json'];
        $filled = 0;
        foreach ($profileFields as $f) {
            if (trim((string) ($profile[$f] ?? '')) !== '') {
                $filled++;
            }
        }
        $profileScore = (int) round(($filled / count($profileFields)) * 50);

        $knowledgeCount = (int) Db::table('ch_expert_ai_knowledge')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', (int) $ai['id'])
            ->where('status', 1)
            ->count();
        $knowledgeScore = min(30, (int) $knowledgeCount * 3);

        $memoryCount = (int) Db::table('ch_expert_ai_memory')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', (int) $ai['id'])
            ->where('status', 1)
            ->count();
        $memoryScore = min(20, (int) $memoryCount * 2);

        $score = min(100, $profileScore + $knowledgeScore + $memoryScore);

        $tips = [];
        if ($profileScore < 30) {
            $tips[] = '补全行业/公司/职位/专长档案，分身说话更真实';
        }
        if ($knowledgeCount < 5) {
            $tips[] = '上传 5 篇以上资料，分身能回答专业问题';
        }
        if ($memoryCount < 3) {
            $tips[] = '多和训练师聊几次，分身记住你的经历';
        }

        return [
            'score' => $score,
            'profile_score' => $profileScore,
            'knowledge_count' => $knowledgeCount,
            'memory_count' => $memoryCount,
            'tips' => $tips,
        ];
    }

    /** 记忆列表（大咖本人 / admin） */
    public function memories(int $tenantId, int $memberId, int $limit = 100): array
    {
        $ai = $this->ensureAi($tenantId, $memberId);
        $rows = Db::table('ch_expert_ai_memory')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', (int) $ai['id'])
            ->where('status', 1)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id' => (int) $r['id'],
                'category' => (string) $r['category'],
                'category_label' => $this->categoryLabel((string) $r['category']),
                'content' => (string) $r['content'],
                'source' => (string) $r['source'],
                'source_chat_id' => (int) $r['source_chat_id'],
                'add_time' => (int) $r['add_time'],
            ];
        }

        return $items;
    }

    /** 删除记忆（软删） */
    public function deleteMemory(int $tenantId, int $memberId, int $memoryId): void
    {
        $ai = $this->ensureAi($tenantId, $memberId);
        $deleted = Db::table('ch_expert_ai_memory')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', (int) $ai['id'])
            ->where('id', $memoryId)
            ->update(['status' => 0, 'update_time' => time()]);
        if (!$deleted) {
            throw new MemberTransactionException(404, 'memory_not_found', '记忆不存在');
        }
    }

    // ------------------------------------------------------------------
    // 知识库（L2 知识层：文档/方法论沉淀，训练与对话按相关性注入）
    // ------------------------------------------------------------------

    /** 知识库列表 */
    public function knowledgeList(int $tenantId, int $memberId, int $limit = 100): array
    {
        $ai = $this->ensureAi($tenantId, $memberId);
        $rows = Db::table('ch_expert_ai_knowledge')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', (int) $ai['id'])
            ->where('status', 1)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'content' => (string) $r['content'],
                'category' => (string) $r['category'],
                'source' => (string) $r['source'],
                'source_file' => (string) $r['source_file'],
                'add_time' => (int) $r['add_time'],
            ];
        }

        return $items;
    }

    /** 新增知识条目（title 必填，content 必填） */
    public function knowledgeAdd(int $tenantId, int $memberId, array $input): array
    {
        $ai = $this->ensureAi($tenantId, $memberId);
        $title = trim((string) ($input['title'] ?? ''));
        $content = trim((string) ($input['content'] ?? ''));
        if ($title === '' || $content === '') {
            throw new MemberTransactionException(422, 'knowledge_invalid', '标题和内容不能为空');
        }
        if (mb_strlen($content) > 20000) {
            throw new MemberTransactionException(413, 'knowledge_too_large', '单条知识过长（限 2 万字）');
        }

        $category = in_array((string) ($input['category'] ?? 'general'), ['general', 'industry', 'qa', 'experience'], true)
            ? (string) $input['category']
            : 'general';

        $now = time();
        $id = (int) Db::table('ch_expert_ai_knowledge')->insertGetId([
            'tenant_id' => $tenantId,
            'expert_id' => (int) $ai['id'],
            'member_id' => $memberId,
            'title' => $title,
            'content' => $content,
            'category' => $category,
            'source' => (string) ($input['source'] ?? 'manual'),
            'source_file' => trim((string) ($input['source_file'] ?? '')),
            'status' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);

        // 向量化（kaypal-embedding，384 维）。失败降级：embedding 为空 → 检索只走 BM25。
        $this->vectorizeKnowledge((int) $id, $title, $content);

        return ['id' => $id];
    }

    /**
     * 为知识条目生成 embedding 并回填（独立失败不阻断添加流程，网关偶发超时重试一次）。
     */
    private function vectorizeKnowledge(int $knowledgeId, string $title, string $content): void
    {
        $attempts = 2;
        for ($i = 1; $i <= $attempts; ++$i) {
            try {
                // 添加知识是低频 admin 操作，给足超时（usage 走引用参数，需临时变量）
                $usage = null;
                $vectors = $this->gateway->embed($title . "\n" . $content, $usage, 30);
                $vec = $vectors[0] ?? [];
                if (!$vec) {
                    Log::warning('ai_twin.knowledge embed empty', ['knowledge_id' => $knowledgeId]);

                    return;
                }
                Db::table('ch_expert_ai_knowledge')->where('id', $knowledgeId)->update([
                    'embedding' => json_encode($vec, JSON_UNESCAPED_UNICODE),
                    'embed_dim' => count($vec),
                    'update_time' => time(),
                ]);

                return;
            } catch (\Throwable $e) {
                Log::warning('ai_twin.knowledge embed attempt failed', [
                    'knowledge_id' => $knowledgeId,
                    'attempt' => $i,
                    'err' => $e->getMessage(),
                ]);
            }
        }
    }

    /** 删除知识条目（软删） */
    public function knowledgeDelete(int $tenantId, int $memberId, int $knowledgeId): void
    {
        $ai = $this->ensureAi($tenantId, $memberId);
        $deleted = Db::table('ch_expert_ai_knowledge')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', (int) $ai['id'])
            ->where('id', $knowledgeId)
            ->update(['status' => 0, 'update_time' => time()]);
        if (!$deleted) {
            throw new MemberTransactionException(404, 'knowledge_not_found', '知识条目不存在');
        }
    }

    /**
     * 按相关性检索知识条目（BM25 + 向量余弦 的 RRF 融合，借鉴 TencentDB Agent Memory 检索策略）。
     *
     * - BM25 风格：中文 2-gram + 英文分词，词频加权（字面匹配）
     * - 向量：kaypal-embedding 余弦相似度（语义匹配：意思相近但用词不同也能命中）
     * - RRF 融合：rank = Σ 1/(60 + rank_i)，取 top-N；单路失败自动降级
     * 避免全量注入撑爆上下文，只挑与当前问题最相关的知识。
     */
    public function relevantKnowledge(int $tenantId, int $aiId, string $query, int $limit = 5): array
    {
        $rows = Db::table('ch_expert_ai_knowledge')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', $aiId)
            ->where('status', 1)
            ->order('id', 'desc')
            ->limit(200)
            ->select()
            ->toArray();
        if (!$rows) {
            return [];
        }

        $tokens = $this->tokenize($query);
        if (!$tokens) {
            return array_slice($rows, 0, $limit);
        }

        // ---- 路 1：BM25 字面得分 ----
        $bmScores = [];
        foreach ($rows as $r) {
            $haystack = mb_strtolower($r['title'] . "\n" . $r['content']);
            $score = 0;
            foreach ($tokens as $tok) {
                $score += mb_substr_count($haystack, $tok) * (mb_strlen($tok) >= 2 ? 2 : 1);
            }
            if ($score > 0) {
                $bmScores[(int) $r['id']] = $score;
            }
        }

        // ---- 路 2：向量余弦（语义）----
        $vecScores = [];
        try {
            $qvec = $this->embeddingFor($query);
            if ($qvec) {
                foreach ($rows as $r) {
                    $emb = $this->decodeEmbedding((string) ($r['embedding'] ?? ''));
                    if ($emb) {
                        $sim = $this->cosineSimilarity($qvec, $emb);
                        if ($sim > 0) {
                            $vecScores[(int) $r['id']] = $sim;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ai_twin.knowledge vector recall failed, fallback bm25', ['err' => $e->getMessage()]);
        }

        // ---- RRF 融合 ----
        $ids = array_unique(array_merge(array_keys($bmScores), array_keys($vecScores)));
        $rrf = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            $score = 0.0;
            if (isset($bmScores[$id])) {
                $score += 1 / (60 + $this->rankOf($bmScores, $id));
            }
            if (isset($vecScores[$id])) {
                $score += 1 / (60 + $this->rankOf($vecScores, $id));
            }
            $rrf[$id] = $score;
        }
        arsort($rrf);

        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }

        $items = [];
        foreach (array_slice(array_keys($rrf), 0, $limit) as $id) {
            $r = $byId[(int) $id] ?? null;
            if ($r === null) {
                continue;
            }
            $items[] = [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'content' => (string) $r['content'],
                'category' => (string) $r['category'],
            ];
        }

        return $items;
    }

    /** 问题向量化（失败返回 null，调用方降级纯 BM25；短超时避免拖慢对话） */
    private function embeddingFor(string $text): ?array
    {
        $key = hash('sha256', $text);
        if (isset($this->embeddingCache[$key])) {
            return $this->embeddingCache[$key];
        }

        $usage = null;
        $vectors = $this->gateway->embed($text, $usage, 8);
        $vec = $vectors[0] ?? [];
        if ($vec) {
            // 近似 LRU：超上限淘汰最早写入
            if (count($this->embeddingCache) >= self::EMBEDDING_CACHE_MAX) {
                array_shift($this->embeddingCache);
            }
            $this->embeddingCache[$key] = $vec;
        }

        return $vec ? $vec : null;
    }

    /** 解码存储的 embedding JSON */
    private function decodeEmbedding(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) && $data ? array_map('floatval', $data) : null;
    }

    /** 余弦相似度 */
    private function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $ai = (float) $a[$i];
            $bi = (float) $b[$i];
            $dot += $ai * $bi;
            $na += $ai * $ai;
            $nb += $bi * $bi;
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }

    /** 元素在降序得分表中的名次（1 起） */
    private function rankOf(array $scores, int $id): int
    {
        arsort($scores);
        $rank = 1;
        foreach ($scores as $k => $v) {
            if ((int) $k === $id) {
                return $rank;
            }
            ++$rank;
        }

        return PHP_INT_MAX;
    }

    /** 轻量分词：中文 2-gram + 英文按空格词（转小写） */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return [];
        }

        $tokens = [];
        // 英文/数字词
        if (preg_match_all('/[a-z0-9]{2,}/', $text, $m)) {
            $tokens = array_merge($tokens, $m[0]);
        }
        // 中文 2-gram
        $cn = preg_replace('/[^\x{4e00}-\x{9fa5}]/u', '', $text);
        if (mb_strlen($cn) >= 2) {
            $len = mb_strlen($cn);
            for ($i = 0; $i < $len - 1; $i++) {
                $tokens[] = mb_substr($cn, $i, 2);
            }
        }

        return array_values(array_unique($tokens));
    }

    /** 训练对话历史（回放） */
    public function trainHistory(int $tenantId, int $memberId): array
    {
        $ai = $this->ensureAi($tenantId, $memberId);
        $rows = Db::table('ch_expert_ai_chat')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', (int) $ai['id'])
            ->where('chat_type', 'train')
            ->order('id', 'desc')
            ->limit(50)
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id' => (int) $r['id'],
                'messages' => json_decode((string) $r['messages'], true) ?: [],
                'message_count' => (int) $r['message_count'],
                'add_time' => (int) $r['add_time'],
            ];
        }

        return $items;
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /** 最近一段对话历史（同一类型） */
    private function lastHistory(int $tenantId, int $aiId, string $chatType, int $maxLen): array
    {
        $row = Db::table('ch_expert_ai_chat')
            ->where('tenant_id', $tenantId)
            ->where('expert_id', $aiId)
            ->where('chat_type', $chatType)
            ->order('id', 'desc')
            ->find();
        if (!is_array($row)) {
            return [];
        }

        $messages = json_decode((string) $row['messages'], true);

        return is_array($messages) ? array_slice($messages, -$maxLen) : [];
    }

    /** 提炼并存储记忆（去重：相同 content 不重复入库） */
    private function extractAndStore(int $tenantId, int $aiId, int $memberId, int $chatId, string $userMsg, string $aiReply): int
    {
        $prompt = "用户说：{$userMsg}\n\n训练师回应：{$aiReply}\n\n请提炼关于用户（被训练者）的记忆。";
        $usage = [];
        $raw = $this->gateway->chat($this->extractSystem(), $prompt, 600, 0.2, $usage);
        $this->recordUsage(['tenant_id' => $tenantId, 'member_id' => $memberId], 'ai_twin_extract', $usage);

        $data = json_decode($raw, true);
        $list = (is_array($data) && isset($data['memories']) && is_array($data['memories'])) ? $data['memories'] : [];
        if (!$list) {
            return 0;
        }

        $validCats = ['identity', 'style', 'fact', 'knowledge', 'preference'];
        $added = 0;
        $now = time();
        foreach ($list as $item) {
            $content = trim((string) ($item['content'] ?? ''));
            if ($content === '' || mb_strlen($content) > 500) {
                continue;
            }
            $category = in_array((string) ($item['category'] ?? ''), $validCats, true)
                ? (string) $item['category']
                : 'fact';

            $exists = Db::table('ch_expert_ai_memory')
                ->where('tenant_id', $tenantId)
                ->where('expert_id', $aiId)
                ->where('content', $content)
                ->find();
            if (is_array($exists)) {
                continue;
            }

            Db::table('ch_expert_ai_memory')->insert([
                'tenant_id' => $tenantId,
                'expert_id' => $aiId,
                'member_id' => $memberId,
                'category' => $category,
                'content' => $content,
                'source' => 'train',
                'source_chat_id' => $chatId,
                'status' => 1,
                'add_time' => $now,
                'update_time' => $now,
            ]);
            ++$added;
        }

        return $added;
    }

    private function categoryLabel(string $category): string
    {
        $map = [
            'identity' => '身份背景',
            'style' => '说话风格',
            'fact' => '事实经历',
            'knowledge' => '知识观点',
            'preference' => '偏好口头禅',
        ];

        return $map[$category] ?? '记忆';
    }

    private function recordUsage(array $ctx, string $scene, array $usage): void
    {
        try {
            $this->usage->record(
                $ctx,
                $scene,
                $usage,
                'kaypal-fast',
                false,
                0
            );
        } catch (\Throwable $e) {
            Log::warning('ai_twin usage record failed', ['err' => $e->getMessage()]);
        }
    }
}
