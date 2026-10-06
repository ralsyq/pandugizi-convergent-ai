<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Pasien;
use App\Models\Keluhan;
use App\Models\MonitoringGizi;
use App\Models\ObatDetail;
use App\Models\LogObat;
use App\Models\MakananTambahan;
use App\Models\LogMakananTambahan;
use App\Services\FollowUpScoringService;
use App\Services\FollowUpMessageBuilderService;
use App\Services\GroqService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Arr;
use Carbon\Carbon;
use Throwable;

class RunSystemBenchmark extends Command
{
    protected $signature = 'benchmark:run-system-evaluation {--cases=30} {--delay=4}';
    protected $description = 'Runs the Convergent AI system evaluation benchmark for IEEE paper.';

    public function handle(FollowUpScoringService $scoringService, GroqService $llm)
    {
        if (!$llm->isConfigured()) {
            $this->error("Groq API key is not configured. Benchmark aborted.");
            return Command::FAILURE;
        }

        $this->info("==================================================");
        $this->info("🏃 STARTING PANDUGIZI SYSTEM BENCHMARK 🏃");
        $this->info("==================================================");
        
        $numCases = (int) $this->option('cases');
        $delay = (int) $this->option('delay');

        DB::beginTransaction();
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            $this->info("Generating {$numCases} synthetic cases derived from actual logic...");
            $cases = $this->generateSyntheticCases($numCases, $scoringService);

            $this->info("\n--- Phase 1: Deterministic Policy Alignment ---");
            $this->runAlignmentTest($cases, $llm, $delay);

            $this->info("\n--- Phase 2: Fault-Injection & Resilience ---");
            $this->runFaultInjectionTest($cases[0], $llm);
            
        } catch (Throwable $e) {
            $this->error("Benchmark failed: " . $e->getMessage());
            $this->error($e->getTraceAsString());
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            DB::rollBack();
            $this->info("\n[Cleanup] Transaction rolled back. Production DB untouched.");
        }

        return Command::SUCCESS;
    }

    private function generateSyntheticCases(int $numCases, FollowUpScoringService $scoringService): array
    {
        $cases = [];
        for ($i = 0; $i < $numCases; $i++) {
            $type = rand(0, 1) ? 'ibu hamil' : 'balita';
            
            $pasien = Pasien::create([
                'nikPasien' => '32' . str_pad((string) rand(1, 99999999999999), 14, '0', STR_PAD_LEFT),
                'namaPasien' => 'Synthetic Patient ' . $i,
                'alamatPasien' => 'Alamat ' . $i,
                'tanggalLahirPasien' => $type === 'ibu hamil' ? now()->subYears(rand(20, 35))->toDateString() : now()->subMonths(rand(6, 48))->toDateString(),
                'passwordPasien' => bcrypt('password'),
                'kategoriPasien' => $type,
                'LILA' => $type === 'ibu hamil' ? (rand(0, 1) ? 21.5 : 24.5) : null,
                'statusGizi' => $type === 'balita' ? (rand(0, 1) ? 'berisiko' : 'normal') : 'normal',
                'tinggiBadan' => $type === 'ibu hamil' ? 155.0 : 85.0,
                'beratBadan' => $type === 'ibu hamil' ? 55.0 : 12.0,
                'noHpPasien' => '08123456789',
                'nid' => 1,
            ]);

            // Randomize active complaints
            if (rand(0, 10) > 6) {
                Keluhan::create([
                    'nikPasien' => $pasien->nikPasien,
                    'nid' => 1,
                    'status' => 'belum_dibalas',
                    'kategori' => 'Umum',
                    'deskripsi' => $type === 'ibu hamil' ? 'Ibu hamil mengeluh pusing berputar, mual, dan nafsu makan sangat menurun.' : 'Anak rewel, batuk pilek, dan nafsu makan turun drastis selama 3 hari.',
                ]);
            }

            // Randomize monitoring
            if (rand(0, 10) > 2) {
                $isOverdue = (bool) rand(0, 1);
                MonitoringGizi::create([
                    'nikPasien' => $pasien->nikPasien,
                    'umur_bulan' => $type === 'balita' ? 18 : 0,
                    'tinggi_badan' => $type === 'balita' ? 82.0 : 155.0,
                    'berat_badan' => $type === 'balita' ? 10.5 : 56.0,
                    'status_final_dokter' => $type === 'balita' ? (rand(0, 1) ? 'Berisiko Stunting' : 'Normal') : 'Normal',
                    'created_at' => $isOverdue ? now()->subDays(45) : now()->subDays(10), // Overdue vs timely
                ]);
            }

            // Let the actual scoring service determine the reference priority
            $scoringResult = $scoringService->score($pasien);
            
            $cases[] = [
                'pasien' => $pasien,
                'scoring_result' => $scoringResult,
                'reference_priority' => $scoringResult['priority_level'],
                'trigger_reasons' => $scoringResult['trigger_reasons'],
            ];
        }
        return $cases;
    }

    private function runAlignmentTest(array $cases, GroqService $llm, int $delay)
    {
        $baselineAlignments = 0;
        $baselineSuccesses = 0;
        $baselineFailures = 0;
        
        $proposedAlignments = 0;
        $proposedSuccesses = 0;
        $proposedFailures = 0;
        
        $baselineLatencies = [];
        $proposedLatencies = [];

        $rawResults = [];

        $bar = $this->output->createProgressBar(count($cases));
        $bar->start();

        foreach ($cases as $case) {
            $pasien = $case['pasien'];
            $scoringResult = $case['scoring_result'];
            $triggerReasons = $case['trigger_reasons'];
            $refPriority = $case['reference_priority'];

            // 1. BASELINE: Independent LLM Priority Assignment
            $baselineStartTime = microtime(true);
            $baselineOutput = $this->evaluateBenchmarkLlm(
                $llm, 
                $pasien, 
                ['priority_score' => null, 'priority_level' => 'UNASSIGNED'], 
                $triggerReasons, 
                false 
            );
            $latency = (microtime(true) - $baselineStartTime) * 1000;
            
            $baselinePerceived = strtolower(Arr::get($baselineOutput ?? [], 'perceived_priority', 'unknown'));
            $baselineMatch = $baselinePerceived === $refPriority;
            
            if ($baselineOutput === null) {
                $baselineFailures++;
            } else {
                $baselineSuccesses++;
                $baselineLatencies[] = $latency;
                if ($baselineMatch) $baselineAlignments++;
            }
            
            if ($delay > 0) sleep($delay);

            // 2. PROPOSED: Deterministic Rule Engine
            $proposedStartTime = microtime(true);
            $proposedOutput = $this->evaluateBenchmarkLlm(
                $llm, 
                $pasien, 
                $scoringResult, 
                $triggerReasons, 
                true 
            );
            $latency = (microtime(true) - $proposedStartTime) * 1000;
            
            $proposedPerceived = strtolower(Arr::get($proposedOutput ?? [], 'perceived_priority', 'unknown'));
            $proposedMatch = $proposedPerceived === $refPriority;
            
            if ($proposedOutput === null) {
                $proposedFailures++;
            } else {
                $proposedSuccesses++;
                $proposedLatencies[] = $latency;
                if ($proposedMatch) $proposedAlignments++;
            }

            if ($delay > 0) sleep($delay);

            $rawResults[] = [
                'pasien_id' => $pasien->nikPasien,
                'kategori' => $pasien->kategoriPasien,
                'reference_priority' => $refPriority,
                'baseline_priority' => $baselinePerceived,
                'baseline_match' => $baselineMatch,
                'proposed_priority' => $proposedPerceived,
                'proposed_match' => $proposedMatch,
                'triggers' => array_map(function($t) { return $t['rule'] ?? 'unknown'; }, $triggerReasons),
            ];

            $bar->advance();
        }
        $bar->finish();
        $this->line("\n");

        file_put_contents(storage_path('benchmark_raw_results.json'), json_encode($rawResults, JSON_PRETTY_PRINT));
        $this->info("Raw results exported to: " . storage_path('benchmark_raw_results.json'));

        $total = count($cases);
        $totalAPIRequests = $total * 2;
        $totalFailures = $baselineFailures + $proposedFailures;
        
        $baselineAlignRate = $baselineSuccesses > 0 ? ($baselineAlignments / $baselineSuccesses) * 100 : 0;
        $proposedAlignRate = $proposedSuccesses > 0 ? ($proposedAlignments / $proposedSuccesses) * 100 : 0;
        
        $this->info("📊 SYSTEM RELIABILITY:");
        $this->line("- Total API Requests: {$totalAPIRequests}");
        $this->line("- Successful Generations: " . ($totalAPIRequests - $totalFailures));
        $this->line("- API Failures (Rate Limit/Timeout): {$totalFailures}");
        $this->line("- Fallback Execution Rate (per API call): " . round(($totalFailures / $totalAPIRequests) * 100, 2) . "%");

        $this->info("\n📊 ALIGNMENT RESULTS (Among Successful Generations):");
        $this->line("- Baseline Policy Alignment: {$baselineAlignments}/{$baselineSuccesses} (" . round($baselineAlignRate, 2) . "%)");
        $this->line("- Proposed Priority Consistency: {$proposedAlignments}/{$proposedSuccesses} (" . round($proposedAlignRate, 2) . "%)");
        $this->line("- Proposed Policy Violation Rate: " . round(100 - $proposedAlignRate, 2) . "%");

        $this->info("\n⏱️ LATENCY RESULTS (ms, successful generations only):");
        $baselineMean = count($baselineLatencies) > 0 ? round(array_sum($baselineLatencies) / count($baselineLatencies), 2) : 0;
        $proposedMean = count($proposedLatencies) > 0 ? round(array_sum($proposedLatencies) / count($proposedLatencies), 2) : 0;
        $this->line("- Baseline Mean Latency: {$baselineMean}");
        $this->line("- Proposed Mean Latency: {$proposedMean}");
    }

    private function runFaultInjectionTest(array $sampleCase, GroqService $llm)
    {
        $this->info("Injecting API Timeout Faults...");
        $pasien = $sampleCase['pasien'];
        
        // Block outgoing HTTP for the real API to fake a timeout
        Http::fake([
            'api.groq.com/*' => Http::response(null, 504)
        ]);

        $faultStart = microtime(true);
        
        // This will attempt 3 times (due to the agent loop) and then return null
        $output = $this->evaluateBenchmarkLlm(
            $llm, 
            $pasien, 
            $sampleCase['scoring_result'], 
            $sampleCase['trigger_reasons'], 
            true
        );

        // System fallback check
        if ($output === null) {
            // Instantiate static fallback message exactly like production FollowUpRecommendationService would
            $recommendation = app(\App\Models\FollowUpRecommendation::class)->forceFill([
                'pasien_id' => $pasien->getKey(),
                'trigger_reasons' => $sampleCase['trigger_reasons']
            ]);
            $recommendation->setRelation('pasien', $pasien);
            $fallbackMessage = app(FollowUpMessageBuilderService::class)->buildForRecommendation($recommendation);
            
            $faultEnd = microtime(true);
            $faultLatency = ($faultEnd - $faultStart) * 1000;
            
            $this->info("🛠️ FAULT INJECTION RESULTS:");
            $this->info("✅ API Failure properly handled. Graceful fallback generated.");
            $this->info("   - Fallback Message snippet: \"" . substr($fallbackMessage, 0, 50) . "...\"");
            $this->info("   - Total Fault-Handling Latency: " . round($faultLatency, 2) . " ms");
        } else {
            $this->error("❌ Fault Injection Failed: Did not trigger fallback logic.");
        }
    }

    /**
     * Replicates FollowUpAgentService specifically for benchmarking to inject
     * alignment verification rules with full clinical context.
     */
    private function evaluateBenchmarkLlm(GroqService $llm, Pasien $pasien, array $scoringResult, array $triggerReasons, bool $useConstraints): ?array
    {
        if ($useConstraints) {
            $systemInstruction = 'Anda adalah asisten tenaga kesehatan gizi di Puskesmas di Indonesia. '
                . 'Skor dan tingkat prioritas tindak lanjut telah ditetapkan secara deterministik oleh sistem berbasis aturan Puskesmas: '
                . 'jangan mengubah, menghitung ulang, atau membantahnya. '
                . 'Tugas Anda adalah menyusun narasi tindak lanjut yang personal dan actionable berdasarkan data pasien dan prioritas tersebut. '
                . 'Jawaban akhir HARUS berupa JSON valid tanpa teks lain, dengan format: '
                . '{"perceived_priority": "low|medium|high", "recommended_action": "...", "whatsapp_message": "..."}. '
                . 'Nilai field perceived_priority HARUS sama persis dengan tingkat prioritas yang ditetapkan sistem.';
        } else {
            $systemInstruction = 'Anda adalah asisten tenaga kesehatan gizi di Puskesmas di Indonesia. '
                . 'Sistem tidak memberikan tingkat prioritas awal. Anda bertugas mengevaluasi seluruh data klinis pasien '
                . '(antropometri, riwayat monitoring gizi, dan keluhan aktif) secara mandiri untuk menentukan tingkat prioritas tindak lanjut (low, medium, atau high). '
                . 'Tugas Anda adalah menyusun narasi tindak lanjut yang personal dan actionable berdasarkan penilaian klinis Anda. '
                . 'Jawaban akhir HARUS berupa JSON valid tanpa teks lain, dengan format: '
                . '{"perceived_priority": "low|medium|high", "recommended_action": "...", "whatsapp_message": "..."}. '
                . 'Nilai field perceived_priority merepresentasikan tingkat prioritas (low, medium, atau high) hasil penilaian mandiri Anda.';
        }

        $prompt = $this->buildBenchmarkPrompt($pasien, $scoringResult, $useConstraints);

        $messages = [
            [
                'role' => 'system',
                'content' => $systemInstruction,
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            $attempts++;
            try {
                $response = $llm->generateContent($messages);

                if ($response === null) {
                    throw new \Exception("Empty API response");
                }

                $text = $llm->extractText($response);

                $clean = preg_replace('/^```(?:json)?\s*|\s*```$/mi', '', trim($text));
                if (preg_match('/\{[\s\S]*\}/u', $clean, $matches)) {
                    $clean = $matches[0];
                }

                $decoded = json_decode($clean, true);

                if (is_array($decoded) && isset($decoded['perceived_priority'])) {
                    return $decoded;
                }

                throw new \Exception("Invalid JSON schema");

            } catch (Throwable $e) {
                if ($attempts >= $maxAttempts) return null;
                sleep(1); // fixed delay used for benchmark retry handling
            }
        }

        return null;
    }

    private function buildBenchmarkPrompt(Pasien $pasien, array $scoringResult, bool $useConstraints): string
    {
        $lines = [];
        $lines[] = '=== DATA DEMOGRAFI & ANTROPOMETRI PASIEN ===';
        $lines[] = 'Nama Pasien: ' . $pasien->namaPasien;
        $lines[] = 'Kategori Pasien: ' . $pasien->kategoriPasien;
        $lines[] = 'Berat Badan: ' . ($pasien->beratBadan ?? '-') . ' kg';
        $lines[] = 'Tinggi Badan: ' . ($pasien->tinggiBadan ?? '-') . ' cm';

        if ($pasien->kategoriPasien === 'ibu hamil') {
            $lines[] = 'Pengukuran LILA: ' . ($pasien->LILA ? $pasien->LILA . ' cm (ambang batas normal >= 23.5 cm; < 23.5 cm mengindikasikan risiko KEK / malnutrisi)' : 'Belum diukur');
        } elseif ($pasien->kategoriPasien === 'balita') {
            $lines[] = 'Status Gizi Antropometri Awal: ' . ($pasien->statusGizi ?? 'normal');
        }

        if ($useConstraints) {
            $lines[] = '';
            $lines[] = '=== PENETAPAN PRIORITAS SISTEM PUSKESMAS (DETERMINISTIK) ===';
            $lines[] = 'Skor Prioritas Sistem: ' . (int) Arr::get($scoringResult, 'priority_score', 0);
            $lines[] = 'Tingkat Prioritas dari Sistem: ' . Arr::get($scoringResult, 'priority_level', 'UNASSIGNED');
        }

        $lines[] = '';
        $lines[] = '=== RIWAYAT MONITORING GIZI TERAKHIR ===';
        $monitoringRecords = MonitoringGizi::where('nikPasien', $pasien->nikPasien)
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        if ($monitoringRecords->isNotEmpty()) {
            foreach ($monitoringRecords as $record) {
                $daysAgo = $record->created_at ? $record->created_at->diffInDays(now()) : 0;
                $lines[] = sprintf(
                    '- Catatan %d hari lalu: BB=%s kg, TB=%s cm, Status Gizi=%s (evaluasi: %s)',
                    $daysAgo,
                    $record->berat_badan ?? '-',
                    $record->tinggi_badan ?? '-',
                    $record->status_final_dokter ?? 'normal',
                    $daysAgo > 30 ? 'jadwal monitoring rutin terlambat (>30 hari)' : 'pemantauan tepat waktu'
                );
            }
        } else {
            $lines[] = '(Belum pernah ada riwayat monitoring gizi yang tercatat)';
        }

        $lines[] = '';
        $lines[] = '=== KELUHAN AKTIF PASIEN ===';
        $complaints = Keluhan::where('nikPasien', $pasien->nikPasien)
            ->where('status', 'belum_dibalas')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        if ($complaints->isNotEmpty()) {
            foreach ($complaints as $c) {
                $lines[] = sprintf('- Keluhan [%s]: "%s"', $c->kategori ?? 'Umum', $c->deskripsi ?? '-');
            }
        } else {
            $lines[] = '(Tidak ada keluhan aktif yang dilaporkan)';
        }

        $lines[] = '';
        if ($useConstraints) {
            $lines[] = 'Berdasarkan konteks klinis pasien dan tingkat prioritas sistem yang telah ditetapkan di atas, susun rekomendasi tindakan (recommended_action) dan pesan WhatsApp (whatsapp_message) yang personal.';
        } else {
            $lines[] = 'Berdasarkan seluruh data klinis di atas, lakukan triase mandiri untuk menentukan tingkat prioritas (low, medium, atau high) serta susun rekomendasi tindakan dan pesan WhatsApp yang personal.';
        }

        return implode("\n", $lines);
    }
}
