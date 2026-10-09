# AI Sales Agent Platform

A multi-tenant AI sales assistant for B2B exhibitions. A single script tag on
an exhibition website gives visitors a chat assistant that answers questions,
qualifies potential exhibitors, shows approved pricing and captures leads.

Built for cPanel shared hosting: PHP 8 and MySQL, no framework, no Composer,
no background services, no root access required.

## Start here

- **Installing it on a new server:** `docs/deployment-guide.md`
- **User manual for beginners (open in a browser):** `docs/user-manual.html`
- **Upgrading an existing installation, and switching on WhatsApp, research and prospecting:** `docs/upgrade-2026-09-whatsapp.md`
- **Using it:** `docs/operating-manual.md`
- **Rules every future change must follow:** `docs/universal-rules.md`
- **Build Asia content decisions:** `docs/content-decisions.md`

## The core idea

The AI handles conversation. The software handles everything commercially
consequential — country classification, pricing eligibility, the figures
themselves, tax calculation, approval state, lead storage, permissions.

Every price shown to a visitor is read from an approved database record and
rendered by PHP. The AI is given finished figures as facts and is told never
to calculate. Before any reply is displayed it is scanned for monetary
amounts, and a reply containing a figure that is not on the approved list is
discarded entirely.

## Folder layout

```
app/          application code and the database schema, not reachable over the web
  lib/        database, security, validation, mail, rate limiting
  domain/     pricing, geography, conversation, leads, review workflow
    import/   reading documents, comparing them, clarifying, publishing drafts
config/       configuration, not reachable over the web
cron/         tasks.php hourly housekeeping; whatsapp.php every five minutes
docs/         deployment guide and operating manual
index.php     guard shown if the document root is pointed here by mistake
public_html/  the only web-reachable folder
  index.php   front door: sends visitors to the installer or the admin panel
  install/    web installer — delete this folder once setup is finished
  admin/      administration panel
  api/        the endpoints the widget calls, and whatsapp.php for incoming WhatsApp
  review/     token-based approval page, no login
  widget/     the embeddable chat widget
storage/      logs and uploads, not reachable over the web
tools/        command-line content loader, not reachable over the web
tests/        acceptance tests (full package only)
```

## Filling the knowledge base from documents

The admin panel's *Conversation with AI* page reads uploaded brochures, price
lists and reports, then builds the knowledge base from them.

The division of labour is the same as everywhere else in the platform. The
model reads and reports, quoting the wording behind every claim, and is
instructed never to calculate. PHP then compares the claims across documents
by exact normalised value, and where they disagree it asks the operator rather
than choosing — because picking between two prices is a judgement, and a
confident wrong answer is worse than a question.

Answers are stored permanently as the reason the knowledge base holds what it
holds, and everything produced arrives as a draft for the normal review
workflow.

## Tests

```
php tests/acceptance.php
```

1,179 checks covering pricing rules, country classification, agent
isolation, prompt-injection resistance, output sanitising, idempotency, rate
limiting, the review workflow, circuit breaking, lead handling, WhatsApp,
follow-ups, company research, prospecting, email sending and analytics.

The suite truncates the database it runs against. Never point it at a live
installation. For that reason the fresh-installation package leaves the
`tests` folder out.

## AI providers

Any of Claude, ChatGPT, Gemini, Grok, DeepSeek, Mistral, OpenRouter, a
self-hosted model via Ollama, or any other OpenAI-compatible endpoint. Chosen
per agent, with an optional backup provider tried once before the assistant
falls back to its approved offline message.

Providers are described as data in `LlmCatalogue`, so adding one is usually a
matter of describing it: only genuinely different request shapes — Anthropic's
and Google's — have code of their own. Each provider has a test button in the
admin panel that sends one real request and reports what came back.

Plain HTTP is permitted only for addresses on the local machine or a private
network, which is where a self-hosted model lives; an `http://` address on the
public internet is refused before anything is sent.

## Requirements

PHP 8.0 or newer with `mysqli`, `curl`, `mbstring`, `openssl` and `json`.
MySQL 5.7 / MariaDB 10.3 or newer. An API key for at least one of the providers
above, or a self-hosted model the server can reach.
