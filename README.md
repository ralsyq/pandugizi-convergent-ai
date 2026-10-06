# PanduGizi: Convergent AI Architecture for Maternal & Child Nutritional Follow-Up

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![Python: 3.8+](https://img.shields.io/badge/Python-3.8%2B-green.svg)](https://www.python.org/)
[![Reproducibility](https://img.shields.io/badge/IEEE%20Artifact-Reproducible-success.svg)]()
[![Dataset: Zenodo CC-BY 4.0](https://img.shields.io/badge/Dataset-CC--BY%204.0-orange.svg)](data/)

Official research replication package and standalone implementation for the paper:

> **"PanduGizi: An Explainable and Generative Convergent AI Architecture for Maternal and Child Nutritional Follow-Up in Primary Healthcare"**  
> *Raisya Putri Agustin and Eka Miranda*  
> Information Systems Department, School of Information Systems, Bina Nusantara University, Jakarta, Indonesia 11480  
> Correspondence: `raisya.agustin@binus.ac.id`, `ekamiranda@binus.ac.id`  
> *IEEE / ICAIDES 2026 Submission*

---

## 📌 Executive Summary

Nutritional monitoring in primary community health centers (Puskesmas) requires healthcare providers to synthesize multidimensional patient-reported observations: supplementary food (PMT) consumption, iron-folic acid medication adherence, acute clinical complaints, and anthropometric trajectories (maternal Upper Arm Circumference / LILA and infant height/weight-for-age).

While conventional symbolic rule-based clinical decision support systems offer deterministic safety and auditability, their static boilerplate notifications lack empathy and engagement. Conversely, unconstrained large language models (LLMs) pose grave risks of medical hallucination, stochastic triage drift, and lack of reproducible guardrails.

**PanduGizi resolves this fundamental dilemma through Convergent AI:**
1. **Stage 1 (Deterministic Clinical Anchor):** A symbolic, multi-attribute rule engine computes immutable priority scores ($S$) and risk tiers ($L \in \{\text{Low}, \text{Medium}, \text{High}\}$), detailing exact clinical triggers with zero stochastic drift.
2. **Stage 2 (Constrained Generative Synthesis):** An agentic generative language model (Google Gemini / Groq) operates strictly within these deterministic guardrails, producing dual synchronized artifacts:
   - `recommended_action`: A structured, numbered clinical action plan for Puskesmas medical officers.
   - `whatsapp_message`: An empathetic, personalized WhatsApp notification for mothers/caregivers formatted with culturally resonant greetings, dietary tips, and action calls.
3. **Stage 3 (Resilience & Graceful Degradation):** When external API latency or network dropouts occur, an automated fallback engine deterministically generates verified clinical action plans and WhatsApp templates without crashing or emitting hallucinations (0% crash rate).
4. **Human-in-the-Loop Oversight:** Attending healthcare personnel retain final clinical authority on the dashboard before message dispatch.

---

## 🏛️ Convergent AI Pipeline Architecture

```
                      +------------------------------------------+
                      |   PATIENT-REPORTED & CLINICAL SIGNALS    |
                      | (PMT intake, meds, LILA, z-score, visit) |
                      +------------------------------------------+
                                           |
                                           v
    [STAGE 1]  +--------------------------------------------------------+
 DETERMINISTIC |         Deterministic Symbolic Scoring Engine          |
    GUARDRAILS |  S = sum(w_i * x_i)  -->  Priority Tier: L in {L, M, H} |
               |     Identifies Immutable Trigger Reasons & Weights     |
               +--------------------------------------------------------+
                                           |
                          +----------------+----------------+
                          | (Normal Network Flow)           | (API Failure/Timeout)
                          v                                 v
    [STAGE 2]  +----------------------+           +---------------------+ [STAGE 3]
  CONSTRAINED  |  Constrained LLM     |           | Automated Graceful  | RESILIENCE
    GENERATIVE |  Generative Agent    |           | Fallback Engine     | FALLBACK
     SYNTHESIS |  (Prompt Guardrails: |           | (Rule-Specific      |
               |   Invariant Priority)|           |  Verified Templates)|
               +----------------------+           +---------------------+
                          |                                 |
                          +----------------+----------------+
                                           |
                                           v
                               +-----------------------+
                               |     DUAL OUTPUTS      |
                               | 1. Clinical Action    |
                               | 2. Empathetic WA Msg  |
                               +-----------------------+
                                           |
                                           v
                               +-----------------------+
                               |  HUMAN-IN-THE-LOOP    |
                               |  Doctor / Nutritionist|
                               |  Review & Dispatch    |
                               +-----------------------+
```

---

## 📐 Algorithm 1: Two-Stage Convergent Follow-Up Pipeline

$$\begin{aligned}
\textbf{Require:} & \quad \text{Patient data } M, \text{ History } H, \text{ Weights } W = \{w_1, \dots, w_6\}, \text{ Thresholds } \{T_1, T_2\} \\
\textbf{Ensure:}  & \quad \text{Action Plan } Rec, \text{ WhatsApp Message } Msg, \text{ Priority Level } L
\end{aligned}$$

1. $Score \leftarrow 0; \quad Triggers \leftarrow \emptyset$
2. **if** $MissedMedication(M)$ **or** $MissedPMT(M)$ **then** $Score \leftarrow Score + w_1; Triggers \leftarrow Triggers \cup \{\text{missed\_intake}\}$
3. **if** $PoorFoodRecord(M)$ **then** $Score \leftarrow Score + w_2; Triggers \leftarrow Triggers \cup \{\text{poor\_diet}\}$
4. **if** $ActiveComplaint(M)$ **then** $Score \leftarrow Score + w_3; Triggers \leftarrow Triggers \cup \{\text{active\_complaint}\}$
5. $\quad$ **if** $Severity(M) \ge \text{Moderate}$ **then** $Score \leftarrow Score + w_4$
6. **if** $ScheduleOverdue(M) > 30\text{ days}$ **then** $Score \leftarrow Score + w_5; Triggers \leftarrow Triggers \cup \{\text{monitoring\_overdue}\}$
7. **if** $AnthropometricRisk(M)$ ($LILA < 23.5\text{cm}$ or $Stunting$) **then** $Score \leftarrow Score + w_6; Triggers \leftarrow Triggers \cup \{\text{anthropometric\_risk}\}$
8. **if** $Score < T_1$ **then** $L \leftarrow \text{'Low (Routine)'}$
9. **else if** $Score < T_2$ **then** $L \leftarrow \text{'Medium (Reminder)'}$
10. **else** $L \leftarrow \text{'High (Urgent)'}$
11. **try:**
12. $\quad Prompt \leftarrow AssemblePrompt(M, H, Score, L, Triggers)$
13. $\quad Output \leftarrow LLMAgent.Generate(Prompt, Guardrails=\{InvariantPriority: L, NoPrescription: true\})$
14. $\quad Rec \leftarrow Output.recommended\_action; \quad Msg \leftarrow Output.whatsapp\_message$
15. **catch** $APIException \text{ or } Timeout$:
16. $\quad Rec \leftarrow FallbackAction(Triggers, L); \quad Msg \leftarrow FallbackTemplate(M, Triggers, L)$
17. $\{Rec, Msg\} \leftarrow ClinicianReviewAndApprove(Rec, Msg, L)$
18. **return** $\{Rec, Msg, L, Score\}$

---

## 📊 Table I: Clinical Indicators & Calibrated Scoring Rubric

Calibrated in collaboration with clinical staff at Puskesmas Sawah Besar:

| Indicator Category | Clinical Condition & Data Source | Weight | Priority Impact |
| :--- | :--- | :---: | :--- |
| **Acute Complaints** | Active unresolved maternal/infant symptom | **+5** | Urgent clinical review |
| **Maternal CED** | Upper Arm Circumference (LILA) $< 23.5\text{ cm}$ | **+5** | High risk of fetal growth restriction |
| **Infant Stunting** | Anthropometric Z-score indicates stunting/wasting | **+5** | Urgent developmental triage |
| **At-Risk Nutrition** | Anthropometric velocity flagged borderline risk | **+4** | Nutrition counseling required |
| **Monitoring Overdue** | Routine check-up overdue $> 30\text{ days}$ | **+4** | Attendance reminder |
| **Data Absence** | Zero baseline monitoring records registered | **+4** | Baseline enrollment required |
| **Medication Non-adherence** | Iron-folic acid / micronutrient unlogged today | **+3** | Daily adherence reminder |
| **PMT Non-adherence** | Supplementary food consumption unlogged today | **+3** | Nutritional adherence reminder |

**Priority Thresholds:**
- **Low Priority ($Score < 4$):** Routine monitoring schedule.
- **Medium Priority ($4 \le Score < 8$):** Contextual follow-up reminder.
- **High Priority ($Score \ge 8$):** Immediate clinical intervention & outreach.

---

## 🏆 Empirical Results (Section V Reproduction)

Empirical evaluation results comparing the **Baseline (Unconstrained Pure LLM)** against the **Proposed Convergent AI Architecture** across $N = 100$ simulated primary care cycles:

| Evaluation Metric | Baseline Pure LLM | Convergent AI (Proposed) | Clinical Significance |
| :--- | :---: | :---: | :--- |
| **Medical Hallucination Rate** | 34.0% | **0.0%** | Zero clinical confabulation |
| **Priority Override Drift Rate** | 18.0% | **0.0%** | Strict preservation of institutional triage |
| **Triage Concordance (Safety Compliance)** | 82.0% | **100.0%** | Complete safety compliance |
| **Graceful Degradation Handled** | N/A (Unhandled crash) | **100.0%** | Fault-tolerant operation |
| **Unhandled System Crashes** | High risk | **0 (0.0% crash rate)** | Uninterrupted service continuity |
| **Concordance on 30 Audited Paper Cases** | 80.0% | **30/30 (100.0%)** | Complete validation on clinical benchmark |

---

## 🚀 Quick Start & Reproduction in Under 2 Minutes

The core research artifact has **zero mandatory third-party C-extensions** and runs on any standard Python 3.8+ environment.

### 1. Installation

```bash
# Clone the repository
git clone https://github.com/raisya-agustin/pandugizi-convergent-ai.git
cd pandugizi-convergent-ai

# (Optional) Create virtual environment
python3 -m venv venv
source venv/bin/activate

# Install development/test dependencies
pip install -r requirements.txt
```

### 2. Run Interactive Clinical Demonstrations (Table II from Paper)

Demonstrates Case A (High-risk pregnant mother), Case B (High-risk toddler), Case C (Routine mother), and Case D (Automated graceful fallback):

```bash
python3 demo.py
```

### 3. Reproduce the 30 Audited Clinical Cases

Verifies that all 30 audited cases from `data/synthetic_patient_cases_30.json` achieve 100% concordance with zero hallucinations:

```bash
python3 benchmark/evaluate_paper_cases.py
```

### 4. Run the Comparative Benchmark (Baseline vs Convergent AI)

Simulates 100 cases under network degradation, outputting the exact empirical comparison table for Section V:

```bash
python3 benchmark/run_benchmark.py
```

### 5. Run the Automated Unit Test Suite

```bash
python3 -m unittest discover tests
# or using pytest:
pytest tests/ -v
```

---

## 📁 Repository Directory Structure

```
pandugizi-convergent-ai/
├── README.md                          # Academic artifact documentation & reproduction guide
├── LICENSE                            # Open-source MIT License
├── CITATION.cff                       # Citation metadata for IEEE ICAIDES 2026
├── requirements.txt                   # Minimal lightweight dependencies
├── setup.py                           # Python package distribution definition
├── demo.py                            # Interactive CLI demonstration of Table II clinical cases
│
├── pandugizi/                         # Core Convergent AI Python Package
│   ├── __init__.py                    # Public API exports
│   ├── config.py                      # Rule weights (W1-W6), thresholds (T1=4, T2=8), labels
│   ├── models.py                      # PatientProfile, ScoringResult, TriggerReason, DualOutput
│   ├── scoring.py                     # Stage 1: Deterministic Symbolic Rule-Based Scoring Engine
│   ├── agent.py                       # Stage 2: Constrained LLM Generative Agent (Gemini/Groq/Mock)
│   ├── fallback.py                    # Stage 3: Automated Graceful Fallback & Template Engine
│   └── pipeline.py                    # Algorithm 1: Two-Stage Pipeline Orchestrator
│
├── benchmark/                         # Evaluation and Benchmarking Suite
│   ├── __init__.py
│   ├── evaluate_paper_cases.py        # Reproduces 30-case clinical concordance evaluation
│   ├── run_benchmark.py               # Comparative benchmark runner (Pure LLM vs Convergent AI)
│   └── generator.py                   # Epidemiologically calibrated synthetic patient generator
│
├── data/                              # Benchmark Datasets (Zenodo CC-BY 4.0 Package)
│   ├── synthetic_patient_cases_30.json# 30 audited patient cases with reference triage
│   ├── scoring_rubric.json            # Calibrated clinical scoring rubric and thresholds
│   └── evaluated_benchmark_results.json # Empirical comparison results
│
├── tests/                             # Automated Unit Test Suite
│   ├── test_scoring.py                # Tests Stage 1 rule calculation and boundaries
│   ├── test_agent.py                  # Tests Stage 2 prompt constraints and JSON sanitization
│   ├── test_fallback.py               # Tests Stage 3 dominant rule resolution and templates
│   └── test_pipeline.py               # Tests end-to-end Algorithm 1 execution & resilience
│
└── reference_implementation/          # Production Laravel 10 / PHP 8.2 Hospital Backend
    ├── README.md                      # Mapping between PHP services and Python core
    ├── FollowUpScoringService.php     # Production deterministic scoring service
    ├── FollowUpRecommendationService.php # Production recommendation workflow service
    ├── BaseFollowUpAgentService.php   # Production LLM agent base class
    ├── GeminiFollowUpAgentService.php # Production Google Gemini API client
    ├── GroqFollowUpAgentService.php   # Production Groq API client
    ├── FollowUpMessageBuilderService.php # Production deterministic WhatsApp template builder
    └── RunSystemBenchmark.php         # Production Laravel Artisan benchmark command
```

---

## 🔑 Optional: Live Generative API Integration

By default, the replication suite runs using the built-in `MockAgent` with zero API keys required, allowing reviewers to evaluate the complete architecture offline.

To test against live Large Language Models:

### Google Gemini (gemini-1.5-flash / gemini-2.0)
```python
from pandugizi import ConvergentPipeline, GeminiAgent

agent = GeminiAgent(api_key="YOUR_GEMINI_API_KEY", model_name="gemini-1.5-flash")
pipeline = ConvergentPipeline(llm_agent=agent)
result = pipeline.process(patient)
```

### Groq (llama-3.3-70b-versatile)
```python
from pandugizi import ConvergentPipeline, GroqAgent

agent = GroqAgent(api_key="YOUR_GROQ_API_KEY", model_name="llama-3.3-70b-versatile")
pipeline = ConvergentPipeline(llm_agent=agent)
result = pipeline.process(patient)
```

---

## 🔒 Ethical Note & Data Privacy

All patient cases contained in `data/synthetic_patient_cases_30.json` and generated by `benchmark/generator.py` are **entirely synthetic**, calibrated to demographic distributions and clinical parameter ranges observed at Puskesmas Sawah Besar, Jakarta. **Zero private health information (PHI) or real patient records are included in this repository.**

---

## 📜 Citation

If you use this Convergent AI architecture, benchmark dataset, or evaluation methodology in your research, please cite:

```bibtex
@inproceedings{agustin2026pandugizi,
  author    = {Agustin, Raisya Putri and Miranda, Eka},
  title     = {PanduGizi: An Explainable and Generative Convergent AI Architecture for Maternal and Child Nutritional Follow-Up in Primary Healthcare},
  booktitle = {Proceedings of the 2026 International Conference on Applied Artificial Intelligence and Digital Expert Systems (ICAIDES)},
  year      = {2026},
  address   = {Jakarta, Indonesia},
  publisher = {IEEE}
}
```

---

## 📄 License

This research codebase is licensed under the [MIT License](LICENSE).  
The synthetic benchmark dataset is licensed under the [Creative Commons Attribution 4.0 International License (CC-BY 4.0)](https://creativecommons.org/licenses/by/4.0/).
