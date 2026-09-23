# AI Learning Pipeline

## Поток

```
Feedback (👍/👎)
  → ai_feedback
  → ai_learning_events (pending)
  → LearningPipeline
       ├─ EvaluationService → ai_evaluations (csat, negative_*)
       └─ PromptOptimizer → ai_prompt_versions (status=candidate)
  → Admin approve → active
```

**Промпты никогда не активируются автоматически.**

## CLI

```bash
php bin/ai_learning_worker.php --once --limit=100
php bin/ai_maintenance.php   # включает processPending + retention
```

## Admin

- `/admin/ai` → Process learning queue
- Approve candidate → active
- Rollback → предыдущая version

## Порог кандидата

`PromptOptimizer::maybeProposeCandidate` создаёт candidate, если за 7 дней ≥ 5 `negative_feedback` и ещё нет открытого candidate.
