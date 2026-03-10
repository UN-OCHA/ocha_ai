# OCHA AI Module

Adds AI integration to UN OCHA Drupal sites. The module provides various **plugin types** (sources, text extraction, embeddings, vector store, completion, etc.) and configuration so that other modules or custom code can inject the plugin managers and orchestrate flows such as RAG. Configure plugins at **Administration → Configuration → OCHA AI** (`/admin/config/ocha-ai`).

## Plugins

The module defines these [plugin types](src/Attribute), each with its own plugin manager:

- **Completion** — LLM answer generation (e.g. AWS Bedrock, Azure OpenAI)
- **Embedding** — generate embeddings (e.g. AWS Bedrock, Azure OpenAI)
- **Source** — document sources (e.g. ReliefWeb)
- **Text extractor** — extract text from files (e.g. MuPDF for PDFs)
- **Text splitter** — split text into passages (sentence, token, nlp_sentence)
- **Vector store** — store and retrieve embeddings (Elasticsearch, Elasticsearch Flattened, Elasticsearch Job)
- **Answer validator** — validate answers (similarity_embedding, similarity_ranker)
- **Ranker** — rank passages (e.g. ocha_ai_helper_ranker)

Default plugin set: ReliefWeb source, MuPDF extractor, token splitter, AWS Bedrock completion/embedding, Elasticsearch vector store.

## Dependencies

- **MuPDF** (for PDF extraction): `apt install mupdf-tools`

## Configuration

Optional overrides can be set in `settings.php` or via the config UI. Example:

```php
$config['ocha_ai.settings']['plugins']['text_extractor']['mupdf']['mutool'] = '/usr/bin/mutool';

$config['ocha_ai.settings']['plugins']['vector_store']['elasticsearch']['url'] = 'http://elasticsearch:9200';
$config['ocha_ai.settings']['plugins']['vector_store']['elasticsearch']['indexing_batch_size'] = 10;
$config['ocha_ai.settings']['plugins']['vector_store']['elasticsearch']['topk'] = 5;

$config['ocha_ai.settings']['plugins']['completion']['aws_bedrock']['region'] = '';
$config['ocha_ai.settings']['plugins']['completion']['aws_bedrock']['api_key'] = '';
$config['ocha_ai.settings']['plugins']['completion']['aws_bedrock']['api_secret'] = '';
$config['ocha_ai.settings']['plugins']['completion']['aws_bedrock']['model'] = 'amazon.titan-text-express-v1';
$config['ocha_ai.settings']['plugins']['completion']['aws_bedrock']['version'] = '';
$config['ocha_ai.settings']['plugins']['completion']['aws_bedrock']['max_tokens'] = 512;

$config['ocha_ai.settings']['plugins']['embedding']['aws_bedrock']['region'] = '';
$config['ocha_ai.settings']['plugins']['embedding']['aws_bedrock']['api_key'] = '';
$config['ocha_ai.settings']['plugins']['embedding']['aws_bedrock']['api_secret'] = '';
$config['ocha_ai.settings']['plugins']['embedding']['aws_bedrock']['model'] = 'amazon.titan-embed-text-v1';
$config['ocha_ai.settings']['plugins']['embedding']['aws_bedrock']['version'] = '';
$config['ocha_ai.settings']['plugins']['embedding']['aws_bedrock']['batch_size'] = 1;
$config['ocha_ai.settings']['plugins']['embedding']['aws_bedrock']['dimensions'] = 1536;
$config['ocha_ai.settings']['plugins']['embedding']['aws_bedrock']['max_tokens'] = 8192;
```
