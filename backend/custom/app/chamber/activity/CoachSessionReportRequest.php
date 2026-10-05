<?php

declare(strict_types=1);

namespace app\chamber\activity;

use InvalidArgumentException;

/**
 * 教练每课填报请求校验。
 * 契约对应前端 M10 规格（docs/modules/M10-成长改变.md §3.1）与 UI 组件 PostureCompare / PersonalityTags。
 */
final class CoachSessionReportRequest
{
    /** @var int */
    private $studentId;

    /** @var int */
    private $sessionId;

    /** @var string */
    private $comment;

    /** @var int */
    private $recordDate;

    /** @var array{front?:string,back?:string,side?:string} */
    private $posturePhotos;

    /** @var array<int,array{key:string,label?:string,score?:int}> */
    private $personalityTags;

    /** @var array<int,array{key:string,label?:string,achieved?:bool}> */
    private $milestones;

    /**
     * @param array{front?:string,back?:string,side?:string} $posturePhotos
     * @param array<int,array{key:string,label?:string,score?:int}> $personalityTags
     * @param array<int,array{key:string,label?:string,achieved?:bool}> $milestones
     */
    public function __construct(
        int $studentId,
        int $sessionId,
        string $comment,
        int $recordDate,
        array $posturePhotos,
        array $personalityTags,
        array $milestones
    ) {
        $this->studentId = $studentId;
        $this->sessionId = $sessionId;
        $this->comment = $comment;
        $this->recordDate = $recordDate;
        $this->posturePhotos = $posturePhotos;
        $this->personalityTags = $personalityTags;
        $this->milestones = $milestones;
    }

    public static function fromArray(array $data): self
    {
        $studentId = self::positiveInt($data, 'student_id');
        $sessionId = self::positiveInt($data, 'session_id');
        $comment = isset($data['comment']) && is_string($data['comment'])
            ? mb_substr($data['comment'], 0, 500) : '';
        $recordDate = self::positiveIntOrNow($data, 'record_date');
        $posturePhotos = self::photos($data);
        $personalityTags = self::tagList($data, 'personality_tags');
        $milestones = self::milestoneList($data, 'milestones');

        return new self($studentId, $sessionId, $comment, $recordDate, $posturePhotos, $personalityTags, $milestones);
    }

    private static function positiveInt(array $data, string $field): int
    {
        if (!isset($data[$field]) || !is_numeric($data[$field]) || (int) $data[$field] <= 0) {
            throw new InvalidArgumentException('coach_session_report.' . $field . ' must be a positive integer');
        }

        return (int) $data[$field];
    }

    private static function positiveIntOrNow(array $data, string $field): int
    {
        if (!isset($data[$field]) || !is_numeric($data[$field]) || (int) $data[$field] <= 0) {
            return time();
        }

        return (int) $data[$field];
    }

    /**
     * @return array{front?:string,back?:string,side?:string}
     */
    private static function photos(array $data): array
    {
        $raw = $data['posture_photos'] ?? [];
        if (!is_array($raw)) {
            throw new InvalidArgumentException('coach_session_report.posture_photos must be an object');
        }
        $out = [];
        foreach (['front', 'back', 'side'] as $type) {
            if (isset($raw[$type]) && is_string($raw[$type]) && $raw[$type] !== '') {
                $out[$type] = $raw[$type];
            }
        }

        return $out;
    }

    /**
     * @return array<int,array{key:string,label:string,score:int}>
     */
    private static function tagList(array $data, string $field): array
    {
        $raw = $data[$field] ?? [];
        if (!is_array($raw)) {
            throw new InvalidArgumentException('coach_session_report.' . $field . ' must be an array');
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['key']) || !is_string($item['key']) || $item['key'] === '') {
                continue;
            }
            $out[] = [
                'key' => $item['key'],
                'label' => isset($item['label']) && is_string($item['label']) ? $item['label'] : $item['key'],
                'score' => isset($item['score']) && is_numeric($item['score']) ? (int) $item['score'] : 0,
            ];
        }

        return $out;
    }

    /**
     * @return array<int,array{key:string,label:string,achieved:bool}>
     */
    private static function milestoneList(array $data, string $field): array
    {
        $raw = $data[$field] ?? [];
        if (!is_array($raw)) {
            throw new InvalidArgumentException('coach_session_report.' . $field . ' must be an array');
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['key']) || !is_string($item['key']) || $item['key'] === '') {
                continue;
            }
            $out[] = [
                'key' => $item['key'],
                'label' => isset($item['label']) && is_string($item['label']) ? $item['label'] : $item['key'],
                'achieved' => isset($item['achieved']) && $item['achieved'] ? true : false,
            ];
        }

        return $out;
    }

    public function studentId(): int
    {
        return $this->studentId;
    }

    public function sessionId(): int
    {
        return $this->sessionId;
    }

    public function comment(): string
    {
        return $this->comment;
    }

    public function recordDate(): int
    {
        return $this->recordDate;
    }

    /**
     * @return array{front?:string,back?:string,side?:string}
     */
    public function posturePhotos(): array
    {
        return $this->posturePhotos;
    }

    /**
     * @return array<int,array{key:string,label:string,score:int}>
     */
    public function personalityTags(): array
    {
        return $this->personalityTags;
    }

    /**
     * @return array<int,array{key:string,label:string,achieved:bool}>
     */
    public function milestones(): array
    {
        return $this->milestones;
    }
}
