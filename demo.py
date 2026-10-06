"""Interactive Demonstration Script for PanduGizi Convergent AI.

Executes the three representative clinical scenarios documented in Table II of the paper:
- Case A: High-risk pregnant mother with Chronic Energy Deficiency (LILA < 23.5cm)
- Case B: High-risk toddler with stunting risk and overdue monitoring
- Case C: Routine pregnant mother with normal adherence
- Case D: Resilience stress test demonstrating automated graceful fallback
"""

import sys
from pathlib import Path

# Add project root to path
sys.path.insert(0, str(Path(__file__).parent))

from pandugizi import (
    PatientProfile,
    ConvergentPipeline,
    MockAgent,
    DeterministicScoringEngine,
    GracefulFallbackEngine,
    PRIORITY_LABELS_ID,
    PRIORITY_LABELS_EN,
)


def print_banner():
    print("=" * 80)
    print("   🏥 PANDUGIZI CONVERGENT AI ARCHITECTURE DEMONSTRATION 🏥")
    print("   Paper: 'An Explainable and Generative Convergent AI Architecture'")
    print("   Authors: Raisya Putri Agustin & Eka Miranda (BINUS University)")
    print("=" * 80)


def display_case(title: str, result, profile: PatientProfile):
    print(f"\n{'#' * 80}")
    print(f"  {title.upper()}")
    print(f"{'#' * 80}")
    print(f"👤 Patient Name        : {profile.nama}")
    print(f"📋 Category            : {profile.kategori.title()}")
    print(f"📊 Deterministic Score : {result.priority_score} points")
    print(f"🎯 Assigned Triage     : {result.priority_level.upper()} ({PRIORITY_LABELS_ID[result.priority_level]} / {PRIORITY_LABELS_EN[result.priority_level]})")
    print(f"⚡ Synthesis Pathway   : {result.synthesis_source.upper()} ({result.latency_ms:.1f} ms)")

    print("\n🔍 Deterministic Clinical Triggers (Audit Trail):")
    if result.trigger_reasons:
        for i, tr in enumerate(result.trigger_reasons, 1):
            print(f"   [{i}] {tr.rule} (+{tr.weight} pts): {tr.reason_id}")
            if tr.evidence:
                for k, v in tr.evidence.items():
                    print(f"       • {k}: {v}")
    else:
        print("   (No risk triggers detected - optimal adherence)")

    print("\n👨‍⚕️ Dual Output (1): Structured Clinical Action Plan (For Clinicians)")
    print("-" * 80)
    print(result.recommended_action)

    print("\n📱 Dual Output (2): Empathetic WhatsApp Notification (For Patient/Mother)")
    print("-" * 80)
    print(result.whatsapp_message)
    print("-" * 80)


def main():
    print_banner()

    pipeline = ConvergentPipeline()

    # -------------------------------------------------------------------------
    # CASE A: High Risk Pregnant Mother
    # -------------------------------------------------------------------------
    case_a = PatientProfile(
        id="PT-A01",
        nama="Ibu Siti Rahmawati",
        kategori="ibu hamil",
        lila=22.1,  # < 23.5 cm (Chronic Energy Deficiency)
        status_gizi="KEK",
        tinggi_badan=153.0,
        berat_badan=47.5,
        has_active_complaint=True,
        complaint_description="Mual pusing di pagi hari dan lemas",
        complaint_severity="mild",
        days_since_last_monitoring=14,
        missed_medication_today=True,  # Missed iron supplement
        missed_pmt_today=False,
    )
    res_a = pipeline.process(case_a)
    display_case("Case A: 26yo Pregnant Mother (CED Risk, Nausea, Missed Iron Pill)", res_a, case_a)

    # -------------------------------------------------------------------------
    # CASE B: High Risk Toddler
    # -------------------------------------------------------------------------
    case_b = PatientProfile(
        id="PT-B02",
        nama="Adik Bintang Kusuma",
        kategori="balita",
        status_gizi="stunting",
        tinggi_badan=74.0,
        berat_badan=8.2,
        has_active_complaint=False,
        days_since_last_monitoring=38,  # > 30 days overdue
        latest_monitoring_risk=True,
        missed_medication_today=False,
        missed_pmt_today=True,  # Missed supplementary food log
    )
    res_b = pipeline.process(case_b)
    display_case("Case B: 14mo Toddler (Stunting Risk, Unlogged PMT, Overdue Check-up)", res_b, case_b)

    # -------------------------------------------------------------------------
    # CASE C: Routine Adherent Mother
    # -------------------------------------------------------------------------
    case_c = PatientProfile(
        id="PT-C03",
        nama="Ibu Dewi Lestari",
        kategori="ibu hamil",
        lila=26.0,  # Normal LILA
        status_gizi="normal",
        tinggi_badan=158.0,
        berat_badan=58.0,
        has_active_complaint=False,
        days_since_last_monitoring=10,
        missed_medication_today=False,
        missed_pmt_today=False,
    )
    res_c = pipeline.process(case_c)
    display_case("Case C: 22yo Pregnant Mother (Optimal Adherence, Zero Complaints)", res_c, case_c)

    # -------------------------------------------------------------------------
    # CASE D: Resilience Test (Simulated API Outage -> Graceful Fallback)
    # -------------------------------------------------------------------------
    failing_agent = MockAgent(simulate_timeout=True)
    resilience_pipeline = ConvergentPipeline(llm_agent=failing_agent)
    res_d = resilience_pipeline.process(case_a, allow_fallback=True)
    display_case("Case D: Resilience Test (Simulated External API Downtime -> Graceful Fallback)", res_d, case_a)

    print("\n✅ All illustrative demonstration scenarios executed successfully.")
    print("💡 Reviewers can run unit tests with: pytest tests/\n")


if __name__ == "__main__":
    main()
