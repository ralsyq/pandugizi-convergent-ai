"""Configuration, rule weights, and priority thresholds for PanduGizi Convergent AI.

Calibrated against Indonesian Ministry of Health maternal and child nutrition
standards and clinical protocols at Puskesmas Sawah Besar, Jakarta.
"""

from typing import Dict

# Priority Thresholds for Stage 1 Deterministic Triage
# Score < T1 => Low Priority (Routine Monitoring)
# T1 <= Score < T2 => Medium Priority (Follow-Up Reminder)
# Score >= T2 => High Priority (Urgent Intervention)
DEFAULT_THRESHOLDS: Dict[str, int] = {
    "T1_medium_priority": 4,
    "T2_high_priority": 8,
}

# Rule Weights (W1 to W6)
RULE_WEIGHTS: Dict[str, int] = {
    # Active clinical complaints (acute maternal/child symptoms)
    "active_complaint": 5,
    # Anthropometric risk: Maternal Upper Arm Circumference (LILA) < 23.5 cm (Chronic Energy Deficiency)
    "pregnant_low_lila": 5,
    # Anthropometric risk: Toddler stunting or severe malnutrition
    "toddler_stunting_status": 5,
    # Anthropometric risk: Toddler at-risk nutritional status
    "toddler_risk_nutrition_status": 4,
    # Anthropometric risk: Maternal nutritional risk
    "pregnant_nutrition_risk_status": 4,
    # Longitudinal monitoring indicates acute risk
    "latest_monitoring_indicates_risk": 4,
    # Attendance: Monitoring visit overdue (> 30 days)
    "monitoring_overdue": 4,
    # Data gap: No prior monitoring baseline recorded
    "no_monitoring_record": 4,
    # Compliance: Supplementary feeding (PMT) unlogged today
    "supplementary_food_not_reported_today": 3,
    # Compliance: Iron-folic acid or micronutrient supplement unlogged today
    "medication_not_reported_today": 3,
}

# Threshold parameter boundaries
LOW_LILA_THRESHOLD_CM = 23.5
MONITORING_OVERDUE_DAYS = 30

# Priority Level Constants
PRIORITY_LOW = "low"
PRIORITY_MEDIUM = "medium"
PRIORITY_HIGH = "high"

PRIORITY_LABELS_ID = {
    PRIORITY_LOW: "Rendah (Rutin)",
    PRIORITY_MEDIUM: "Sedang (Pengingat)",
    PRIORITY_HIGH: "Tinggi (Mendesak)",
}

PRIORITY_LABELS_EN = {
    PRIORITY_LOW: "Low (Routine)",
    PRIORITY_MEDIUM: "Medium (Reminder)",
    PRIORITY_HIGH: "High (Urgent)",
}

# Priority ordering for selecting dominant rule in deterministic fallback
DOMINANT_RULE_ORDER = [
    "active_complaint",
    "pregnant_low_lila",
    "toddler_stunting_status",
    "toddler_risk_nutrition_status",
    "latest_monitoring_indicates_risk",
    "monitoring_overdue",
    "no_monitoring_record",
    "medication_not_reported_today",
    "supplementary_food_not_reported_today",
    "pregnant_nutrition_risk_status",
]
