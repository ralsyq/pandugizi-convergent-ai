"""Stage 1: Deterministic Symbolic Rule-Based Scoring Engine.

Implements Algorithm 1 (Stage 1) of the PanduGizi Convergent AI framework.
Calculates clinical priority score and identifies immutable trigger conditions
without stochastic drift or medical hallucination.
"""

from typing import List, Dict, Any, Optional
from .models import PatientProfile, ScoringResult, TriggerReason
from .config import (
    RULE_WEIGHTS,
    DEFAULT_THRESHOLDS,
    LOW_LILA_THRESHOLD_CM,
    MONITORING_OVERDUE_DAYS,
    PRIORITY_LOW,
    PRIORITY_MEDIUM,
    PRIORITY_HIGH,
)


class DeterministicScoringEngine:
    """Deterministic rule-based clinical scoring engine.
    
    Serves as the immutable clinical anchor for risk stratification.
    Guarantees mathematical reproducibility and zero hallucination.
    """

    def __init__(
        self,
        weights: Optional[Dict[str, int]] = None,
        thresholds: Optional[Dict[str, int]] = None,
    ):
        self.weights = weights or RULE_WEIGHTS
        self.thresholds = thresholds or DEFAULT_THRESHOLDS

    def score(self, patient: PatientProfile) -> ScoringResult:
        """Computes deterministic priority score and risk tier for a patient.
        
        Args:
            patient: PatientProfile containing clinical and administrative observations.
            
        Returns:
            ScoringResult containing score, priority level, and active triggers.
        """
        score = 0
        reasons: List[TriggerReason] = []
        rules_evaluated: List[str] = []

        category = patient.kategori.lower()
        is_pregnant = patient.is_pregnant
        is_toddler = patient.is_toddler

        # 1. Rule: Active Clinical Complaint
        if patient.has_active_complaint:
            w = self.weights.get("active_complaint", 5)
            score += w
            reasons.append(
                TriggerReason(
                    rule="active_complaint",
                    reason="Patient has an active clinical complaint requiring follow-up.",
                    weight=w,
                    evidence={
                        "complaint": patient.complaint_description or "Keluhan aktif tercatat",
                        "severity": patient.complaint_severity or "unspecified",
                        "category": patient.complaint_category or "Umum",
                    },
                    reason_id="Pasien memiliki keluhan aktif yang memerlukan tindak lanjut tenaga kesehatan.",
                )
            )
        rules_evaluated.append("active_complaint")

        # 2. Rule: Monitoring Record Presence & Overdue Check
        if patient.days_since_last_monitoring is None:
            w = self.weights.get("no_monitoring_record", 4)
            score += w
            reasons.append(
                TriggerReason(
                    rule="no_monitoring_record",
                    reason="Patient has no recorded routine nutritional monitoring record.",
                    weight=w,
                    evidence={"days_since_last_monitoring": None},
                    reason_id="Pasien belum memiliki riwayat rekam monitoring gizi berkala.",
                )
            )
            rules_evaluated.append("no_monitoring_record")
        else:
            rules_evaluated.append("no_monitoring_record")
            if patient.days_since_last_monitoring > MONITORING_OVERDUE_DAYS:
                w = self.weights.get("monitoring_overdue", 4)
                score += w
                reasons.append(
                    TriggerReason(
                        rule="monitoring_overdue",
                        reason=f"Nutritional monitoring is overdue by {patient.days_since_last_monitoring} days.",
                        weight=w,
                        evidence={"days_overdue": patient.days_since_last_monitoring},
                        reason_id="Jadwal monitoring gizi pasien telah lewat lebih dari 30 hari.",
                    )
                )
            rules_evaluated.append("monitoring_overdue")

        # 3. Rule: Daily Medication / Supplement Intake Adherence
        if patient.missed_medication_today:
            w = self.weights.get("medication_not_reported_today", 3)
            score += w
            reasons.append(
                TriggerReason(
                    rule="medication_not_reported_today",
                    reason="Prescribed medication or micronutrient supplement intake unlogged today.",
                    weight=w,
                    evidence={"missed_medication_today": True},
                    reason_id="Konsumsi obat atau suplementasi belum dilaporkan pada hari ini.",
                )
            )
        rules_evaluated.append("medication_not_reported_today")

        # 4. Rule: Daily Supplementary Feeding (PMT) Adherence
        if patient.missed_pmt_today:
            w = self.weights.get("supplementary_food_not_reported_today", 3)
            score += w
            reasons.append(
                TriggerReason(
                    rule="supplementary_food_not_reported_today",
                    reason="Supplementary food (PMT) consumption unlogged today.",
                    weight=w,
                    evidence={"missed_pmt_today": True},
                    reason_id="Konsumsi Makanan Tambahan (PMT) belum dilaporkan pada hari ini.",
                )
            )
        rules_evaluated.append("supplementary_food_not_reported_today")

        # 5. Toddler Specific Rules
        if is_toddler:
            score += self._score_toddler_rules(patient, reasons, rules_evaluated)

        # 6. Pregnant Mother Specific Rules
        if is_pregnant:
            score += self._score_pregnant_rules(patient, reasons, rules_evaluated)

        # Determine Triage Level
        t1 = self.thresholds.get("T1_medium_priority", 4)
        t2 = self.thresholds.get("T2_high_priority", 8)

        if score < t1:
            priority_level = PRIORITY_LOW
        elif score < t2:
            priority_level = PRIORITY_MEDIUM
        else:
            priority_level = PRIORITY_HIGH

        return ScoringResult(
            priority_score=score,
            priority_level=priority_level,
            trigger_reasons=reasons,
            rules_evaluated=rules_evaluated,
            patient_category=category,
        )

    def _score_toddler_rules(
        self,
        patient: PatientProfile,
        reasons: List[TriggerReason],
        rules_evaluated: List[str],
    ) -> int:
        added_score = 0
        status = (patient.status_gizi or "").lower()

        # Severe / Stunting Status
        if any(keyword in status for keyword in ["stunting", "buruk", "severe"]):
            w = self.weights.get("toddler_stunting_status", 5)
            added_score += w
            reasons.append(
                TriggerReason(
                    rule="toddler_stunting_status",
                    reason="Toddler patient has a nutrition status indicating stunting or severe malnutrition.",
                    weight=w,
                    evidence={"status_gizi": patient.status_gizi},
                    reason_id="Status gizi balita menunjukkan indikasi risiko stunting atau masalah pertumbuhan yang perlu dipantau.",
                )
            )
        elif any(keyword in status for keyword in ["berisiko", "kurang", "risk"]):
            w = self.weights.get("toddler_risk_nutrition_status", 4)
            added_score += w
            reasons.append(
                TriggerReason(
                    rule="toddler_risk_nutrition_status",
                    reason="Toddler patient has an at-risk nutritional status requiring active monitoring.",
                    weight=w,
                    evidence={"status_gizi": patient.status_gizi},
                    reason_id="Status gizi balita menunjukkan kondisi berisiko yang memerlukan pemantauan lanjutan.",
                )
            )
        rules_evaluated.append("toddler_nutrition_status")

        # Longitudinal monitoring indicates risk
        if patient.latest_monitoring_risk:
            w = self.weights.get("latest_monitoring_indicates_risk", 4)
            added_score += w
            reasons.append(
                TriggerReason(
                    rule="latest_monitoring_indicates_risk",
                    reason="Latest monitoring examination flagged acute nutritional risk.",
                    weight=w,
                    evidence={"latest_monitoring_risk": True},
                    reason_id="Hasil monitoring gizi terakhir menunjukkan kondisi yang memerlukan tindak lanjut pemantauan.",
                )
            )
        rules_evaluated.append("latest_monitoring_indicates_risk")

        return added_score

    def _score_pregnant_rules(
        self,
        patient: PatientProfile,
        reasons: List[TriggerReason],
        rules_evaluated: List[str],
    ) -> int:
        added_score = 0

        # Chronic Energy Deficiency (LILA < 23.5 cm)
        if patient.lila is not None and patient.lila < LOW_LILA_THRESHOLD_CM:
            w = self.weights.get("pregnant_low_lila", 5)
            added_score += w
            reasons.append(
                TriggerReason(
                    rule="pregnant_low_lila",
                    reason=f"Maternal Upper Arm Circumference (LILA={patient.lila}cm) is below the 23.5cm CED threshold.",
                    weight=w,
                    evidence={"lila": patient.lila, "threshold": LOW_LILA_THRESHOLD_CM},
                    reason_id="Pengukuran LILA ibu hamil berada di bawah ambang pemantauan yang memerlukan tindak lanjut.",
                )
            )
        rules_evaluated.append("pregnant_low_lila")

        status = (patient.status_gizi or "").lower()
        if any(keyword in status for keyword in ["kek", "kurang", "berisiko"]):
            w = self.weights.get("pregnant_nutrition_risk_status", 4)
            added_score += w
            reasons.append(
                TriggerReason(
                    rule="pregnant_nutrition_risk_status",
                    reason="Maternal nutritional status is clinically categorized as at-risk / CED.",
                    weight=w,
                    evidence={"status_gizi": patient.status_gizi},
                    reason_id="Status gizi ibu hamil menunjukkan kondisi berisiko yang memerlukan pemantauan lanjutan.",
                )
            )
        rules_evaluated.append("pregnant_nutrition_risk_status")

        return added_score
