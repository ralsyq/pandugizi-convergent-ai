"""Stage 2: Constrained LLM Generative Synthesis Agent.

Implements Algorithm 1 (Stage 2) of the PanduGizi Convergent AI framework.
Synthesizes clinical recommendations for healthcare professionals and empathetic
WhatsApp communications for patients under strict, immutable guardrails.
"""

import json
import re
import time
from abc import ABC, abstractmethod
from typing import Dict, Any, Optional, List, Tuple
from .models import PatientProfile, ScoringResult, TriggerReason, DualOutput
from .config import PRIORITY_LABELS_ID


SYSTEM_INSTRUCTION_GUARDRAILS = """Anda adalah asisten tenaga kesehatan gizi di Puskesmas di Indonesia.
Anda menerima hasil asesmen prioritas tindak lanjut yang sudah dihitung oleh sistem berbasis aturan deterministik.
Skor dan tingkat prioritas sudah FINAL: JANGAN mengubah, menghitung ulang, atau membantahnya.
Tugas Anda adalah menyusun narasi tindak lanjut yang personal dan actionable berdasarkan data pasien dan alasan pemicu yang diberikan.
Pertimbangkan riwayat monitoring dan keluhan yang tersedia untuk membuat rekomendasi yang spesifik ke kondisi pasien.
JANGAN membuat diagnosis medis definitif dan JANGAN meresepkan obat; semua keputusan klinis tetap berada pada tenaga kesehatan.

Jawaban akhir HARUS berupa JSON valid tanpa teks lain di luar format JSON, dengan bentuk:
{
  "recommended_action": "...",
  "whatsapp_message": "..."
}

Panduan Penulisan:
1. Field 'recommended_action' ditujukan untuk tenaga kesehatan:
   - Gunakan bahasa klinis formal, spesifik, bernomor, dan actionable.
   - Fokus pada rencana intervensi, konseling gizi, dan validasi antropometri.
2. Field 'whatsapp_message' ditujukan untuk pasien/keluarga via WhatsApp:
   - WAJIB menggunakan enter / baris baru ganda (\\n\\n) antar paragraf agar mudah dibaca dan tidak menumpuk.
   - Gunakan emoji yang sopan dan relevan (👋, 🥗, 🩺, 🤰, 👶, 💊, ✨, 📌, 💡) agar pesan hangat dan komunikatif.
   - Gunakan formatting WhatsApp seperti *tebal* (*bold*) pada kata kunci atau judul bagian.
   - Susun dalam alur terstruktur: (a) Salam pembuka hangat, (b) Catatan kondisi/keluhan gizi, (c) Tips praktis asupan makanan/suplemen, (d) Ajakan tindak lanjut/kunjungan ke Puskesmas, (e) Kalimat penutup penyemangat."""


class BaseLlmAgent(ABC):
    """Abstract base class for generative LLM agents."""

    max_retries: int = 1
    retry_delay: float = 0.01

    @abstractmethod
    def call_api(self, prompt: str, system_instruction: str) -> str:
        """Invokes the underlying LLM provider."""
        pass

    def build_prompt(
        self,
        patient: PatientProfile,
        scoring: ScoringResult,
        trigger_reasons: List[TriggerReason],
    ) -> str:
        """Builds the structured clinical prompt constraining the generative model."""
        lines = [
            "=== DATA ASESMEN PASIEN ===",
            f"Nama: {patient.nama}",
            f"Kategori: {patient.kategori}",
            f"Skor Prioritas: {scoring.priority_score}",
            f"Tingkat Prioritas (FINAL): {scoring.priority_level.upper()} ({PRIORITY_LABELS_ID.get(scoring.priority_level, '')})",
            "",
            "=== ALASAN PEMICU TINDAK LANJUT ===",
        ]

        for i, tr in enumerate(trigger_reasons, 1):
            lines.append(f"{i}. {tr.reason_id or tr.reason} (bobot: {tr.weight})")
            if tr.evidence:
                for k, v in tr.evidence.items():
                    lines.append(f"   - {k}: {v}")

        lines.append("")
        lines.append("=== RIWAYAT MONITORING GIZI ===")
        if patient.monitoring_history:
            for item in patient.monitoring_history:
                lines.append(
                    f"{item.get('tanggal', '-')}: BB={item.get('berat_badan', '-')} kg, "
                    f"TB={item.get('tinggi_badan', '-')} cm, Status={item.get('status_gizi', '-')}"
                )
        else:
            lines.append("(Tidak ada riwayat monitoring sebelumnya)")

        lines.append("")
        lines.append("=== KELUHAN AKTIF ===")
        if patient.complaint_history or patient.has_active_complaint:
            if patient.has_active_complaint:
                lines.append(
                    f"Keluhan saat ini: [{patient.complaint_severity}] {patient.complaint_description}"
                )
            for c in patient.complaint_history:
                lines.append(f"{c.get('tanggal', '-')}: {c.get('deskripsi', '-')}")
        else:
            lines.append("(Tidak ada keluhan aktif)")

        lines.append("")
        lines.append(
            "Berdasarkan konteks di atas, susun rekomendasi tindakan klinis ('recommended_action') "
            "dan draf pesan WhatsApp ('whatsapp_message') dalam format JSON valid."
        )

        return "\n".join(lines)

    def parse_response(self, raw_text: str) -> Tuple[Optional[str], Optional[str]]:
        """Extracts and sanitizes JSON payload containing recommended_action and whatsapp_message."""
        if not raw_text or not raw_text.strip():
            return None, None

        # Remove markdown code fences
        clean = re.sub(r"^```(?:json)?\s*|\s*```$", "", raw_text.strip(), flags=re.MULTILINE)

        # Match JSON block between first { and last }
        match = re.search(r"\{[\s\S]*\}", clean)
        if match:
            clean = match.group(0)

        try:
            data = json.loads(clean)
        except json.JSONDecodeError:
            # Fallback regex extraction
            action_m = re.search(r'"recommended_action"\s*:\s*"([^"]+)"', clean)
            wa_m = re.search(r'"whatsapp_message"\s*:\s*"([^"]+)"', clean)
            action = action_m.group(1) if action_m else None
            wa = wa_m.group(1) if wa_m else None
            return action, wa

        if isinstance(data, dict):
            action = data.get("recommended_action")
            wa = data.get("whatsapp_message")
            return (str(action).strip() if action else None, str(wa).strip() if wa else None)

        return None, None

    def generate(
        self,
        patient: PatientProfile,
        scoring: ScoringResult,
        trigger_reasons: List[TriggerReason],
        max_retries: Optional[int] = None,
        retry_delay: Optional[float] = None,
    ) -> DualOutput:
        """Generates dual recommendations adhering to strict clinical guardrails."""
        retries = max_retries if max_retries is not None else self.max_retries
        delay = retry_delay if retry_delay is not None else self.retry_delay
        prompt = self.build_prompt(patient, scoring, trigger_reasons)
        start_time = time.time()

        for attempt in range(retries + 1):
            try:
                raw_output = self.call_api(prompt, SYSTEM_INSTRUCTION_GUARDRAILS)
                action, wa_msg = self.parse_response(raw_output)

                if action and wa_msg:
                    latency = (time.time() - start_time) * 1000
                    return DualOutput(
                        recommended_action=action,
                        whatsapp_message=wa_msg,
                        source="llm_agent",
                        latency_ms=latency,
                    )
            except Exception as e:
                if attempt >= retries:
                    raise e
                if delay > 0:
                    time.sleep(delay)

        raise RuntimeError("Failed to obtain valid JSON output from LLM agent.")


class MockAgent(BaseLlmAgent):
    """High-fidelity offline mock agent for testing and academic reproduction without API keys.
    
    Can simulate both constrained convergent generation and unconstrained baseline drifts.
    """

    def __init__(
        self,
        inject_latency: bool = False,
        simulate_timeout: bool = False,
        simulate_hallucination: bool = False,
        sleep_duration: float = 0.005,
    ):
        self.inject_latency = inject_latency
        self.simulate_timeout = simulate_timeout
        self.simulate_hallucination = simulate_hallucination
        self.sleep_duration = sleep_duration

    def call_api(self, prompt: str, system_instruction: str) -> str:
        if self.simulate_timeout:
            if self.inject_latency:
                time.sleep(self.sleep_duration)
            raise TimeoutError("Simulated LLM API Timeout Exception.")

        if self.inject_latency:
            time.sleep(self.sleep_duration)

        # High-quality realistic synthesis derived from prompt
        if "balita" in prompt.lower():
            if "stunting" in prompt.lower() or "buruk" in prompt.lower():
                payload = {
                    "recommended_action": (
                        "1. Jadwalkan konsultasi gizi tatap muka dan evaluasi antropometri ulang "
                        "(BB/TB) balita dalam 48 jam.\n"
                        "2. Lakukan audit asupan nutrisi harian (food recall 24 jam) serta pola pemberian makan.\n"
                        "3. Berikan suplementasi zat gizi mikro dan makanan tambahan (PMT) pemulihan berprotein hewani tinggi."
                    ),
                    "whatsapp_message": (
                        "Halo Bunda & Adik tercinta 👋👶\n\n"
                        "Salam hangat dari Puskesmas. Berdasarkan catatan pemantauan tumbuh kembang terakhir, "
                        "kami ingin mengajak Ayah/Bunda untuk berkonsultasi sejenak mengenai perkembangan si kecil. 🥗\n\n"
                        "Yuk sempatkan hadir ke Puskesmas minggu ini untuk pengukuran ulang dan bincang gizi sehat "
                        "bersama ahli gizi kami ✨. Bersama kita kawal tumbuh kembang optimal si kecil! 🩺"
                    ),
                }
            else:
                payload = {
                    "recommended_action": (
                        "1. Lanjutkan pemantauan tumbuh kembang rutin pada jadwal Posyandu berikutnya.\n"
                        "2. Edukasi orang tua mengenai variasi makanan padat gizi seimbang."
                    ),
                    "whatsapp_message": (
                        "Halo Ayah dan Bunda 👋👶\n\n"
                        "Terima kasih atas perhatiannya dalam menjaga kesehatan si kecil. "
                        "Tetap pertahankan pola makan bergizi seimbang ya! 🥗✨\n\n"
                        "Sampai jumpa di jadwal posyandu berikutnya. Salam sehat selalu dari Puskesmas! 🏥"
                    ),
                }
        else:
            # Pregnant Mother
            if "lila" in prompt.lower() or "kek" in prompt.lower():
                payload = {
                    "recommended_action": (
                        "1. Jadwalkan konsultasi antenatal care (ANC) terpadu di Puskesmas dalam waktu 48 jam.\n"
                        "2. Evaluasi status nutrisi dan lingkar lengan atas (LILA) serta berikan konseling menu gizi seimbang.\n"
                        "3. Distribusikan biskuit PMT Ibu Hamil KEK dan verifikasi kepatuhan konsumsi tablet tambah darah (TTD)."
                    ),
                    "whatsapp_message": (
                        "Halo Ibu tercinta 👋🤰\n\n"
                        "Semoga Ibu dan calon buah hati senantiasa sehat. Berdasarkan pemantauan catatan kesehatan, "
                        "kami mengundang Ibu berkunjung ke Puskesmas untuk evaluasi lingkar lengan dan bincang nutrisi bersama dokter. 🩺\n\n"
                        "Jangan lupa untuk tetap rutin meminum suplemen tambah darah dan istirahat yang cukup ya Bu ✨. "
                        "Kami siap mendampingi kehamilan Ibu hingga persalinan! 💡"
                    ),
                }
            else:
                payload = {
                    "recommended_action": (
                        "1. Pertahankan jadwal pemeriksaan kehamilan berkala (ANC) sesuai trimester.\n"
                        "2. Berikan apresiasi atas kedisiplinan konsumsi vitamin dan pola makan sehat."
                    ),
                    "whatsapp_message": (
                        "Halo Ibu tercinta 👋🤰\n\n"
                        "Terima kasih sudah disiplin menjaga kesehatan dan asupan gizi selama kehamilan. "
                        "Ibu dan calon buah hati luar biasa! ✨🥗\n\n"
                        "Tetap jaga pola makan dan istirahat yang cukup ya Bu. Sampai bertemu di jadwal kontrol berikutnya! 🌸"
                    ),
                }

        if self.simulate_hallucination:
            payload["recommended_action"] += "\n[HALLUCINATION: Pasien didiagnosis gagal ginjal kronis stadium 4. Berikan antibiotik ciprofloxacin 500mg 3x sehari.]"

        return json.dumps(payload, ensure_ascii=False)


class GeminiAgent(BaseLlmAgent):
    """Live Google Gemini API client."""

    def __init__(self, api_key: str, model_name: str = "gemini-1.5-flash"):
        self.api_key = api_key
        self.model_name = model_name

    def call_api(self, prompt: str, system_instruction: str) -> str:
        import urllib.request
        import urllib.error

        url = (
            f"https://generativelanguage.googleapis.com/v1beta/models/{self.model_name}:generateContent"
            f"?key={self.api_key}"
        )

        body = {
            "contents": [
                {
                    "parts": [
                        {"text": f"SYSTEM INSTRUCTION:\n{system_instruction}\n\nUSER PROMPT:\n{prompt}"}
                    ]
                }
            ],
            "generationConfig": {
                "temperature": 0.2,
                "response_mime_type": "application/json",
            },
        }

        req = urllib.request.Request(
            url,
            data=json.dumps(body).encode("utf-8"),
            headers={"Content-Type": "application/json"},
            method="POST",
        )

        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                res_data = json.loads(resp.read().decode("utf-8"))
                candidates = res_data.get("candidates", [])
                if candidates:
                    parts = candidates[0].get("content", {}).get("parts", [])
                    if parts:
                        return parts[0].get("text", "")
        except urllib.error.HTTPError as e:
            error_body = e.read().decode("utf-8")
            raise RuntimeError(f"Gemini API error ({e.code}): {error_body}")
        except Exception as e:
            raise RuntimeError(f"Gemini API call failed: {str(e)}")

        raise RuntimeError("No text returned by Gemini API")


class GroqAgent(BaseLlmAgent):
    """Live Groq API client."""

    def __init__(self, api_key: str, model_name: str = "llama-3.3-70b-versatile"):
        self.api_key = api_key
        self.model_name = model_name

    def call_api(self, prompt: str, system_instruction: str) -> str:
        import urllib.request
        import urllib.error

        url = "https://api.groq.com/openai/v1/chat/completions"
        body = {
            "model": self.model_name,
            "messages": [
                {"role": "system", "content": system_instruction},
                {"role": "user", "content": prompt},
            ],
            "temperature": 0.2,
            "response_format": {"type": "json_object"},
        }

        req = urllib.request.Request(
            url,
            data=json.dumps(body).encode("utf-8"),
            headers={
                "Content-Type": "application/json",
                "Authorization": f"Bearer {self.api_key}",
            },
            method="POST",
        )

        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                res_data = json.loads(resp.read().decode("utf-8"))
                return res_data["choices"][0]["message"]["content"]
        except Exception as e:
            raise RuntimeError(f"Groq API call failed: {str(e)}")
