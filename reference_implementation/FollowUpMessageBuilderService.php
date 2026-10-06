<?php

namespace App\Services;

use App\Models\FollowUpRecommendation;
use App\Models\Pasien;
use Illuminate\Support\Arr;

class FollowUpMessageBuilderService
{
    private const RULE_PRIORITY = [
        'active_complaint',
        'pregnant_low_lila',
        'toddler_stunting_status',
        'toddler_risk_nutrition_status',
        'monitoring_overdue',
        'medication_not_reported_today',
        'supplementary_food_not_reported_today',
        'latest_monitoring_indicates_risk',
        'no_monitoring_record',
        'pregnant_nutrition_risk_status',
    ];

    public function buildForRecommendation(FollowUpRecommendation $recommendation): string
    {
        $aiMessage = trim((string) $recommendation->getAttribute('ai_whatsapp_message'));

        if ($aiMessage !== '') {
            return $aiMessage;
        }

        $patientName = $this->getPatientName($recommendation);
        $triggerReasons = $this->normalizeTriggerReasons($recommendation->trigger_reasons);
        $dominantRule = $this->getDominantRule($triggerReasons);

        return $this->buildMessageByRule($dominantRule, $patientName);
    }

    public function getPatientName(FollowUpRecommendation $recommendation): string
    {
        $pasien = $this->getPatient($recommendation);

        if ($pasien instanceof Pasien) {
            foreach (['namaPasien', 'nama_lengkap', 'name', 'nama'] as $field) {
                $name = trim((string) $pasien->getAttribute($field));

                if ($name !== '') {
                    return $name;
                }
            }
        }

        return 'Ibu/Bapak';
    }

    public function getPatientPhone(FollowUpRecommendation $recommendation): ?string
    {
        $pasien = $this->getPatient($recommendation);

        if (!$pasien instanceof Pasien) {
            return null;
        }

        $phone = trim((string) $pasien->getAttribute('noHpPasien'));

        return $phone !== '' ? $phone : null;
    }

    public function getDominantRule(array $triggerReasons): ?string
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

        foreach (self::RULE_PRIORITY as $rule) {
            if (in_array($rule, $candidateRules, true)) {
                return $rule;
            }
        }

        return $candidateRules[0] ?? null;
    }

    public function normalizeTriggerReasons($triggerReasons): array
    {
        if (is_array($triggerReasons)) {
            return $triggerReasons;
        }

        if (is_string($triggerReasons) && trim($triggerReasons) !== '') {
            $decoded = json_decode($triggerReasons, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    public function buildDefaultMessage(string $patientName): string
    {
        $greeting = $this->buildGreeting($patientName);

        return "{$greeting} 👋,\n\n"
            . "Ini pengingat dari petugas gizi *Puskesmas*. 🏥\n\n"
            . "📌 Berdasarkan evaluasi berkala, terdapat catatan kesehatan yang perlu ditindaklanjuti bersama tenaga kesehatan.\n\n"
            . "💡 Mohon dapat melakukan pembaruan data atau menghubungi Puskesmas untuk konsultasi lebih lanjut ya.\n\n"
            . "Terima kasih banyak, semoga sehat selalu! ✨";
    }

    public function buildMessageByRule(?string $rule, string $patientName): string
    {
        $greeting = $this->buildGreeting($patientName);
        $messages = [
            'active_complaint' => "{$greeting} 👋,\n\n"
                . "Ini pesan dari petugas kesehatan *Puskesmas*. 🩺\n\n"
                . "📌 Kami menerima catatan keluhan yang Ibu/Bapak sampaikan dan membutuhkan perhatian lebih lanjut dari tim medis.\n\n"
                . "💡 Mohon memantau perkembangan kondisi serta mengikuti arahan petugas, atau segera berkunjung ke Puskesmas bila keluhan berlanjut ya.\n\n"
                . "Semoga lekas pulih dan sehat selalu! ✨",

            'monitoring_overdue' => "{$greeting} 👋,\n\n"
                . "Ini pengingat jadwal pemantauan dari *Puskesmas*. 📊\n\n"
                . "📌 Jadwal monitoring gizi rutin Anda sudah melewati batas waktu pemantauan berkala.\n\n"
                . "💡 Mari luangkan waktu untuk mencatat perkembangan tinggi/berat badan atau berkunjung ke Puskesmas agar status gizi tetap terpantau dengan baik.\n\n"
                . "Terima kasih atas kerja samanya! ✨",

            'medication_not_reported_today' => "{$greeting} 👋,\n\n"
                . "Ini pengingat dari *Puskesmas* terkait jadwal suplemen/obat. 💊\n\n"
                . "📌 Catatan konsumsi obat atau suplemen gizi hari ini belum terisi di aplikasi.\n\n"
                . "💡 Jangan lupa dikonsumsi sesuai petunjuk dokter dan centang laporannya ya.\n\n"
                . "Semangat selalu dalam menjaga kesehatan! ✨",

            'supplementary_food_not_reported_today' => "{$greeting} 👋,\n\n"
                . "Ini pengingat konsumsi Makanan Tambahan (PMT) dari *Puskesmas*. 🥗\n\n"
                . "📌 Laporan pemberian makanan tambahan untuk hari ini belum tercatat di sistem.\n\n"
                . "💡 Pastikan makanan tambahan dikonsumsi dengan baik demi pemenuhan nutrisi optimal ya.\n\n"
                . "Terima kasih dan sehat selalu! ✨",

            'pregnant_low_lila' => "{$greeting} 👋,\n\n"
                . "Ini pesan perhatian dari petugas gizi *Puskesmas*. 🤰\n\n"
                . "📌 Berdasarkan catatan pemantauan kehamilan, ukuran lingkar lengan (LILA) memerlukan perhatian dan pendampingan nutrisi khusus.\n\n"
                . "💡 Kami mengundang Ibu untuk berkonsultasi ke Puskesmas agar mendapatkan panduan menu gizi seimbang dan makanan tambahan (PMT Bumil).\n\n"
                . "Semoga Ibu dan calon buah hati senantiasa sehat dan kuat! ✨",

            'toddler_stunting_status' => "{$greeting} 👋,\n\n"
                . "Ini pesan perhatian gizi anak dari *Puskesmas*. 👶\n\n"
                . "📌 Hasil pemantauan pertumbuhan si kecil menunjukkan indikasi perlunya evaluasi dan stimulasi nutrisi tambahan bersama dokter/ahli gizi.\n\n"
                . "💡 Kami sangat menyarankan Ayah/Bunda untuk mengajak si kecil berkunjung ke Puskesmas guna pemeriksaan tumbuh kembang lebih lanjut.\n\n"
                . "Mari bersama kawal tumbuh kembang optimal anak kita! ✨",

            'toddler_risk_nutrition_status' => "{$greeting} 👋,\n\n"
                . "Ini pesan pemantauan gizi anak dari *Puskesmas*. 👶\n\n"
                . "📌 Pertumbuhan si kecil saat ini berada pada batas yang perlu dioptimalkan asupannya.\n\n"
                . "💡 Mohon pastikan asupan protein hewani dan jadwal makan teratur, serta silakan berkonsultasi ke Puskesmas bila ada kendala makan ya.\n\n"
                . "Semoga si kecil tumbuh sehat, aktif, dan ceria! ✨",
        ];

        return $messages[$rule] ?? $this->buildDefaultMessage($patientName);
    }

    private function buildGreeting(string $patientName): string
    {
        $patientName = trim($patientName);

        if ($patientName === '' || strtolower($patientName) === 'ibu/bapak') {
            return 'Halo Ibu/Bapak';
        }

        return "Halo Ibu/Bapak {$patientName}";
    }

    private function getPatient(FollowUpRecommendation $recommendation): ?Pasien
    {
        if ($recommendation->relationLoaded('pasien')) {
            $pasien = $recommendation->getRelation('pasien');

            return $pasien instanceof Pasien ? $pasien : null;
        }

        return $recommendation->pasien()->first();
    }
}
