<?php

namespace App\Services;

use App\Models\Dokter;
use App\Models\FollowUpRecommendation;
use App\Models\Pasien;
use Illuminate\Support\Arr;

class FollowUpRecommendationService
{
    private const SOURCE_KELUHAN = 'keluhan';
    private const SOURCE_MONITORING_GIZI = 'monitoring_gizi';
    private const SOURCE_MANUAL = 'manual';
    private const SOURCE_SYSTEM = 'system';

    private const ALLOWED_SOURCE_TYPES = [
        self::SOURCE_KELUHAN,
        self::SOURCE_MONITORING_GIZI,
        self::SOURCE_MANUAL,
        self::SOURCE_SYSTEM,
    ];

    private const ACTION_RULE_PRIORITY = [
        'active_complaint',
        'pregnant_low_lila',
        'toddler_stunting_status',
        'toddler_risk_nutrition_status',
        'latest_monitoring_indicates_risk',
        'monitoring_overdue',
        'no_monitoring_record',
        'medication_not_reported_today',
        'supplementary_food_not_reported_today',
    ];

    private FollowUpScoringService $scoringService;
    private GroqFollowUpAgentService $agentService;

    public function __construct(FollowUpScoringService $scoringService, ?GroqFollowUpAgentService $agentService = null)
    {
        $this->scoringService = $scoringService;
        $this->agentService = $agentService ?: app(GroqFollowUpAgentService::class);
    }

    public function generateForPatient(Pasien $pasien, array $options = []): FollowUpRecommendation
    {
        $sourceType = $this->normalizeSourceType(Arr::get($options, 'source_type'));
        $sourceId = $this->normalizeNullableInteger(Arr::get($options, 'source_id'));
        $dokterId = $this->resolveDokterId($pasien, Arr::get($options, 'dokter_id'));
        $cancelExistingPending = Arr::get($options, 'cancel_existing_pending', true) !== false;

        $scoringResult = $this->scoringService->score($pasien);

        if ($cancelExistingPending) {
            FollowUpRecommendation::where('pasien_id', $pasien->getKey())
                ->where('status', FollowUpRecommendation::STATUS_PENDING)
                ->update(['status' => FollowUpRecommendation::STATUS_CANCELLED]);
        }

        $triggerReasons = $this->localizeTriggerReasons(Arr::get($scoringResult, 'trigger_reasons', []));

        $narrative = $this->agentService->generateNarrative($pasien, $scoringResult, $triggerReasons);

        return FollowUpRecommendation::create([
            'pasien_id' => $pasien->getKey(),
            'dokter_id' => $dokterId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'priority_score' => (int) Arr::get($scoringResult, 'priority_score', 0),
            'priority_level' => $this->normalizePriorityLevel(Arr::get($scoringResult, 'priority_level')),
            'trigger_reasons' => $triggerReasons,
            'recommended_action' => Arr::get($narrative, 'recommended_action')
                ?: $this->buildRecommendedAction($triggerReasons),
            'ai_whatsapp_message' => Arr::get($narrative, 'whatsapp_message'),
            'status' => FollowUpRecommendation::STATUS_PENDING,
            'generated_at' => now(),
        ]);
    }

    public function getPriorityLabel(string $priorityLevel): string
    {
        $labels = [
            FollowUpRecommendation::PRIORITY_LOW => 'Rendah',
            FollowUpRecommendation::PRIORITY_MEDIUM => 'Sedang',
            FollowUpRecommendation::PRIORITY_HIGH => 'Tinggi',
        ];

        return $labels[$this->normalizePriorityLevel($priorityLevel)] ?? 'Rendah';
    }

    public function getPriorityDisplayLabel(string $priorityLevel): string
    {
        $labels = [
            FollowUpRecommendation::PRIORITY_LOW => 'Prioritas Rendah',
            FollowUpRecommendation::PRIORITY_MEDIUM => 'Prioritas Sedang',
            FollowUpRecommendation::PRIORITY_HIGH => 'Prioritas Tinggi',
        ];

        return $labels[$this->normalizePriorityLevel($priorityLevel)] ?? 'Prioritas Rendah';
    }

    public function getStatusLabel(string $status): string
    {
        $labels = [
            FollowUpRecommendation::STATUS_PENDING => 'Menunggu Tinjauan',
            FollowUpRecommendation::STATUS_APPROVED => 'Disetujui',
            FollowUpRecommendation::STATUS_REJECTED => 'Ditolak',
            FollowUpRecommendation::STATUS_SENT => 'Terkirim',
            FollowUpRecommendation::STATUS_FAILED => 'Gagal',
            FollowUpRecommendation::STATUS_CANCELLED => 'Dibatalkan',
        ];

        return $labels[strtolower(trim($status))] ?? 'Tidak Diketahui';
    }

    public function getSourceTypeLabel(?string $sourceType): string
    {
        $labels = [
            self::SOURCE_KELUHAN => 'Keluhan Pasien',
            self::SOURCE_MONITORING_GIZI => 'Monitoring Gizi',
            self::SOURCE_MANUAL => 'Input Manual Tenaga Kesehatan',
            self::SOURCE_SYSTEM => 'Sistem',
        ];

        if ($sourceType === null) {
            return 'Tidak Diketahui';
        }

        return $labels[strtolower(trim($sourceType))] ?? 'Tidak Diketahui';
    }

    private function normalizeSourceType($sourceType): string
    {
        $sourceType = strtolower(trim((string) $sourceType));

        if ($sourceType === '' || !in_array($sourceType, self::ALLOWED_SOURCE_TYPES, true)) {
            return self::SOURCE_SYSTEM;
        }

        return $sourceType;
    }

    private function normalizePriorityLevel($priorityLevel): string
    {
        $priorityLevel = strtolower(trim((string) $priorityLevel));

        if (in_array($priorityLevel, [
            FollowUpRecommendation::PRIORITY_LOW,
            FollowUpRecommendation::PRIORITY_MEDIUM,
            FollowUpRecommendation::PRIORITY_HIGH,
        ], true)) {
            return $priorityLevel;
        }

        return FollowUpRecommendation::PRIORITY_LOW;
    }

    private function normalizeNullableInteger($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function resolveDokterId(Pasien $pasien, $optionDokterId): ?int
    {
        $dokterId = $this->normalizeNullableInteger($optionDokterId);

        if ($dokterId !== null) {
            return $dokterId;
        }

        if ($pasien->getAttribute('dokter_id')) {
            return (int) $pasien->getAttribute('dokter_id');
        }

        $dokter = $pasien->relationLoaded('dokter')
            ? $pasien->getRelation('dokter')
            : $pasien->dokter()->first();

        if ($dokter instanceof Dokter && $dokter->getKey()) {
            return (int) $dokter->getKey();
        }

        return null;
    }

    private function localizeTriggerReasons(array $triggerReasons): array
    {
        return array_map(function ($reason) {
            if (!is_array($reason)) {
                return $reason;
            }

            $rule = (string) Arr::get($reason, 'rule', '');
            $reason['reason_id'] = $this->getReasonLabel($rule);

            return $reason;
        }, $triggerReasons);
    }

    private function getReasonLabel(string $rule): string
    {
        $labels = [
            'active_complaint' => 'Pasien memiliki keluhan aktif yang memerlukan tindak lanjut tenaga kesehatan.',
            'no_monitoring_record' => 'Pasien belum memiliki catatan monitoring gizi yang dapat digunakan untuk evaluasi awal.',
            'monitoring_overdue' => 'Data monitoring gizi terakhir telah melewati batas waktu pemantauan rutin.',
            'medication_not_reported_today' => 'Konsumsi obat atau suplementasi belum dilaporkan pada hari ini.',
            'supplementary_food_not_reported_today' => 'Konsumsi Makanan Tambahan (PMT) belum dilaporkan pada hari ini.',
            'toddler_stunting_status' => 'Status gizi balita menunjukkan indikasi risiko stunting atau masalah pertumbuhan yang perlu dipantau.',
            'toddler_risk_nutrition_status' => 'Status gizi balita menunjukkan kondisi berisiko yang memerlukan pemantauan lanjutan.',
            'latest_monitoring_indicates_risk' => 'Hasil monitoring gizi terakhir menunjukkan kondisi yang memerlukan tindak lanjut pemantauan.',
            'pregnant_low_lila' => 'Pengukuran LILA ibu hamil berada di bawah ambang pemantauan yang memerlukan tindak lanjut.',
            'pregnant_nutrition_risk_status' => 'Status gizi ibu hamil menunjukkan kondisi berisiko yang memerlukan pemantauan lanjutan.',
        ];

        return $labels[$rule] ?? 'Faktor pemantauan ini memerlukan tinjauan lebih lanjut oleh tenaga kesehatan.';
    }

    private function buildRecommendedAction(array $triggerReasons): string
    {
        $rule = $this->findHighestWeightRule($triggerReasons);

        $actions = [
            'active_complaint' => 'Prioritaskan tindak lanjut oleh tenaga kesehatan untuk mengevaluasi keluhan aktif pasien dan menentukan kebutuhan pemeriksaan lanjutan.',
            'pregnant_low_lila' => 'Jadwalkan tindak lanjut untuk evaluasi risiko Kekurangan Energi Kronis (KEK) dan edukasi pemantauan asupan gizi ibu hamil.',
            'toddler_stunting_status' => 'Jadwalkan evaluasi antropometri ulang dan koordinasikan pemantauan status gizi balita sesuai alur layanan yang berlaku.',
            'toddler_risk_nutrition_status' => 'Lakukan pemantauan lanjutan terhadap status gizi balita dan pertimbangkan edukasi gizi kepada orang tua atau wali pasien.',
            'latest_monitoring_indicates_risk' => 'Tinjau kembali hasil monitoring gizi terakhir dan pertimbangkan tindak lanjut pemantauan sesuai kondisi pasien.',
            'monitoring_overdue' => 'Jadwalkan ulang monitoring gizi karena data pemantauan terakhir telah melewati batas waktu pemantauan rutin.',
            'no_monitoring_record' => 'Lakukan pencatatan monitoring gizi awal agar tenaga kesehatan dapat menilai perkembangan status gizi pasien.',
            'medication_not_reported_today' => 'Lakukan pengingat dan verifikasi kepatuhan pencatatan konsumsi obat atau suplementasi sesuai jadwal yang telah diberikan.',
            'supplementary_food_not_reported_today' => 'Lakukan pengingat dan verifikasi pencatatan konsumsi Makanan Tambahan (PMT) sesuai jadwal pemantauan.',
        ];

        return $actions[$rule] ?? 'Lanjutkan pemantauan gizi rutin dan lakukan tindak lanjut sesuai kebutuhan layanan.';
    }

    private function findHighestWeightRule(array $triggerReasons): ?string
    {
        $highestWeight = null;
        $candidateRules = [];

        foreach ($triggerReasons as $reason) {
            if (!is_array($reason)) {
                continue;
            }

            $rule = Arr::get($reason, 'rule');
            $weight = Arr::get($reason, 'weight');

            if (!is_string($rule) || !is_numeric($weight)) {
                continue;
            }

            $weight = (int) $weight;

            if ($highestWeight === null || $weight > $highestWeight) {
                $highestWeight = $weight;
                $candidateRules = [$rule];
                continue;
            }

            if ($weight === $highestWeight) {
                $candidateRules[] = $rule;
            }
        }

        foreach (self::ACTION_RULE_PRIORITY as $rule) {
            if (in_array($rule, $candidateRules, true)) {
                return $rule;
            }
        }

        return $candidateRules[0] ?? null;
    }
}
