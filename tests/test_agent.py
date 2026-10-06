"""Unit tests for Stage 2 LLM Agent & Clinical Guardrails."""

import unittest
from pandugizi import (
    MockAgent,
    PatientProfile,
    ScoringResult,
    TriggerReason,
    PRIORITY_HIGH,
)


class TestLlmAgent(unittest.TestCase):
    def setUp(self):
        self.agent = MockAgent()

    def test_prompt_enforces_priority_invariance(self):
        patient = PatientProfile(id="P01", nama="Ibu Siti", kategori="ibu hamil")
        scoring = ScoringResult(
            priority_score=9,
            priority_level=PRIORITY_HIGH,
            trigger_reasons=[],
            rules_evaluated=[],
            patient_category="ibu hamil",
        )
        triggers = [TriggerReason(rule="low_lila", reason="LILA low", weight=5)]

        prompt = self.agent.build_prompt(patient, scoring, triggers)
        self.assertIn("Tingkat Prioritas (FINAL): HIGH", prompt)
        self.assertIn("Ibu Siti", prompt)

    def test_parse_response_with_markdown_fences(self):
        markdown_json = '```json\n{"recommended_action": "Action 1", "whatsapp_message": "Msg 1"}\n```'
        action, wa = self.agent.parse_response(markdown_json)
        self.assertEqual(action, "Action 1")
        self.assertEqual(wa, "Msg 1")

    def test_mock_agent_synthesizes_dual_output(self):
        patient = PatientProfile(id="P02", nama="Adik Kenzo", kategori="balita")
        scoring = ScoringResult(
            priority_score=9,
            priority_level=PRIORITY_HIGH,
            trigger_reasons=[],
            rules_evaluated=[],
            patient_category="balita",
        )
        triggers = [TriggerReason(rule="toddler_stunting_status", reason="Stunting", weight=5)]

        out = self.agent.generate(patient, scoring, triggers)
        self.assertEqual(out.source, "llm_agent")
        self.assertTrue(len(out.recommended_action) > 10)
        self.assertTrue(len(out.whatsapp_message) > 10)


if __name__ == "__main__":
    unittest.main()
