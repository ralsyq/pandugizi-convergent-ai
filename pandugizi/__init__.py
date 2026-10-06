"""PanduGizi: An Explainable and Generative Convergent AI Architecture for Nutritional Follow-Up.

Research Artifact Package for IEEE ICAIDES 2026.
Authors: Raisya Putri Agustin & Eka Miranda (Bina Nusantara University)
"""

from .config import (
    RULE_WEIGHTS,
    DEFAULT_THRESHOLDS,
    LOW_LILA_THRESHOLD_CM,
    MONITORING_OVERDUE_DAYS,
    PRIORITY_LOW,
    PRIORITY_MEDIUM,
    PRIORITY_HIGH,
    PRIORITY_LABELS_ID,
    PRIORITY_LABELS_EN,
)
from .models import (
    PatientProfile,
    ScoringResult,
    TriggerReason,
    DualOutput,
    PipelineResult,
)
from .scoring import DeterministicScoringEngine
from .agent import (
    BaseLlmAgent,
    MockAgent,
    GeminiAgent,
    GroqAgent,
    SYSTEM_INSTRUCTION_GUARDRAILS,
)
from .fallback import GracefulFallbackEngine
from .pipeline import ConvergentPipeline

__version__ = "1.0.0"
__all__ = [
    "RULE_WEIGHTS",
    "DEFAULT_THRESHOLDS",
    "PRIORITY_LOW",
    "PRIORITY_MEDIUM",
    "PRIORITY_HIGH",
    "PRIORITY_LABELS_ID",
    "PRIORITY_LABELS_EN",
    "PatientProfile",
    "ScoringResult",
    "TriggerReason",
    "DualOutput",
    "PipelineResult",
    "DeterministicScoringEngine",
    "BaseLlmAgent",
    "MockAgent",
    "GeminiAgent",
    "GroqAgent",
    "GracefulFallbackEngine",
    "ConvergentPipeline",
    "SYSTEM_INSTRUCTION_GUARDRAILS",
]
