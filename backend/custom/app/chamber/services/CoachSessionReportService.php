<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\activity\CoachSessionReportRequest;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;

/** 教练每课填报（成长改变数据源）。 */
final class CoachSessionReportService
{
    public function create(TenantContext $tenant, int $coachId, CoachSessionReportRequest $report): array
    {
        $now = time();
        $reportId = Db::table('ch_coach_session_report')->insertGetId([
            'tenant_id' => $tenant->tenantId(),
            'channel_id' => $tenant->channelId(),
            'student_id' => $report->studentId(),
            'session_id' => $report->sessionId(),
            'coach_id' => $coachId,
            'comment' => $report->comment(),
            'record_date' => $report->recordDate(),
            'add_time' => $now,
            'update_time' => $now,
        ]);
        $this->insertPhotos($tenant->tenantId(), $reportId, $report->posturePhotos());
        $this->insertTags($tenant->tenantId(), $reportId, $report->personalityTags());
        $this->insertMilestones($tenant->tenantId(), $reportId, $report->milestones());

        return ['id' => $reportId];
    }

    /**
     * @param array{front?:string,back?:string,side?:string} $photos
     */
    private function insertPhotos(int $tenantId, int $reportId, array $photos): void
    {
        $rows = [];
        $order = 0;
        foreach (['front', 'back', 'side'] as $type) {
            if (isset($photos[$type]) && $photos[$type] !== '') {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'report_id' => $reportId,
                    'photo_type' => $type,
                    'url' => $photos[$type],
                    'sort_order' => $order++,
                    'add_time' => time(),
                ];
            }
        }
        if ($rows !== []) {
            Db::table('ch_coach_session_report_photo')->insertAll($rows);
        }
    }

    /**
     * @param array<int,array{key:string,label:string,score:int}> $tags
     */
    private function insertTags(int $tenantId, int $reportId, array $tags): void
    {
        $rows = [];
        foreach ($tags as $tag) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'tag_key' => $tag['key'],
                'tag_label' => $tag['label'],
                'score' => $tag['score'],
                'add_time' => time(),
            ];
        }
        if ($rows !== []) {
            Db::table('ch_coach_session_report_tag')->insertAll($rows);
        }
    }

    /**
     * @param array<int,array{key:string,label:string,achieved:bool}> $milestones
     */
    private function insertMilestones(int $tenantId, int $reportId, array $milestones): void
    {
        $rows = [];
        foreach ($milestones as $m) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'milestone_key' => $m['key'],
                'milestone_label' => $m['label'],
                'achieved' => $m['achieved'] ? 1 : 0,
                'add_time' => time(),
            ];
        }
        if ($rows !== []) {
            Db::table('ch_coach_session_report_milestone')->insertAll($rows);
        }
    }

    /** 学员成长档案（体态前后对比 + 性格标签基线/当前）。 */
    public function growthForStudent(TenantContext $tenant, int $studentId): array
    {
        return $this->buildGrowth($tenant, [$studentId]);
    }

    /** 当前会员（本人 + 家庭子女）的成长档案聚合。 */
    public function growthForMember(TenantContext $tenant, AuthenticatedUserContext $auth): array
    {
        $studentIds = $this->resolveStudentIds($tenant, $auth->uid());
        if ($studentIds === []) {
            return $this->emptyGrowth();
        }

        return $this->buildGrowth($tenant, $studentIds);
    }

    /**
     * @return int[]
     */
    private function resolveStudentIds(TenantContext $tenant, int $uid): array
    {
        $ids = [];
        $self = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $uid)
            ->where('is_del', 0)
            ->where('status', 1)
            ->column('id');
        foreach ($self as $id) {
            $ids[] = (int) $id;
        }
        $children = Db::table('ch_family_member fm')
            ->join('ch_family f', 'f.id = fm.family_id')
            ->where('f.tenant_id', $tenant->tenantId())
            ->where('f.owner_uid', $uid)
            ->where('fm.relation', 'child')
            ->where('fm.status', 1)
            ->column('fm.member_id');
        foreach ($children as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param int[] $studentIds
     */
    private function buildGrowth(TenantContext $tenant, array $studentIds): array
    {
        $reports = Db::table('ch_coach_session_report')
            ->where('tenant_id', $tenant->tenantId())
            ->whereIn('student_id', $studentIds)
            ->order('record_date', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        if ($reports === []) {
            return $this->emptyGrowth();
        }

        $reportIds = array_column($reports, 'id');
        $photos = $this->loadPhotos($tenant->tenantId(), $reportIds);
        $tags = $this->loadTags($tenant->tenantId(), $reportIds);

        $postureRecords = [];
        foreach ($reports as $r) {
            $recPhotos = $photos[(int) $r['id']] ?? ['front' => '', 'back' => '', 'side' => ''];
            $postureRecords[] = [
                'id' => (int) $r['id'],
                'date' => (int) $r['record_date'],
                'photos' => $recPhotos,
            ];
        }

        $baselineTags = $tags[(int) $reports[0]['id']] ?? [];
        $latest = end($reports);
        $currentTags = $tags[(int) $latest['id']] ?? [];
        $updatedAt = (int) $latest['record_date'];

        return [
            'posture_records' => $postureRecords,
            'personality_baseline' => $baselineTags,
            'personality_current' => $currentTags,
            'personality_updated_at' => $updatedAt,
        ];
    }

    /**
     * @param int[] $reportIds
     * @return array<int,array{front?:string,back?:string,side?:string}>
     */
    private function loadPhotos(int $tenantId, array $reportIds): array
    {
        $rows = Db::table('ch_coach_session_report_photo')
            ->where('tenant_id', $tenantId)
            ->whereIn('report_id', $reportIds)
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['report_id']][$row['photo_type']] = $row['url'];
        }

        return $map;
    }

    /**
     * @param int[] $reportIds
     * @return array<int,array<int,array{key:string,label:string,score:int}>>
     */
    private function loadTags(int $tenantId, array $reportIds): array
    {
        $rows = Db::table('ch_coach_session_report_tag')
            ->where('tenant_id', $tenantId)
            ->whereIn('report_id', $reportIds)
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['report_id']][] = [
                'key' => $row['tag_key'],
                'label' => $row['tag_label'],
                'score' => (int) $row['score'],
            ];
        }

        return $map;
    }

    /**
     * @return array{posture_records:array,personality_baseline:array,personality_current:array,personality_updated_at:int}
     */
    private function emptyGrowth(): array
    {
        return [
            'posture_records' => [],
            'personality_baseline' => [],
            'personality_current' => [],
            'personality_updated_at' => 0,
        ];
    }
}
