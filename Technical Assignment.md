# Vast — Staff Software Engineer Technical Assessment

## Route Revenue Reconciliation Engine

Thank you for your interest in joining **Vast**!

This assessment is designed to show us how you **think about systems** — not just how you write code. It has two parts: a short design document and a focused build. Budget **~3 hours total** across both parts. It's fine to leave things unfinished — prioritize depth over breadth.

---

## About Vast

Vast builds **Route Ops** — a platform for operators who manage networks of gaming/vending machines across multiple locations. One critical function is processing daily revenue data from location partners. Files arrive nightly, sometimes get resent, jobs can retry, and **financial accuracy is non-negotiable**.

**Our Core Values:**
1. **Clients First - Always** — We obsess over resolving real problems and driving client success
2. **Progress Over Perfection** — We keep moving, iterate constantly, and improve every step of the way
3. **Fail Fast, Learn Faster** — We take smart risks, learn from missteps, and use failure as fuel for growth
4. **Build What's Next** — We stay curious, embrace innovation and challenge the status quo

**Our Stack:** Laravel 12, MySQL, Vue 3, Livewire, OpenAPI, GitHub, Cloudflare (frontend), Forge/AWS (backend)

---

## Part A: Design Document (~1 hour)

Before writing any code, write a short architecture document (1–3 pages, whatever format you prefer) addressing the following scenario:

### The Scenario

Vast receives nightly revenue files from 200+ location partners. Each file contains daily performance records for every machine at that location. The data must be imported, validated, reconciled against expected totals, and surfaced in a dashboard for operators.

**Current challenges:**
- Files sometimes get resent (duplicate submissions)
- Import jobs can fail mid-way and retry
- Multiple locations may submit simultaneously
- Financial totals must be provably correct — operators make business decisions based on this data
- The system needs to scale: today it's 200 locations, in 12 months it could be 1,000+

### What to cover in your design doc:

1. **Data Model** — How would you structure the tables? What relationships matter? Where do constraints live?
2. **Idempotency Strategy** — How do you ensure the same data imported twice doesn't create duplicates or corrupt totals? Be specific about where enforcement happens (app layer, DB layer, both) and why.
3. **Reconciliation Approach** — How would you compare imported revenue against expected totals and surface discrepancies?
4. **Error Handling & Recovery** — What happens when an import fails halfway? How do you recover without data loss or corruption?
5. **Scaling Considerations** — What changes when you go from 200 to 1,000+ locations? What would you tackle now vs. defer?
6. **Multi-Product Future** — Vast's roadmap includes an AI-powered call center and a retail operating system sharing the same financial core. How would you design this module so it doesn't become a bottleneck or need a full rewrite?

**This is the most important part of the assessment.** We're evaluating your architectural judgment, your ability to think about financial systems with precision, and how you communicate technical decisions.

---

## Part B: Focused Build (~2 hours)

Now build the core of what you designed. **You don't need to build everything from Part A** — focus on the import pipeline and idempotency, which is the hardest and most important piece.

### Tech Stack

- **Backend:** Laravel (PHP), MySQL
- **Frontend:** Vue 3 (Composition API) — *optional, see below*
- **API:** RESTful
- You're welcome to use Livewire if that's your preference

### Setup

Scaffold a standard Laravel app however you prefer. We've included sample data files (`sample_import.json` and `expected_totals.json`) so you don't waste time inventing test data — use them to seed your database and test your endpoints.

### Required: Import Endpoint — `POST /api/revenue/import`

Accept the JSON structure from `sample_import.json`. Your endpoint must:

- Validate all fields with appropriate error messages
- Upsert locations by `location_id`
- **Process idempotently** — importing the same data twice should produce the same result, not duplicates
- Handle partial failure gracefully (if record 15 of 20 fails, what happens to records 1–14?)
- Return a summary: `{ imported: X, updated: Y, skipped: Z, errors: [...] }`

### Required: One meaningful test

Write at least one test that proves your idempotency strategy works — import the same data twice and assert the correct outcome.

### Optional (if time allows, in priority order):

1. **Reconciliation endpoint** — `GET /api/revenue/reconcile` — Compare imported totals against `expected_totals.json`, return discrepancies
2. **Dashboard endpoint** — `GET /api/revenue/dashboard` — Aggregated revenue data with date/location filters
3. **Simple Vue frontend** — A dashboard component showing revenue data and reconciliation status

Don't build optional items at the expense of a solid import pipeline. A well-architected import with clear idempotency and one strong test beats a full-stack feature with shallow foundations.

---

## AI Usage

**We expect you to use AI.** At Vast, AI is a core multiplier — we'd be surprised if you didn't use it. What matters is:

- **Note where you used AI** in your README (e.g., "Used Claude to scaffold the migration, then modified X")
- **Own every decision.** In the 1-hour review, we'll ask you to explain your choices, debug scenarios, and extend the design. The conversation matters as much as the code.
- If you use AI to blast through boilerplate so you can spend more time on architecture and idempotency design — that's exactly the behavior we're looking for.

---

## Submission

- **GitHub repo link** or **zip file** containing:
  - Your design document (Part A)
  - Your code (Part B)
  - A brief `README.md` with:
    - How to run it
    - Key design decisions and trade-offs
    - What you'd improve with more time
    - Where/how you used AI

- **Timeline:** Please submit within **[X days]** of receiving this assessment

---

## What Happens Next

A **1-hour technical review** with Chad (CPTO) and Diego (Senior Engineer). The conversation covers:

- **First 20 min:** Walk through your design document — your thinking, trade-offs, and what you'd do differently with more time
- **Next 25 min:** Walk through your code — architecture, idempotency implementation, testing approach
- **Final 15 min:** Live discussion — we'll throw scenarios at you (e.g., "a partner starts sending files 3x/day instead of 1x, what breaks?") and explore how you think on your feet

---

## Evaluation Criteria

| Area | What We're Looking For |
|------|------------------------|
| **Design Document Quality** | Clear thinking, practical trade-offs, financial precision, scaling awareness |
| **Idempotency & Data Integrity** | Specific strategy, DB + app enforcement, handles edge cases |
| **Architecture & Code Quality** | Clean separation of concerns, readable, pragmatic |
| **Testing** | Meaningful coverage that proves the hard things work |
| **AI Usage & Transparency** | Smart leverage of AI, honest about what was generated vs. hand-written |
| **Communication** | Clear README, good explanations, can articulate "why" not just "what" |
| **Review Conversation** | Can defend decisions, think on feet, collaborative not defensive |

---

**— Vast Engineering**
