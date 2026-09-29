<?php
/**
 * AI assistant (natural language → SQL → answer).
 * Works with any OpenAI-compatible chat API: Open WebUI, Ollama, LM Studio, vLLM, llama.cpp...
 *   Open WebUI: AI_BASE_URL=http://localhost:3000/api   (API key: Settings → Account → API Keys)
 *   Ollama:     AI_BASE_URL=http://localhost:11434/v1
 * The client calls {AI_BASE_URL}/chat/completions and {AI_BASE_URL}/models.
 */
return [
    'enabled'      => (bool) env('AI_ENABLED', false),
    'base_url'     => rtrim((string) env('AI_BASE_URL', 'http://localhost:3000/api'), '/'),
    'api_key'      => (string) env('AI_API_KEY', ''),
    'model'        => (string) env('AI_MODEL', ''),
    'timeout'      => (int) env('AI_TIMEOUT_SECONDS', 120),
    'temperature'  => (float) env('AI_TEMPERATURE', 0.1),
    'max_attempts' => (int) env('AI_MAX_ATTEMPTS', 3),          // 1 + automatic corrections after SQL errors
    'send_results' => (bool) env('AI_SEND_RESULTS', true),      // send result rows to the model to write the answer
    'result_rows'  => (int) env('AI_RESULT_ROWS', 50),          // max rows sent to the model
    'max_rows'     => (int) env('AI_QUERY_MAX_ROWS', 1000),     // max rows fetched by AI-generated queries
    'schema_tables'=> (int) env('AI_SCHEMA_MAX_TABLES', 150),   // max tables described in the prompt
    'verify_tls'   => (bool) env('AI_VERIFY_TLS', true),
];
