# PanduGizi: Reference Implementation (Laravel / PHP 8.2)

This directory contains the production Laravel backend services deployed in the clinical workstation at Puskesmas Sawah Besar, Jakarta.

## Architecture Mapping

The production PHP services map directly to the standalone Python research engine:

| Academic Paper Concept | Laravel Production Service | Python Core Engine |
| :--- | :--- | :--- |
| **Stage 1: Deterministic Rule-Based Scoring** | `FollowUpScoringService.php` | `pandugizi/scoring.py` |
| **Stage 2: Constrained LLM Generative Synthesis** | `GeminiFollowUpAgentService.php` / `GroqFollowUpAgentService.php` | `pandugizi/agent.py` |
| **Stage 3: Automated Graceful Degradation** | `FollowUpMessageBuilderService.php` | `pandugizi/fallback.py` |
| **Pipeline Orchestrator** | `FollowUpRecommendationService.php` | `pandugizi/pipeline.py` |
| **Benchmarking Command** | `RunSystemBenchmark.php` | `benchmark/run_benchmark.py` |

## Included Reference Files
1. `FollowUpScoringService.php`: Full weighted scoring implementation evaluating 10 clinical and administrative rules.
2. `FollowUpRecommendationService.php`: Clinical review and recommendation life cycle controller.
3. `BaseFollowUpAgentService.php`: Abstract agent managing structured prompt generation, retries, and JSON sanitation.
4. `GeminiFollowUpAgentService.php`: Google Gemini integration (gemini-1.5-flash / gemini-2.0).
5. `GroqFollowUpAgentService.php`: Ultra-low-latency Groq fallback agent.
6. `FollowUpMessageBuilderService.php`: Deterministic fallback message engine for WhatsApp notifications.
7. `RunSystemBenchmark.php`: Production Artisan command (`php artisan benchmark:run-system-evaluation`).
