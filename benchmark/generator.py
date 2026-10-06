"""Synthetic Patient Generator for Nutritional Triage Benchmark.

Calibrated against epidemiological distributions and clinical parameter ranges
observed in Indonesian primary healthcare (Puskesmas).
Generates purely synthetic records with ZERO private health information (PHI).
"""

import random
from typing import List, Dict, Any
from pandugizi.models import PatientProfile


def generate_synthetic_patients(num_cases: int = 100, seed: int = 42) -> List[PatientProfile]:
    """Generates synthetic patient profiles for benchmarking.
    
    Args:
        num_cases: Number of synthetic cases to generate.
        seed: Random seed for reproducibility.
        
    Returns:
        List of PatientProfile instances.
    """
    random.seed(seed)
    patients = []

    for i in range(num_cases):
        is_preg = random.choice([True, False])
        patient_id = f"SYN-{i+1:03d}"

        if is_preg:
            # Pregnant Mother profile
            kategori = "ibu hamil"
            nama = f"Ibu Hamil #{i+1}"
            lila = round(random.uniform(20.5, 27.0), 1) if random.random() < 0.85 else None
            status_gizi = "KEK" if (lila and lila < 23.5) else ("normal" if random.random() < 0.7 else "berisiko")
            tinggi = round(random.uniform(148.0, 165.0), 1)
            berat = round(random.uniform(45.0, 75.0), 1)
        else:
            # Toddler profile
            kategori = "balita"
            nama = f"Balita #{i+1}"
            lila = None
            status_choice = random.choice(["normal", "normal", "berisiko", "stunting", "gizi buruk"])
            status_gizi = status_choice
            tinggi = round(random.uniform(65.0, 95.0), 1)
            berat = round(random.uniform(7.0, 15.0), 1)

        has_complaint = random.random() < 0.35
        complaint_desc = None
        severity = "none"
        if has_complaint:
            severity = random.choice(["mild", "moderate", "severe"])
            if is_preg:
                complaint_desc = random.choice([
                    "Mual dan muntah di pagi hari, nafsu makan berkurang.",
                    "Pusing berputar dan lemas sejak 2 hari lalu.",
                    "Kaki bengkak dan cepat lelah saat beraktivitas.",
                ])
            else:
                complaint_desc = random.choice([
                    "Batuk pilek disertai demam ringan, nafsu makan turun drastis.",
                    "Diare ringan 3 kali sehari dan rewel.",
                    "Menolak makan makanan keluarga, hanya mau susu.",
                ])

        # Overdue monitoring
        has_monitoring = random.random() < 0.85
        days_since = random.randint(5, 60) if has_monitoring else None
        latest_risk = random.random() < 0.25 if has_monitoring else False

        missed_med = random.random() < 0.30
        missed_pmt = random.random() < 0.25

        patients.append(
            PatientProfile(
                id=patient_id,
                nama=nama,
                kategori=kategori,
                lila=lila,
                status_gizi=status_gizi,
                tinggi_badan=tinggi,
                berat_badan=berat,
                has_active_complaint=has_complaint,
                complaint_description=complaint_desc,
                complaint_severity=severity,
                complaint_category="Umum",
                days_since_last_monitoring=days_since,
                latest_monitoring_risk=latest_risk,
                missed_medication_today=missed_med,
                missed_pmt_today=missed_pmt,
            )
        )

    return patients
