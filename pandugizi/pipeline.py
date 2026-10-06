"""Two-Stage Convergent Follow-Up Recommendation Pipeline Orchestrator.

Formalizes and executes Algorithm 1 from the PanduGizi paper:
1. Stage 1: Deterministic Risk Triage & Clinical Guardrails
2. Stage 2: Constrained Generative Synthesis & Graceful Fallback
3. Stage 3: Human-in-the-Loop Review Hook
"""

import time
from typing import Optional
from .models import PatientProfile, PipelineResult
from .scoring import DeterministicScoringEngine
from .agent import BaseLlmAgent, MockAgent
from .fallback import GracefulFallbackEngine


class ConvergentPipeline:
    """Orchestrates the two-stage Convergent AI decision and communication pipeline."""

    def __init__(
        self,
        scoring_engine: Optional[DeterministicScoringEngine] = None,
        llm_agent: Optional[BaseLlmAgent] = None,
        fallback_engine: Optional[GracefulFallbackEngine] = None,
    ):
        self.scoring_engine = scoring_engine or DeterministicScoringEngine()
        self.llm_agent = llm_agent or MockAgent()
        self.fallback_engine = fallback_engine or GracefulFallbackEngine()

    def process(
        self,
        patient: PatientProfile,
        allow_fallback: bool = True,
    ) -> PipelineResult:
        """Executes Algorithm 1 for a single patient profile.
        
        Args:
            patient: Structured clinical profile of the patient.
            allow_fallback: Whether to activate automated graceful fallback on API error.
            
        Returns:
            PipelineResult containing deterministic score, priority level, triggers,
            and dual synthesized recommendations.
        """
        # ======================================================================
        # STAGE 1: Deterministic Risk Triage & Clinical Guardrails
        # ======================================================================
        stage1_start = time.time()
        scoring_result = self.scoring_engine.score(patient)
        triggers = scoring_result.trigger_reasons
        priority_level = scoring_result.priority_level
        priority_score = scoring_result.priority_score

        # ======================================================================
        # STAGE 2: Constrained Generative Synthesis & Fallback
        # ======================================================================
        rec_action = ""
        whatsapp_msg = ""
        synthesis_source = "llm_agent"
        total_latency_ms = 0.0

        try:
            dual_output = self.llm_agent.generate(patient, scoring_result, triggers)
            rec_action = dual_output.recommended_action
            whatsapp_msg = dual_output.whatsapp_message
            synthesis_source = dual_output.source
            total_latency_ms = dual_output.latency_ms
        except Exception as e:
            if not allow_fallback:
                raise e

            # Fallback path (Graceful degradation)
            fallback_start = time.time()
            fallback_output = self.fallback_engine.generate(
                patient=patient,
                trigger_reasons=triggers,
                latency_ms=(time.time() - fallback_start) * 1000,
            )
            rec_action = fallback_output.recommended_action
            whatsapp_msg = fallback_output.whatsapp_message
            synthesis_source = "graceful_fallback"
            total_latency_ms = (time.time() - stage1_start) * 1000

        return PipelineResult(
            patient_id=patient.id,
            patient_name=patient.nama,
            patient_category=patient.kategori,
            priority_score=priority_score,
            priority_level=priority_level,
            trigger_reasons=triggers,
            recommended_action=rec_action,
            whatsapp_message=whatsapp_msg,
            synthesis_source=synthesis_source,
            latency_ms=total_latency_ms,
            verified_by_clinician=False,
        )
