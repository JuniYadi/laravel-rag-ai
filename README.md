# Laravel RAG AI

A Laravel application combining Retrieval-Augmented Generation (RAG) with AI-powered chat. Upload documents, index them using vector embeddings, and ask questions that get answered using your own data.

## Requirements

- PHP 8.5+
- PostgreSQL with pgvector extension
- Composer
- Node.js & NPM
- OpenAI API key (or compatible LLM provider)

## Installation

```bash
git clone <repository-url>
cd laravel-rag-ai

cp .env.example .env
composer install
npm install
npm run build

php artisan key:generate
php artisan migrate
```

## Configuration

### Environment Variables

Copy `.env.example` to `.env` and configure:

| Variable | Description | Default |
|----------|-------------|---------|
| `DB_CONNECTION` | Database driver | `pgsql` |
| `DB_DATABASE` | Database name | `laravel_rag` |
| `LLM_PROVIDER` | LLM provider | `openai` |
| `LLM_MODEL` | Model name | `gpt-4o-mini` |
| `LLM_BASE_URL` | LLM API base URL | `https://api.openai.com/v1` |
| `DOCUMENT_DISK` | Storage disk for documents | `local` |
| `DOCUMENT_STORAGE_PATH` | Path within disk | `documents` |

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

# Run queue worker
php artisan queue:listen

# Start development server
composer run dev
```

## License

Proprietary. All rights reserved.
