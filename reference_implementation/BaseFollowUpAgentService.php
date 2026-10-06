<?php

namespace App\Services;

use App\Models\Keluhan;
use App\Models\MonitoringGizi;
use App\Models\Pasien;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class BaseFollowUpAgentService
{
    protected const HISTORY_LIMIT = 5;
    protected const COMPLAINT_LIMIT = 3;

    abstract public function isEnabled(): bool;
    
    /**
     * Provider-specific method to call the LLM and return the generated text.
     */
    abstract protected function callLlm(string $prompt, string $systemInstruction): ?string;

    /**
     * Generates personalized narrative by sending patient context + scoring to the LLM.
     * Returns ['recommended_action' => string, 'whatsapp_message' => string], or null on failure.
     */
    public function generateNarrative(Pasien $pasien, array $scoringResult, array $triggerReasons): ?array
    {
        if (!$this->isEnabled() || empty($triggerReasons)) {
            return null;
        }

        $prompt = $this->buildPrompt($pasien, $scoringResult, $triggerReasons);
        $systemInstruction = $this->systemInstruction();

        $attempts = 0;
        $maxAttempts = 3; // 1 initial try + 2 retries

        while ($attempts < $maxAttempts) {
            $attempts++;
            try {
                $text = $this->callLlm($prompt, $systemInstruction);

                if ($text === null) {
                    throw new \Exception("Empty response from LLM API");
                }

                $parsed = $this->parseNarrative($text);

                if ($parsed === null) {
                    throw new \Exception("Failed to parse LLM narrative");
                }

                return $parsed;

            } catch (Throwable $e) {
                if ($attempts >= $maxAttempts) {
                    Log::error('LLM follow-up agent failed after retries', [
                        'pasien_id' => $pasien->getKey(),
                        'message' => $e->getMessage(),
                        'attempts' => $attempts,
                    ]);

                    return null;
                }

                // Jeda 1 detik sebelum retry berikutnya
                sleep(1);
            }
        }

        return null;
    }

    protected function systemInstruction(): string
    {
        return 'Anda adalah asisten tenaga kesehatan gizi di Puskesmas di Indonesia. '
            . 'Anda menerima hasil asesmen prioritas tindak lanjut yang sudah dihitung oleh sistem berbasis aturan. '
            . 'Skor dan tingkat prioritas sudah final: jangan mengubah, menghitung ulang, atau membantahnya. '
            . 'Tugas Anda adalah menyusun narasi tindak lanjut yang personal dan actionable berdasarkan data pasien dan alasan pemicu yang diberikan. '
            . 'Pertimbangkan riwayat monitoring dan keluhan yang tersedia untuk membuat rekomendasi yang spesifik ke kondisi pasien. '
            . 'Jangan membuat diagnosis medis definitif dan jangan meresepkan obat; '
            . 'semua keputusan klinis tetap berada pada tenaga kesehatan. '
            . 'Jawaban akhir HARUS berupa JSON valid tanpa teks lain, dengan bentuk: '
            . '{"recommended_action": "...", "whatsapp_message": "..."}. '
            . 'Field recommended_action ditujukan untuk tenaga kesehatan (bahasa klinis, spesifik, bernomor, actionable). '
            . 'Field whatsapp_message ditujukan untuk pasien via WhatsApp dengan aturan formatting ketat: '
            . '1. WAJIB menggunakan enter / baris baru ganda (\n\n) antar paragraf agar mudah dibaca dan tidak menumpuk. '
            . '2. Gunakan emoji yang sopan dan relevan (👋, 🥗, 🩺, 🤰, 👶, 💊, ✨, 📌, 💡) agar pesan hangat dan komunikatif. '
            . '3. Gunakan formatting WhatsApp seperti *tebal* (*bold*) pada kata kunci atau judul bagian. '
            . '4. Susun dalam alur terstruktur: (a) Salam pembuka hangat, (b) Catatan kondisi/keluhan gizi, (c) Tips praktis asupan makanan/suplemen, (d) Ajakan tindak lanjut/kunjungan ke Puskesmas, (e) Kalimat penutup penyemangat.';
    }

    protected function buildPrompt(Pasien $pasien, array $scoringResult, array $triggerReasons): string
    {
        $lines = [];
        $lines[] = '=== DATA ASESMEN PASIEN ===';
        $lines[] = 'Nama: ' . $this->patientName($pasien);
        $lines[] = 'Kategori: ' . (string) $pasien->getAttribute('kategoriPasien');
        $lines[] = 'Skor Prioritas: ' . (int) Arr::get($scoringResult, 'priority_score', 0);
        $lines[] = 'Tingkat Prioritas: ' . (string) Arr::get($scoringResult, 'priority_level', 'low');
        $lines[] = '';

        $lines[] = '=== ALASAN PEMICU TINDAK LANJUT ===';
        foreach ($triggerReasons as $index => $reason) {
            if (!is_array($reason)) {
                continue;
            }

            $lines[] = sprintf(
                '%d. %s (bobot: %s)',
                $index + 1,
                (string) Arr::get($reason, 'reason_id', Arr::get($reason, 'reason', '-')),
                (string) Arr::get($reason, 'weight', '-')
            );

            $evidence = Arr::get($reason, 'evidence');
            if (is_array($evidence) && !empty($evidence)) {
                foreach ($evidence as $key => $value) {
                    $lines[] = '   - ' . $key . ': ' . $this->formatValue($value);
                }
            }
        }
        $lines[] = '';

        $lines[] = '=== RIWAYAT MONITORING GIZI ===';
        $monitoring = $this->fetchMonitoringHistory($pasien);
        if (!empty($monitoring)) {
            foreach ($monitoring as $record) {
                $lines[] = sprintf(
                    '%s: BB=%s kg, TB=%s cm, Status=%s',
                    $record['tanggal'],
                    $record['berat_badan'],
                    $record['tinggi_badan'],
                    $record['status_gizi']
                );
            }
        } else {
            $lines[] = '(Tidak ada riwayat monitoring)';
        }
        $lines[] = '';

        $lines[] = '=== KELUHAN AKTIF ===';
        $complaints = $this->fetchComplaintDetail($pasien);
        if (!empty($complaints)) {
            foreach ($complaints as $complaint) {
                $lines[] = sprintf(
                    '%s - %s: %s',
                    $complaint['tanggal'],
                    $complaint['kategori'],
                    $complaint['deskripsi']
                );
            }
        } else {
            $lines[] = '(Tidak ada keluhan aktif)';
        }
        $lines[] = '';

        $lines[] = 'Berdasarkan konteks di atas, susun narasi tindak lanjut yang personal dan spesifik.';

        return implode("\n", $lines);
    }

    protected function fetchMonitoringHistory(Pasien $pasien): array
    {
        $records = MonitoringGizi::where('nikPasien', $pasien->getAttribute('nikPasien'))
            ->orderByDesc('created_at')
            ->limit(static::HISTORY_LIMIT)
            ->get();

        $items = [];

        foreach ($records as $record) {
            $items[] = [
                'tanggal' => optional($record->created_at)->toDateString(),
                'umur_bulan' => $record->getAttribute('umur_bulan'),
                'tinggi_badan' => $record->getAttribute('tinggi_badan'),
                'berat_badan' => $record->getAttribute('berat_badan'),
                'status_gizi' => $record->getAttribute('status_final_dokter'),
            ];
        }

        return $items;
    }

    protected function fetchComplaintDetail(Pasien $pasien): array
    {
        $records = Keluhan::where('nikPasien', $pasien->getAttribute('nikPasien'))
            ->where('status', 'belum_dibalas')
            ->orderByDesc('created_at')
            ->limit(static::COMPLAINT_LIMIT)
            ->get();

        $items = [];

        foreach ($records as $record) {
            $items[] = [
                'tanggal' => optional($record->created_at)->toDateString(),
                'kategori' => $record->getAttribute('kategori'),
                'deskripsi' => $record->getAttribute('deskripsi'),
            ];
        }

        return $items;
    }

    protected function parseNarrative(string $text): ?array
    {
        if ($text === '') {
            return null;
        }

        // 1. Remove markdown code fences
        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/mi', '', trim($text));

        // 2. Extract JSON object between first { and last }
        if (preg_match('/\{[\s\S]*\}/u', $clean, $matches)) {
            $clean = $matches[0];
        }

        $decoded = json_decode($clean, true);

        // 3. Fallback: sanitize unescaped newlines inside JSON strings
        if (!is_array($decoded)) {
            $sanitized = preg_replace_callback('/"([^"\\\\]*|\\\\.)*"/s', function ($m) {
                return str_replace(["\r\n", "\r", "\n"], ['\n', '\n', '\n'], $m[0]);
            }, $clean);
            $decoded = json_decode($sanitized, true);
        }

        if (!is_array($decoded)) {
            Log::warning('LLM agent returned unparsable output', ['output' => mb_substr($text, 0, 500)]);
            return null;
        }

        $action = trim((string) Arr::get($decoded, 'recommended_action', ''));
        $message = trim((string) Arr::get($decoded, 'whatsapp_message', ''));

        if ($action === '' && $message === '') {
            return null;
        }

        return [
            'recommended_action' => $action !== '' ? $action : null,
            'whatsapp_message' => $message !== '' ? $message : null,
        ];
    }

    protected function patientName(Pasien $pasien): string
    {
        $name = trim((string) $pasien->getAttribute('namaPasien'));
        return $name !== '' ? $name : 'Pasien';
    }

    protected function formatValue($value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
