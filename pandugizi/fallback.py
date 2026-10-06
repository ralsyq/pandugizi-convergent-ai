"""Stage 3: Automated Graceful Degradation & Deterministic Template Engine.

Implements the resilience mechanism of PanduGizi Convergent AI.
Guarantees uninterrupted operational continuity during external API latency,
rate-limiting, or connection dropouts without crashing or emitting hallucinations.
"""

from typing import List, Optional
from .models import PatientProfile, TriggerReason, DualOutput
from .config import DOMINANT_RULE_ORDER, PRIORITY_LABELS_ID


class GracefulFallbackEngine:
    """Deterministic template generator used when generative LLM is unavailable."""

    CLINICAL_ACTIONS = {
        "active_complaint": (
            "Prioritaskan tindak lanjut oleh tenaga kesehatan untuk mengevaluasi keluhan aktif "
            "pasien dan menentukan kebutuhan pemeriksaan lanjutan."
        ),
        "pregnant_low_lila": (
            "Jadwalkan tindak lanjut untuk evaluasi risiko Kekurangan Energi Kronis (KEK) "
            "dan edukasi pemantauan asupan gizi ibu hamil."
        ),
        "toddler_stunting_status": (
            "Jadwalkan evaluasi antropometri ulang dan koordinasikan pemantauan status "
            "gizi balita sesuai alur layanan yang berlaku."
        ),
        "toddler_risk_nutrition_status": (
            "Lakukan pemantauan lanjutan terhadap status gizi balita dan pertimbangkan "
            "edukasi gizi kepada orang tua atau wali pasien."
        ),
        "latest_monitoring_indicates_risk": (
            "Tinjau kembali hasil monitoring gizi terakhir dan pertimbangkan tindak "
            "lanjut pemantauan sesuai kondisi pasien."
        ),
        "monitoring_overdue": (
            "Jadwalkan ulang monitoring gizi karena data pemantauan terakhir telah "
            "melewati batas waktu pemantauan rutin."
        ),
        "no_monitoring_record": (
            "Lakukan pencatatan monitoring gizi awal agar tenaga kesehatan dapat menilai "
            "perkembangan status gizi pasien."
        ),
        "medication_not_reported_today": (
            "Lakukan pengingat dan verifikasi kepatuhan pencatatan konsumsi obat atau "
            "suplementasi sesuai jadwal yang telah diberikan."
        ),
        "supplementary_food_not_reported_today": (
            "Lakukan pengingat dan verifikasi pencatatan konsumsi Makanan Tambahan (PMT) "
            "sesuai jadwal pemantauan."
        ),
        "pregnant_nutrition_risk_status": (
            "Lakukan pendampingan gizi intensif untuk memantau kenaikan berat badan dan "
            "asupan nutrisi ibu hamil secara teratur."
        ),
    }

    WHATSAPP_TEMPLATES = {
        "active_complaint": (
            "{greeting} 👋,\n\n"
            "Ini pesan dari petugas kesehatan *Puskesmas*. 🩺\n\n"
            "📌 Kami menerima catatan keluhan yang Ibu/Bapak sampaikan dan membutuhkan "
            "perhatian lebih lanjut dari tim medis.\n\n"
            "💡 Mohon memantau perkembangan kondisi serta mengikuti arahan petugas, atau segera "
            "berkunjung ke Puskesmas bila keluhan berlanjut ya.\n\n"
            "Semoga lekas pulih dan sehat selalu! ✨"
        ),
        "pregnant_low_lila": (
            "{greeting} 👋,\n\n"
            "Ini pesan perhatian dari petugas gizi *Puskesmas*. 🤰\n\n"
            "📌 Berdasarkan catatan pemantauan kehamilan, ukuran lingkar lengan (LILA) memerlukan "
            "perhatian dan pendampingan nutrisi khusus.\n\n"
            "💡 Kami mengundang Ibu untuk berkonsultasi ke Puskesmas agar mendapatkan panduan menu "
            "gizi seimbang dan makanan tambahan (PMT Bumil).\n\n"
            "Semoga Ibu dan calon buah hati senantiasa sehat dan kuat! ✨"
        ),
        "toddler_stunting_status": (
            "{greeting} 👋,\n\n"
            "Ini pesan perhatian gizi anak dari *Puskesmas*. 👶\n\n"
            "📌 Hasil pemantauan pertumbuhan si kecil menunjukkan indikasi perlunya evaluasi "
            "dan stimulasi nutrisi tambahan bersama dokter/ahli gizi.\n\n"
            "💡 Kami sangat menyarankan Ayah/Bunda untuk mengajak si kecil berkunjung ke Puskesmas "
            "guna pemeriksaan tumbuh kembang lebih lanjut.\n\n"
            "Mari bersama kawal tumbuh kembang optimal anak kita! ✨"
        ),
        "toddler_risk_nutrition_status": (
            "{greeting} 👋,\n\n"
            "Ini pesan pemantauan gizi anak dari *Puskesmas*. 👶\n\n"
            "📌 Pertumbuhan si kecil saat ini berada pada batas yang perlu dioptimalkan asupannya.\n\n"
            "💡 Mohon pastikan asupan protein hewani dan jadwal makan teratur, serta silakan "
            "berkonsultasi ke Puskesmas bila ada kendala makan ya.\n\n"
            "Semoga si kecil tumbuh sehat, aktif, dan ceria! ✨"
        ),
        "monitoring_overdue": (
            "{greeting} 👋,\n\n"
            "Ini pengingat jadwal pemantauan dari *Puskesmas*. 📊\n\n"
            "📌 Jadwal monitoring gizi rutin Anda sudah melewati batas waktu pemantauan berkala.\n\n"
            "💡 Mari luangkan waktu untuk mencatat perkembangan tinggi/berat badan atau berkunjung "
            "ke Puskesmas agar status gizi tetap terpantau dengan baik.\n\n"
            "Terima kasih atas kerja samanya! ✨"
        ),
        "no_monitoring_record": (
            "{greeting} 👋,\n\n"
            "Ini pesan ramah dari tim gizi *Puskesmas*. 🏥\n\n"
            "📌 Data pemantauan gizi awal Anda belum tercatat di sistem kami.\n\n"
            "💡 Yuk luangkan waktu untuk berkunjung ke Posyandu atau Puskesmas agar kami dapat "
            "memantau status kesehatan dan gizi keluarga tercinta sejak dini.\n\n"
            "Salam sehat selalu! ✨"
        ),
        "medication_not_reported_today": (
            "{greeting} 👋,\n\n"
            "Ini pengingat dari *Puskesmas* terkait jadwal suplemen/obat. 💊\n\n"
            "📌 Catatan konsumsi obat atau suplemen gizi hari ini belum terisi di aplikasi.\n\n"
            "💡 Jangan lupa dikonsumsi sesuai petunjuk dokter dan centang laporannya ya.\n\n"
            "Semangat selalu dalam menjaga kesehatan! ✨"
        ),
        "supplementary_food_not_reported_today": (
            "{greeting} 👋,\n\n"
            "Ini pengingat konsumsi Makanan Tambahan (PMT) dari *Puskesmas*. 🥗\n\n"
            "📌 Laporan pemberian makanan tambahan untuk hari ini belum tercatat di sistem.\n\n"
            "💡 Pastikan makanan tambahan dikonsumsi dengan baik demi pemenuhan nutrisi optimal ya.\n\n"
            "Terima kasih dan sehat selalu! ✨"
        ),
    }

    DEFAULT_ACTION = (
        "Lanjutkan pemantauan gizi rutin dan lakukan tindak lanjut sesuai kebutuhan layanan."
    )

    DEFAULT_WHATSAPP = (
        "{greeting} 👋,\n\n"
        "Ini pengingat dari petugas gizi *Puskesmas*. 🏥\n\n"
        "📌 Berdasarkan evaluasi berkala, terdapat catatan kesehatan yang perlu "
        "ditindaklanjuti bersama tenaga kesehatan.\n\n"
        "💡 Mohon dapat melakukan pembaruan data atau menghubungi Puskesmas untuk "
        "konsultasi lebih lanjut ya.\n\n"
        "Terima kasih banyak, semoga sehat selalu! ✨"
    )

    def generate(
        self,
        patient: PatientProfile,
        trigger_reasons: List[TriggerReason],
        latency_ms: float = 0.0,
    ) -> DualOutput:
        """Generates deterministic dual recommendations from active triggers.
        
        Args:
            patient: The patient profile.
            trigger_reasons: Active clinical and administrative triggers.
            latency_ms: Measured latency before fallback was triggered.
            
        Returns:
            DualOutput populated with verified deterministic templates.
        """
        dominant_rule = self._find_dominant_rule(trigger_reasons)
        greeting = self._build_greeting(patient.nama)

        # 1. Recommended Action for Clinician
        rec_action = self.CLINICAL_ACTIONS.get(dominant_rule, self.DEFAULT_ACTION)

        # 2. WhatsApp Message for Patient
        template = self.WHATSAPP_TEMPLATES.get(dominant_rule, self.DEFAULT_WHATSAPP)
        whatsapp_msg = template.format(greeting=greeting)

        return DualOutput(
            recommended_action=rec_action,
            whatsapp_message=whatsapp_msg,
            source="graceful_fallback",
            latency_ms=latency_ms,
        )

    def _find_dominant_rule(self, trigger_reasons: List[TriggerReason]) -> Optional[str]:
        if not trigger_reasons:
            return None

        highest_weight = -1
        candidate_rules = []

        for tr in trigger_reasons:
            if tr.weight > highest_weight:
                highest_weight = tr.weight
                candidate_rules = [tr.rule]
            elif tr.weight == highest_weight:
                candidate_rules.append(tr.rule)

        for rule in DOMINANT_RULE_ORDER:
            if rule in candidate_rules:
                return rule

        return candidate_rules[0] if candidate_rules else None

    def _build_greeting(self, name: str) -> str:
        clean = (name or "").strip()
        if not clean or clean.lower() in ["ibu/bapak", "pasien"]:
            return "Halo Ibu/Bapak"
        return f"Halo Ibu/Bapak {clean}"
