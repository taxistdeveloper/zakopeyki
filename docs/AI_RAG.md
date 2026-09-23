# AI RAG

Hybrid retrieval:

1. MySQL FULLTEXT по `ai_knowledge_base`
2. Vector search по `ai_knowledge_chunks` + `ai_knowledge_embeddings` (cosine в PHP)
3. Merge + re-rank

Индексация:

```bash
php bin/ai_index_knowledge.php
```

Требует Ollama embedding-модель (`embedding_model` в `config/ai.php`, по умолчанию `nomic-embed-text`).
Без Ollama работает только FULLTEXT.
