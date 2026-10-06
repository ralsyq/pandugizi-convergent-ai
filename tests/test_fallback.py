"""Unit tests for Stage 3 Graceful Fallback Engine."""

import unittest
from pandugizi import (
    GracefulFallbackEngine,
    PatientProfile,
    TriggerReason,
)


class TestGracefulFallback(unittest.TestCase):
    def setUp(self):
        self.fallback = GracefulFallbackEngine()

    def test_fallback_generates_action_and_message(self):
        patient = PatientProfile(id="P01", nama="Ibu Aminah", kategori="ibu hamil")
        triggers = [
            TriggerReason(
                rule="active_complaint",
                reason="Active complaint recorded",
                weight=5,
                reason_id="Keluhan aktif pasien",
            )
        ]

        out = self.fallback.generate(patient, triggers)
        self.assertEqual(out.source, "graceful_fallback")
        self.assertTrue(len(out.recommended_action) > 10)
        self.assertIn("Puskesmas", out.whatsapp_message)
        self.assertIn("Ibu Aminah", out.whatsapp_message)

    def test_dominant_rule_selection(self):
        patient = PatientProfile(id="P02", nama="Adik Bintang", kategori="balita")
        # active_complaint (5) vs medication (3)
        triggers = [
            TriggerReason(rule="medication_not_reported_today", reason="No meds", weight=3),
            TriggerReason(rule="active_complaint", reason="Complaint", weight=5),
        ]
        out = self.fallback.generate(patient, triggers)
        # Should pick active_complaint
        self.assertIn("keluhan aktif", out.recommended_action.lower())


if __name__ == "__main__":
    unittest.main()
