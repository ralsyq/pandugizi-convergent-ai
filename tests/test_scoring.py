"""Unit tests for Stage 1 Deterministic Symbolic Rule-Based Scoring Engine."""

import unittest
from pandugizi import (
    DeterministicScoringEngine,
    PatientProfile,
    PRIORITY_LOW,
    PRIORITY_MEDIUM,
    PRIORITY_HIGH,
    LOW_LILA_THRESHOLD_CM,
)


class TestDeterministicScoring(unittest.TestCase):
    def setUp(self):
        self.engine = DeterministicScoringEngine()

    def test_routine_patient_scores_zero(self):
        patient = PatientProfile(
            id="P01",
            nama="Ibu Sehat",
            kategori="ibu hamil",
            lila=25.0,
            status_gizi="normal",
            days_since_last_monitoring=10,
        )
        res = self.engine.score(patient)
        self.assertEqual(res.priority_score, 0)
        self.assertEqual(res.priority_level, PRIORITY_LOW)
        self.assertEqual(len(res.trigger_reasons), 0)

    def test_pregnant_low_lila_triggers_five_points(self):
        patient = PatientProfile(
            id="P02",
            nama="Ibu KEK",
            kategori="ibu hamil",
            lila=21.0,  # Below 23.5 cm
            days_since_last_monitoring=10,
        )
        res = self.engine.score(patient)
        self.assertGreaterEqual(res.priority_score, 5)
        self.assertEqual(res.priority_level, PRIORITY_MEDIUM)
        rules = [tr.rule for tr in res.trigger_reasons]
        self.assertIn("pregnant_low_lila", rules)

    def test_toddler_stunting_and_overdue_reaches_high_priority(self):
        patient = PatientProfile(
            id="P03",
            nama="Balita Stunting",
            kategori="balita",
            status_gizi="stunting",  # +5 pts
            days_since_last_monitoring=45,  # +4 pts (overdue > 30 days)
        )
        res = self.engine.score(patient)
        self.assertEqual(res.priority_score, 9)
        self.assertEqual(res.priority_level, PRIORITY_HIGH)
        rules = [tr.rule for tr in res.trigger_reasons]
        self.assertIn("toddler_stunting_status", rules)
        self.assertIn("monitoring_overdue", rules)

    def test_active_complaint_and_missed_medication(self):
        patient = PatientProfile(
            id="P04",
            nama="Ibu Mual",
            kategori="ibu hamil",
            lila=24.0,
            has_active_complaint=True,  # +5 pts
            complaint_description="Mual berat",
            missed_medication_today=True,  # +3 pts
            days_since_last_monitoring=10,
        )
        res = self.engine.score(patient)
        self.assertEqual(res.priority_score, 8)
        self.assertEqual(res.priority_level, PRIORITY_HIGH)


if __name__ == "__main__":
    unittest.main()
