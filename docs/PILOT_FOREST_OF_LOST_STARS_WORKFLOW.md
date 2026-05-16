# Forest Of Lost Stars Pilot Workflow

## Scope

This document freezes the validated local admin workflow for `forest-of-lost-stars`.

It does not cover `brief -> Claude` authoring yet.
It does not publish anything to production.

## Inputs

- Brief: `resources/book-briefs/forest-of-lost-stars.yaml`
- Master blueprint: `resources/book-blueprints/forest-of-lost-stars/master.json`
- Runtime output: `resources/book-blueprints/forest-of-lost-stars/generated`
- Cover output: `resources/book-blueprints/forest-of-lost-stars/generated-cover`
- Pages output: `resources/book-blueprints/forest-of-lost-stars/generated-pages`
- Public assets: `public/uploads/books/forest-of-lost-stars`

## Brief To Master

New command:

```bash
php bin/console app:book:generate-master-from-brief \
  --brief=resources/book-briefs/forest-of-lost-stars.yaml \
  --output-dir=resources/book-blueprints/forest-of-lost-stars \
  --dry-run
```

Dry-run outputs:

- `claude-master-prompt.txt`
- `claude-master-payload.json`
- `claude-master-debug.json`

Real generation:

```bash
php bin/console app:book:generate-master-from-brief \
  --brief=resources/book-briefs/forest-of-lost-stars.yaml \
  --output-dir=resources/book-blueprints/forest-of-lost-stars
```

Guard rails:

- dry-run never calls Replicate
- real mode uses `anthropic/claude-3.7-sonnet` only
- generated `master.json` is validated immediately after generation
- no image generation in this command
- no Sylius sync in this command
- QA score fields are prepared in `qa.scorecard`:
  - `editorialScore`
  - `imageabilityScore`
  - `heroConsistencyScore`
  - `localeCompletenessScore`

## Manual Workflow

Run these commands in this exact order.

### 0. Optional: regenerate master from brief

```bash
php bin/console app:book:generate-master-from-brief \
  --brief=resources/book-briefs/forest-of-lost-stars.yaml \
  --output-dir=resources/book-blueprints/forest-of-lost-stars
```

### 1. Validate master

```bash
php bin/console app:book:validate-blueprint \
  --file=resources/book-blueprints/forest-of-lost-stars/master.json
```

### 2. Generate runtimes FR/NL/EN

```bash
php bin/console app:book:generate-blueprint \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated
```

### 3. Validate generated runtimes

```bash
php bin/console app:book:validate-blueprint --runtime \
  --file=resources/book-blueprints/forest-of-lost-stars/generated/runtime.fr.json

php bin/console app:book:validate-blueprint --runtime \
  --file=resources/book-blueprints/forest-of-lost-stars/generated/runtime.en.json

php bin/console app:book:validate-blueprint --runtime \
  --file=resources/book-blueprints/forest-of-lost-stars/generated/runtime.nl.json
```

### 4. Dry-run cover

```bash
php bin/console app:book:generate-cover \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated-cover \
  --dry-run
```

### 5. Generate cover explicitly

```bash
php bin/console app:book:generate-cover \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated-cover \
  --force
```

Expected file:

- `resources/book-blueprints/forest-of-lost-stars/generated-cover/cover-generated.png`

### 6. Generate page_1 and freeze hero reference

```bash
php bin/console app:book:generate-pages \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --cover=resources/book-blueprints/forest-of-lost-stars/generated-cover/cover-generated.png \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated-pages \
  --page=page_1 \
  --write-hero-reference \
  --force
```

Expected files:

- `resources/book-blueprints/forest-of-lost-stars/generated-pages/page_1-generated.png`
- `resources/book-blueprints/forest-of-lost-stars/generated-pages/hero-reference.png`

### 7. Generate page_2 to page_6 with the frozen hero reference

```bash
php bin/console app:book:generate-pages \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --cover=resources/book-blueprints/forest-of-lost-stars/generated-cover/cover-generated.png \
  --hero-reference=resources/book-blueprints/forest-of-lost-stars/generated-pages/hero-reference.png \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated-pages \
  --page=page_2 \
  --force

php bin/console app:book:generate-pages \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --cover=resources/book-blueprints/forest-of-lost-stars/generated-cover/cover-generated.png \
  --hero-reference=resources/book-blueprints/forest-of-lost-stars/generated-pages/hero-reference.png \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated-pages \
  --page=page_3 \
  --force

php bin/console app:book:generate-pages \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --cover=resources/book-blueprints/forest-of-lost-stars/generated-cover/cover-generated.png \
  --hero-reference=resources/book-blueprints/forest-of-lost-stars/generated-pages/hero-reference.png \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated-pages \
  --page=page_4 \
  --force

php bin/console app:book:generate-pages \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --cover=resources/book-blueprints/forest-of-lost-stars/generated-cover/cover-generated.png \
  --hero-reference=resources/book-blueprints/forest-of-lost-stars/generated-pages/hero-reference.png \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated-pages \
  --page=page_5 \
  --force

php bin/console app:book:generate-pages \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --cover=resources/book-blueprints/forest-of-lost-stars/generated-cover/cover-generated.png \
  --hero-reference=resources/book-blueprints/forest-of-lost-stars/generated-pages/hero-reference.png \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated-pages \
  --page=page_6 \
  --force
```

### 8. Sync Sylius local

```bash
php bin/console app:sync-book-blueprints
```

### 9. Verify local API and assets

```bash
curl http://localhost:8001/api/books/forest-of-lost-stars?locale=fr
curl http://localhost:8001/api/books/forest-of-lost-stars?locale=en
curl http://localhost:8001/api/books/forest-of-lost-stars?locale=nl

curl http://localhost:8001/uploads/books/forest-of-lost-stars/cover-generated.png
curl http://localhost:8001/uploads/books/forest-of-lost-stars/page_1-generated.png
curl http://localhost:8001/uploads/books/forest-of-lost-stars/page_2-generated.png
curl http://localhost:8001/uploads/books/forest-of-lost-stars/page_3-generated.png
curl http://localhost:8001/uploads/books/forest-of-lost-stars/page_4-generated.png
curl http://localhost:8001/uploads/books/forest-of-lost-stars/page_5-generated.png
curl http://localhost:8001/uploads/books/forest-of-lost-stars/page_6-generated.png
```

## Orchestrator

New command:

```bash
php bin/console app:book:create-from-blueprint <slug> --base-url=<base-url>
```

Behavior:

1. validate master
2. generate FR/NL/EN runtimes
3. validate runtimes
4. dry-run cover
5. if `--generate-images` is present only:
6. generate cover
7. generate page_1
8. write `hero-reference.png`
9. generate `page_2` to `page_6` with `--hero-reference`
10. sync Sylius
11. verify local API
12. verify physical asset files
13. verify HTTP 200 on each asset
14. fail on any broken image

Guard rails:

- no real Replicate call without `--generate-images`
- stop on invalid master/runtime
- stop on missing PNG after generation
- stop on missing public asset after sync
- stop on non-200 API response
- stop on non-200 asset response
- stop if any `broken images` remain

## Final Pilot Rebuild Command

From the `php` container:

```bash
php bin/console app:book:create-from-blueprint forest-of-lost-stars \
  --generate-images \
  --force \
  --base-url=http://nginx
```

From the host:

```bash
docker compose exec -T php php bin/console app:book:create-from-blueprint forest-of-lost-stars \
  --generate-images \
  --force \
  --base-url=http://nginx
```
