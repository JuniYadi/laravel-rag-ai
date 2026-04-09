# Laravel RAG AI

[![codecov](https://codecov.io/gh/JuniYadi/laravel-rag-ai/graph/badge.svg?token=IB1vVqdaPc)](https://codecov.io/gh/JuniYadi/laravel-rag-ai)
[![GitHub release](https://img.shields.io/github/v/release/JuniYadi/laravel-rag-ai?include_prereleases)](https://github.com/JuniYadi/laravel-rag-ai/releases)
[![Docker](https://img.shields.io/badge/ghcr.io-juniyadi%2Flaravel--rag--ai-blue)](https://github.com/JuniYadi/laravel-rag-ai/pkgs/container/laravel-rag-ai)
[![License](https://img.shields.io/github/license/JuniYadi/laravel-rag-ai)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.5-8892BF)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20)](https://laravel.com)


A Laravel application combining Retrieval-Augmented Generation (RAG) with AI-powered chat. Upload documents, index them using vector embeddings, and ask questions that get answered using your own data.

## Requirements

- PHP 8.5+
- Composer
- Node.js & NPM
- OpenAI API key (or compatible LLM provider)
- Database mode (choose one):
  - Quick local dev: SQLite (no pgvector)
  - Recommended / production RAG: PostgreSQL + pgvector extension

## Installation

```bash
git clone <repository-url>
cd laravel-rag-ai

cp .env.example .env
composer install
npm install
npm run build

php artisan key:generate
```

## Configuration

### Choose your database mode

#### Mode A — Quick local dev (SQLite fallback)

Use this for fast app setup and UI/API development. Retrieval quality/features may be limited versus pgvector.

```env
DB_CONNECTION=sqlite
```

Then run:

```bash
touch database/database.sqlite
php artisan migrate
```

#### Mode B — Recommended / production (PostgreSQL + pgvector)

Use this for proper vector search behavior.

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=laravel_rag
DB_USERNAME=postgres
DB_PASSWORD=your-password
```

Before migrating, ensure extension is enabled:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

Then run:

```bash
php artisan migrate
```

### Environment variables

Copy `.env.example` to `.env` and configure at minimum:

| Variable | Description | Default |
|----------|-------------|---------|
| `DB_CONNECTION` | Database driver | `sqlite` |
| `LLM_PROVIDER` | LLM provider | `openai` |
| `LLM_MODEL` | Model name | `gpt-4o-mini` |
| `LLM_BASE_URL` | LLM API base URL | `https://api.openai.com/v1` |
| `LLM_API_KEY` | API key for selected LLM provider | _(required)_ |
| `DOCUMENT_DISK` | Storage disk for documents | `local` |
| `DOCUMENT_STORAGE_PATH` | Path within disk | `documents` |
| `DOCUMENT_CHUNK_SIZE` | Target chars per chunk during ingestion | `1200` |
| `DOCUMENT_CHUNK_OVERLAP` | Overlap chars between neighboring chunks | `200` |

If using PostgreSQL mode, also set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. If using SQLite mode, create `database/database.sqlite`. 

### Troubleshooting

- **`could not find driver (pgsql)`**
  - Install/enable PHP PostgreSQL extension (`pdo_pgsql`).
- **`type "vector" does not exist` or migration fails on vector columns**
  - Run `CREATE EXTENSION IF NOT EXISTS vector;` in your target PostgreSQL database.
- **Embedding/LLM calls fail (`401`, `invalid_api_key`, timeout)**
  - Verify `LLM_API_KEY`, `LLM_BASE_URL`, and outbound network access.
- **SQLite file errors (`unable to open database file`)**
  - Ensure `database/database.sqlite` exists and is writable by the app process.

### Google OAuth (Optional)

To enable Google login, you need a Google Cloud Console project with OAuth 2.0 credentials:

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a project (or select existing)
3. Enable the **Google Identity** API
4. Go to **APIs & Services > Credentials**
5. Create an **OAuth 2.0 Client ID** (Web application)
6. Add your redirect URL to **Authorized redirect URIs**: `https://your-app-url/auth/google/callback`
7. Copy the **Client ID** and **Client Secret** to your `.env`:

```env
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URL=https://your-app-url/auth/google/callback
```

## Features

- **Document Upload** — Upload PDF, TXT, and other document formats for indexing
- **RAG Chat** — Ask questions and get answers sourced from your uploaded documents
- **Google Login** — Authenticate with your Google account (optional)
- **2FA Support** — Two-factor authentication via Laravel Fortify
- **Responsive UI** — Built with Livewire, Flux UI, and Tailwind CSS

## Tech Stack

- **Backend:** Laravel 13, PHP 8.5
- **Frontend:** Livewire 4, Flux UI, Tailwind CSS 4
- **Auth:** Laravel Fortify + Socialite (Google OAuth)
- **Database:** PostgreSQL with pgvector
- **AI:** OpenAI API (compatible providers supported)
- **Testing:** Pest 4

## Development

```bash
# Run tests
php artisan test --compact

# Code formatting
vendor/bin/pint --format agent

# Run queue worker (default queue)
php artisan queue:work

# Run queue worker for document ingestion only
php artisan queue:work --queue=documents

# Start development server
composer run dev
```

## License

Proprietary. All rights reserved.
