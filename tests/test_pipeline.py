"""Unit tests for ConvergentPipeline (Algorithm 1 Orchestration)."""

import unittest
from pandugizi import (
    ConvergentPipeline,
    PatientProfile,
    MockAgent,
    PRIORITY_HIGH,
    PRIORITY_MEDIUM,
    PRIORITY_LOW,
)


class TestConvergentPipeline(unittest.TestCase):
    def test_pipeline_end_to_end_success(self):
        pipeline = ConvergentPipeline()
        patient = PatientProfile(
            id="P100",
            nama="Ibu Rina",
            kategori="ibu hamil",
            lila=21.0,
            has_active_complaint=True,
            complaint_description="Pusing",
        )
        res = pipeline.process(patient)
        self.assertEqual(res.priority_level, PRIORITY_HIGH)
        self.assertEqual(res.synthesis_source, "llm_agent")
        self.assertFalse(res.verified_by_clinician)
        self.assertTrue(len(res.recommended_action) > 0)
        self.assertTrue(len(res.whatsapp_message) > 0)

    def test_pipeline_graceful_fallback_on_api_failure(self):
        failing_agent = MockAgent(simulate_timeout=True)
        pipeline = ConvergentPipeline(llm_agent=failing_agent)
        patient = PatientProfile(
            id="P101",
            nama="Adik Bintang",
            kategori="balita",
            status_gizi="stunting",
        )
        res = pipeline.process(patient, allow_fallback=True)
        self.assertEqual(res.priority_level, PRIORITY_HIGH)
        self.assertEqual(res.synthesis_source, "graceful_fallback")
        self.assertIn("Puskesmas", res.whatsapp_message)


if __name__ == "__main__":
    unittest.main()
