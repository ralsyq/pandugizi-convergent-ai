<?php

namespace App\Services;

use App\Models\Keluhan;
use App\Models\LogMakananTambahan;
use App\Models\LogObat;
use App\Models\MakananTambahan;
use App\Models\MonitoringGizi;
use App\Models\ObatDetail;
use App\Models\Pasien;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

class FollowUpScoringService
{
    private const WEIGHT_ACTIVE_COMPLAINT = 5;
    private const WEIGHT_NO_MONITORING = 4;
    private const WEIGHT_MONITORING_OVERDUE = 4;
    private const WEIGHT_MEDICATION_NOT_REPORTED = 3;
    private const WEIGHT_SUPPLEMENTARY_FOOD_NOT_REPORTED = 3;
    private const WEIGHT_TODDLER_SEVERE_STATUS = 5;
    private const WEIGHT_TODDLER_RISK_STATUS = 4;
    private const WEIGHT_LATEST_MONITORING_RISK = 4;
    private const WEIGHT_PREGNANT_LOW_LILA = 5;
    private const WEIGHT_PREGNANT_RISK_STATUS = 4;

    private const HIGH_PRIORITY_THRESHOLD = 8;
    private const MEDIUM_PRIORITY_THRESHOLD = 4;
    private const MONITORING_OVERDUE_DAYS = 30;
    private const LOW_LILA_THRESHOLD = 23.5;

    public function score(Pasien $pasien): array
    {
        $score = 0;
        $reasons = [];
        $rulesEvaluated = [];
        $rulesSkipped = [];

        $category = $this->getPatientCategory($pasien);
        $isIbuHamil = $this->isCategoryIbuHamil($category);
        $isBalita = $this->isCategoryBalita($category);

        if (!$isIbuHamil && !$isBalita) {
            $rulesSkipped[] = [
                'rule' => 'category_scope',
                'reason' => 'Patient category is outside Phase 2 scope.',
                'evidence' => ['patient_category' => $category],
            ];

            return $this->buildResult($pasien, 0, $reasons, $rulesEvaluated, $rulesSkipped, $category);
        }

        if ($this->hasActiveComplaint($pasien, $rulesSkipped)) {
            $score += self::WEIGHT_ACTIVE_COMPLAINT;
            $this->addReason(
                $reasons,
                'active_complaint',
                self::WEIGHT_ACTIVE_COMPLAINT,
                'Pasien memiliki keluhan aktif yang belum ditanggapi.',
                ['active_statuses' => ['belum_dibalas']]
            );
        }
        $rulesEvaluated[] = 'active_complaint';

        $monitoringSkipCount = count($rulesSkipped);
        $latestMonitoring = $this->getLatestMonitoring($pasien, $rulesSkipped);
        $monitoringCouldBeEvaluated = count($rulesSkipped) === $monitoringSkipCount;

        if (!$latestMonitoring && $monitoringCouldBeEvaluated) {
            $score += self::WEIGHT_NO_MONITORING;
            $this->addReason(
                $reasons,
                'no_monitoring_record',
                self::WEIGHT_NO_MONITORING,
                'Pasien belum memiliki riwayat rekam monitoring gizi berkala.'
            );
            $rulesEvaluated[] = 'no_monitoring_record';
        } elseif ($latestMonitoring) {
            $rulesEvaluated[] = 'no_monitoring_record';

            if ($this->isMonitoringOverdue($latestMonitoring, $rulesSkipped)) {
                $score += self::WEIGHT_MONITORING_OVERDUE;
                $this->addReason(
                    $reasons,
                    'monitoring_overdue',
                    self::WEIGHT_MONITORING_OVERDUE,
                    'Jadwal monitoring gizi pasien telah lewat lebih dari 30 hari.',
                    [
                        'latest_monitoring_id' => $latestMonitoring->getKey(),
                        'latest_monitoring_date' => optional($latestMonitoring->created_at)->toDateString(),
                    ]
                );
            }
            $rulesEvaluated[] = 'monitoring_overdue';
        }

        if ($this->hasActiveMedicationAssignment($pasien, $rulesSkipped)) {
            $medicationEvidence = [];
            if (!$this->hasMedicationLogToday($pasien, $rulesSkipped, $medicationEvidence)) {
                $score += self::WEIGHT_MEDICATION_NOT_REPORTED;
                $this->addReason(
                    $reasons,
                    'medication_not_reported_today',
                    self::WEIGHT_MEDICATION_NOT_REPORTED,
                    'Asupan obat atau suplemen belum dilaporkan hari ini.',
                    $medicationEvidence
                );
            }
        }
        $rulesEvaluated[] = 'medication_not_reported_today';

        if ($this->hasActiveSupplementaryFoodAssignment($pasien, $rulesSkipped)) {
            $foodEvidence = [];
            if (!$this->hasSupplementaryFoodLogToday($pasien, $rulesSkipped, $foodEvidence)) {
                $score += self::WEIGHT_SUPPLEMENTARY_FOOD_NOT_REPORTED;
                $this->addReason(
                    $reasons,
                    'supplementary_food_not_reported_today',
                    self::WEIGHT_SUPPLEMENTARY_FOOD_NOT_REPORTED,
                    'Konsumsi makanan tambahan belum dilaporkan hari ini.',
                    $foodEvidence
                );
            }
        }
        $rulesEvaluated[] = 'supplementary_food_not_reported_today';

        if ($isBalita) {
            $score += $this->scoreToddlerRules($pasien, $latestMonitoring, $reasons, $rulesEvaluated);
        }

        if ($isIbuHamil) {
            $score += $this->scorePregnantRules($pasien, $reasons, $rulesEvaluated, $rulesSkipped);
        }

        return $this->buildResult($pasien, $score, $reasons, $rulesEvaluated, $rulesSkipped, $category);
    }

    private function scoreToddlerRules(Pasien $pasien, ?MonitoringGizi $latestMonitoring, array &$reasons, array &$rulesEvaluated): int
    {
        $score = 0;
        $nutritionStatus = $this->getNutritionStatus($pasien);

        if ($this->containsAny($nutritionStatus, ['stunting', 'buruk', 'severe'])) {
            $score += self::WEIGHT_TODDLER_SEVERE_STATUS;
            $this->addReason(
                $reasons,
                'toddler_stunting_status',
                self::WEIGHT_TODDLER_SEVERE_STATUS,
                'Status gizi balita terindikasi Stunting / Gizi Buruk.',
                ['statusGizi' => $pasien->getAttribute('statusGizi')]
            );
        } elseif ($this->containsAny($nutritionStatus, ['berisiko', 'risiko', 'gizi kurang', 'kurang'])) {
            $score += self::WEIGHT_TODDLER_RISK_STATUS;
            $this->addReason(
                $reasons,
                'toddler_risk_nutrition_status',
                self::WEIGHT_TODDLER_RISK_STATUS,
                'Status gizi balita terindikasi Berisiko Stunting / Gizi Kurang.',
                ['statusGizi' => $pasien->getAttribute('statusGizi')]
            );
        }
        $rulesEvaluated[] = 'toddler_nutrition_status';

        if ($latestMonitoring && $this->latestMonitoringIndicatesRisk($latestMonitoring)) {
            $score += self::WEIGHT_LATEST_MONITORING_RISK;
            $this->addReason(
                $reasons,
                'latest_monitoring_indicates_risk',
                self::WEIGHT_LATEST_MONITORING_RISK,
                'Hasil monitoring terkini menunjukkan indikasi risiko gizi.',
                [
                    'latest_monitoring_id' => $latestMonitoring->getKey(),
                    'hasil_ai' => $latestMonitoring->getAttribute('hasil_ai'),
                    'status_final_dokter' => $latestMonitoring->getAttribute('status_final_dokter'),
                ]
            );
        }
        $rulesEvaluated[] = 'latest_monitoring_indicates_risk';

        return $score;
    }

    private function scorePregnantRules(Pasien $pasien, array &$reasons, array &$rulesEvaluated, array &$rulesSkipped): int
    {
        $score = 0;
        $lila = $this->getLilaValue($pasien);

        if ($lila === null) {
            $rulesSkipped[] = [
                'rule' => 'pregnant_low_lila',
                'reason' => 'Data LILA belum tersedia.',
            ];
        } elseif ($lila < self::LOW_LILA_THRESHOLD) {
            $score += self::WEIGHT_PREGNANT_LOW_LILA;
            $this->addReason(
                $reasons,
                'pregnant_low_lila',
                self::WEIGHT_PREGNANT_LOW_LILA,
                'Ukuran LILA ibu hamil di bawah ambang batas (< 23.5 cm / Risiko KEK).',
                ['LILA' => $lila, 'threshold' => self::LOW_LILA_THRESHOLD]
            );
        }
        $rulesEvaluated[] = 'pregnant_low_lila';

        $nutritionStatus = $this->getNutritionStatus($pasien);
        if ($this->containsAny($nutritionStatus, ['berisiko', 'risiko', 'kurang', 'buruk', 'kek'])) {
            $score += self::WEIGHT_PREGNANT_RISK_STATUS;
            $this->addReason(
                $reasons,
                'pregnant_nutrition_risk_status',
                self::WEIGHT_PREGNANT_RISK_STATUS,
                'Status gizi ibu hamil berisiko KEK / Kurang Gizi.',
                ['statusGizi' => $pasien->getAttribute('statusGizi')]
            );
        }
        $rulesEvaluated[] = 'pregnant_nutrition_risk_status';

        return $score;
    }

    private function buildResult(Pasien $pasien, int $score, array $reasons, array $rulesEvaluated, array $rulesSkipped, ?string $category): array
    {
        $priorityLevel = $this->classifyPriority($score);

        return [
            'priority_score' => $score,
            'priority_level' => $priorityLevel,
            'trigger_reasons' => $reasons,
            'recommended_action' => $this->buildRecommendedAction($priorityLevel),
            'metadata' => [
                'patient_id' => $pasien->getKey(),
                'patient_identifier' => $pasien->getAttribute('nikPasien'),
                'patient_category' => $category,
                'evaluated_at' => Carbon::now()->toIso8601String(),
                'rules_evaluated' => array_values(array_unique($rulesEvaluated)),
                'rules_skipped' => $rulesSkipped,
            ],
        ];
    }

    private function normalizeText($value): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower((string) $value)));
    }

    private function containsAny($value, array $needles): bool
    {
        $normalized = $this->normalizeText($value);

        foreach ($needles as $needle) {
            if (strpos($normalized, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isCategoryIbuHamil(?string $category): bool
    {
        return $this->containsAny($category, ['ibu hamil', 'bumil', 'hamil']);
    }

    private function isCategoryBalita(?string $category): bool
    {
        return $this->containsAny($category, ['balita', 'bayi', 'toddler']);
    }

    private function addReason(array &$reasons, string $rule, int $weight, string $reason, ?array $evidence = null): void
    {
        $reasons[] = [
            'rule' => $rule,
            'weight' => $weight,
            'reason' => $reason,
            'evidence' => $evidence,
        ];
    }

    private function classifyPriority(int $score): string
    {
        if ($score >= self::HIGH_PRIORITY_THRESHOLD) {
            return 'high';
        }

        if ($score >= self::MEDIUM_PRIORITY_THRESHOLD) {
            return 'medium';
        }

        return 'low';
    }

    private function buildRecommendedAction(string $priorityLevel): string
    {
        if ($priorityLevel === 'high') {
            return 'Healthcare worker should review this patient and prepare immediate follow-up.';
        }

        if ($priorityLevel === 'medium') {
            return 'Healthcare worker should monitor this patient and consider follow-up if the condition persists.';
        }

        return 'Continue routine nutrition monitoring.';
    }

    private function getPatientCategory(Pasien $pasien): ?string
    {
        return $pasien->getAttribute('kategoriPasien');
    }

    private function getNutritionStatus(Pasien $pasien): ?string
    {
        return $pasien->getAttribute('statusGizi');
    }

    private function getLilaValue(Pasien $pasien): ?float
    {
        if (!array_key_exists('LILA', $pasien->getAttributes()) || $pasien->getAttribute('LILA') === null || $pasien->getAttribute('LILA') === '') {
            return null;
        }

        return (float) $pasien->getAttribute('LILA');
    }

    private function hasActiveComplaint(Pasien $pasien, array &$rulesSkipped): bool
    {
        if (!$this->hasColumns('keluhan', ['nikPasien', 'status'], $rulesSkipped, 'active_complaint')) {
            return false;
        }

        try {
            return Keluhan::where('nikPasien', $pasien->getAttribute('nikPasien'))
                ->whereIn('status', ['belum_dibalas', 'open', 'active', 'aktif', 'pending', 'belum_selesai'])
                ->exists();
        } catch (Throwable $exception) {
            $this->skipForReadError($rulesSkipped, 'active_complaint', $exception);
            return false;
        }
    }

    private function getLatestMonitoring(Pasien $pasien, array &$rulesSkipped): ?MonitoringGizi
    {
        if (!$this->hasColumns('monitoring_gizi', ['nikPasien'], $rulesSkipped, 'monitoring_record')) {
            return null;
        }

        try {
            return MonitoringGizi::where('nikPasien', $pasien->getAttribute('nikPasien'))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
        } catch (Throwable $exception) {
            $this->skipForReadError($rulesSkipped, 'monitoring_record', $exception);
            return null;
        }
    }

    private function isMonitoringOverdue(MonitoringGizi $monitoring, array &$rulesSkipped): bool
    {
        if (!$monitoring->created_at) {
            $rulesSkipped[] = [
                'rule' => 'monitoring_overdue',
                'reason' => 'Latest monitoring date is empty.',
                'evidence' => ['latest_monitoring_id' => $monitoring->getKey()],
            ];

            return false;
        }

        return Carbon::parse($monitoring->created_at)->lt(Carbon::now()->subDays(self::MONITORING_OVERDUE_DAYS));
    }

    private function hasActiveMedicationAssignment(Pasien $pasien, array &$rulesSkipped): bool
    {
        if (!$this->hasColumns('obat_detail', ['nikPasien', 'statusTerapi'], $rulesSkipped, 'medication_not_reported_today')) {
            return false;
        }

        try {
            return ObatDetail::where('nikPasien', $pasien->getAttribute('nikPasien'))
                ->where('statusTerapi', 'aktif')
                ->exists();
        } catch (Throwable $exception) {
            $this->skipForReadError($rulesSkipped, 'medication_not_reported_today', $exception);
            return false;
        }
    }

    private function hasMedicationLogToday(Pasien $pasien, array &$rulesSkipped, array &$evidence): bool
    {
        if (!$this->hasColumns('obat_detail', ['obatDetailID', 'nikPasien', 'statusTerapi'], $rulesSkipped, 'medication_not_reported_today')) {
            return true;
        }

        if (!$this->hasColumns('log_obat', ['obatDetailID', 'nikPasien', 'tanggal'], $rulesSkipped, 'medication_not_reported_today')) {
            return true;
        }

        try {
            $activeIds = ObatDetail::where('nikPasien', $pasien->getAttribute('nikPasien'))
                ->where('statusTerapi', 'aktif')
                ->pluck('obatDetailID')
                ->filter()
                ->values();

            $reportedCount = LogObat::where('nikPasien', $pasien->getAttribute('nikPasien'))
                ->whereDate('tanggal', Carbon::today()->toDateString())
                ->whereIn('obatDetailID', $activeIds)
                ->distinct('obatDetailID')
                ->count('obatDetailID');

            $evidence = [
                'date' => Carbon::today()->toDateString(),
                'active_assignment_count' => $activeIds->count(),
                'reported_assignment_count' => $reportedCount,
            ];

            return $activeIds->count() > 0 && $reportedCount >= $activeIds->count();
        } catch (Throwable $exception) {
            $this->skipForReadError($rulesSkipped, 'medication_not_reported_today', $exception);
            return true;
        }
    }

    private function hasActiveSupplementaryFoodAssignment(Pasien $pasien, array &$rulesSkipped): bool
    {
        if (!$this->hasColumns('makanan_tambahan', ['nikPasien', 'statusPemberian'], $rulesSkipped, 'supplementary_food_not_reported_today')) {
            return false;
        }

        try {
            return MakananTambahan::where('nikPasien', $pasien->getAttribute('nikPasien'))
                ->where('statusPemberian', 'aktif')
                ->exists();
        } catch (Throwable $exception) {
            $this->skipForReadError($rulesSkipped, 'supplementary_food_not_reported_today', $exception);
            return false;
        }
    }

    private function hasSupplementaryFoodLogToday(Pasien $pasien, array &$rulesSkipped, array &$evidence): bool
    {
        if (!$this->hasColumns('makanan_tambahan', ['makananTambahanID', 'nikPasien', 'statusPemberian'], $rulesSkipped, 'supplementary_food_not_reported_today')) {
            return true;
        }

        if (!$this->hasColumns('log_makanan_tambahan', ['makananTambahanID', 'nikPasien', 'tanggal'], $rulesSkipped, 'supplementary_food_not_reported_today')) {
            return true;
        }

        try {
            $activeIds = MakananTambahan::where('nikPasien', $pasien->getAttribute('nikPasien'))
                ->where('statusPemberian', 'aktif')
                ->pluck('makananTambahanID')
                ->filter()
                ->values();

            $reportedCount = LogMakananTambahan::where('nikPasien', $pasien->getAttribute('nikPasien'))
                ->whereDate('tanggal', Carbon::today()->toDateString())
                ->whereIn('makananTambahanID', $activeIds)
                ->distinct('makananTambahanID')
                ->count('makananTambahanID');

            $evidence = [
                'date' => Carbon::today()->toDateString(),
                'active_assignment_count' => $activeIds->count(),
                'reported_assignment_count' => $reportedCount,
            ];

            return $activeIds->count() > 0 && $reportedCount >= $activeIds->count();
        } catch (Throwable $exception) {
            $this->skipForReadError($rulesSkipped, 'supplementary_food_not_reported_today', $exception);
            return true;
        }
    }

    private function latestMonitoringIndicatesRisk(MonitoringGizi $monitoring): bool
    {
        return $this->containsAny($monitoring->getAttribute('hasil_ai'), ['stunting', 'berisiko', 'risk', 'risiko', 'kurang', 'buruk'])
            || $this->containsAny($monitoring->getAttribute('status_final_dokter'), ['stunting', 'berisiko', 'risk', 'risiko', 'kurang', 'buruk']);
    }

    private function hasColumns(string $table, array $columns, array &$rulesSkipped, string $rule): bool
    {
        try {
            if (!Schema::hasTable($table)) {
                $rulesSkipped[] = [
                    'rule' => $rule,
                    'reason' => "Table {$table} not found.",
                ];

                return false;
            }

            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    $rulesSkipped[] = [
                        'rule' => $rule,
                        'reason' => "Column {$table}.{$column} not found.",
                    ];

                    return false;
                }
            }

            return true;
        } catch (Throwable $exception) {
            $this->skipForReadError($rulesSkipped, $rule, $exception);
            return false;
        }
    }

    private function skipForReadError(array &$rulesSkipped, string $rule, Throwable $exception): void
    {
        $rulesSkipped[] = [
            'rule' => $rule,
            'reason' => 'Read-only query could not be evaluated.',
            'evidence' => ['error' => $exception->getMessage()],
        ];
    }
}
