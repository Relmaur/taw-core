# ADR-0001: Reference-corpus storage — extracted, not duplicated; separate from RAG; CLI-installed

## Status

Accepted.

## Context

The Bible-reader feature (fsspx-taw client site) needs a framework-level home for a large
(~18MB), read-only, curated reference dataset — a Straubinger-translation Spanish Catholic
Bible shipped as a SQLite file (`books`/`chapters`/`verses`/`sections`/`notes`/
`scripture_references` + `verses_fts`/`notes_fts`). The source file's own `meta` table
identifies itself with a `generator: corpus:export` / `schema_version` / `surface: bible`
convention, implying other corpora (other translations, other reference works) may follow the
same shape later.

`TAW\Core\Rag\Storage` already solved an adjacent problem — a webserver-inaccessible uploads
subdirectory holding SQLite files, opened via a consistent exception-mode PDO connection — for
the RAG chatbot's knowledge bases. Three questions needed an explicit answer rather than
default behavior:

1. Does the ~15-line "protected dir + PDO open" pattern get extracted into shared
   infrastructure, or does a new corpus subsystem duplicate it?
2. Does the corpus file live inside `taw-private/rag/` (reusing the RAG directory) or in its
   own directory?
3. Does the corpus file arrive via the existing `KnowledgeBaseAdminScreen` wp-admin upload flow
   (which already does admin-gated `.sqlite` upload + magic-byte validation + protected-dir
   storage) or through a separate mechanism?
4. Is the reader itself a fixed, `final` implementation, or does a theme need a way to
   substitute its own — a different installed filename, or genuinely different read behavior —
   without forking taw-core?

## Decisions

**1. Extract, don't duplicate.** `TAW\Core\Storage\ProtectedSqlite` now owns
`ensureProtectedDir()`, `open()`/`openReadOnly()`, and `looksLikeSqliteFile()`. Both
`TAW\Core\Rag\Storage` and the new `TAW\Core\Corpus\Storage` are thin wrappers around it,
owning only what's actually subsystem-specific: which directory, and whether writes ever
happen. The reasoning: the protection guarantee (never directly HTTP-reachable) and the
magic-byte validation are security-relevant mechanics, not incidental style — a copy-pasted
drift between two implementations of "is this really a SQLite file" or "is this directory
really locked down" is a real vulnerability class, not just untidy code. `KnowledgeBaseAdminScreen`
now delegates its own `looksLikeSqlite()` to the same shared check instead of keeping a second
copy of the magic-byte constant and read loop.

**2. Separate directory, not a subdirectory of RAG.** `TAW\Core\Corpus\Storage::dir()` resolves
to `uploads/taw-private/corpus/`, a sibling of `taw-private/rag/`, not nested inside it. A
reference corpus meant for direct structured reading (books/chapters/verses/notes) and a RAG
knowledge base meant for chatbot semantic search are different concerns that happen to share
storage mechanics. Concretely: `KnowledgeBase\KnowledgeBaseIngestionPipeline` writes a
`taw_rag_chunks` table directly into whatever `.sqlite` file it ingests. If the Bible file sat
inside `taw-private/rag/`, it would be indistinguishable from an admin-uploaded knowledge base
and could eventually get ingested/mutated by that pipeline by mistake. A separate directory
makes that impossible structurally, not just by convention.

**3. CLI install, not a wp-admin upload screen.** `php bin/taw corpus:install <path> <filename>`
(`TAW\CLI\CorpusInstallCommand`) copies a developer-supplied `.sqlite` file into
`Corpus\Storage`'s protected directory under a fixed filename, after the same magic-byte check
`KnowledgeBaseAdminScreen` uses. No registry, no metadata option, no admin UI. This is a
curated dataset a developer places once per environment — closer to a database migration than
to user-generated content — not something a parish-office admin is expected to swap through a
web form the way a RAG knowledge base upload is. A wp-admin upload screen can be added later if
that assumption turns out wrong; it isn't built speculatively now.

The installed filename is fixed and versionless (e.g. `bible-straubinger.sqlite`, no `-beta` or
version suffix) — `release_channel`/`generated_at`/`source_revision` already live inside the
file's own `meta` table, so reader code never has to know which build is currently installed
just from the filename.

**4. A theme-facing filter, and `BibleReader` stays extendable rather than `final`.**
`TAW\Core\Rest\BibleEndpoint` resolves the reader it queries through
`apply_filters('taw_corpus_bible_reader', new BibleReader())`, matching the existing
`taw_security_hide_users_endpoint`/`taw_register_meta_in_rest` filter pattern rather than
inventing a new extensibility mechanism. For that filter to be more than decorative,
`BibleReader` had to actually be subclassable: it is `class`, not `final class`, `FILENAME` is
overridable, `pdo()` is `protected`, and every internal `fetch*`/`normalizeBookRow` helper is
`protected` rather than `private` — a child class can override just the filename, or just one
fetch method, and reuse the rest. No `BibleReaderInterface` was extracted for this — one real
consumer (fsspx-taw) doesn't justify a formal contract yet; `BibleEndpoint::reader()` narrows
the filter's `mixed` return via `instanceof BibleReader` (falling back to the default on a
misbehaving filter callback) rather than an interface type-check. Revisit once a second
implementation actually exists to shape the interface against.

## Consequences

- Any future reference corpus (another translation, another reference work) reuses
  `Corpus\Storage` and `corpus:install` as-is — just a different destination filename — without
  touching `Rag\Storage` or the RAG ingestion pipeline at all.
- `ProtectedSqlite` is now the one place that defines "what makes a directory safe" and "what
  makes a file a real SQLite database" for this framework; any future subsystem needing the
  same guarantees extends it rather than re-implementing it a third time.
- The corpus reader itself (`TAW\Core\Corpus\Bible\BibleReader`) always opens via
  `Corpus\Storage::openReadOnly()`, which additionally sets `PRAGMA query_only = 1` — a
  reference corpus is never written to at runtime, enforced at the SQLite level, not just by
  the reader's own code never issuing a write.
- No admin-facing "install a corpus" UI exists yet. If a future site needs non-developer corpus
  swapping, that's new scope, not a gap in this design — `KnowledgeBaseAdminScreen` is a
  reasonable model to follow when that need actually arrives.
- A theme can point `BibleEndpoint` at a different installed file or different read behavior
  today via `add_filter('taw_corpus_bible_reader', ...)` — no taw-core release needed for a
  per-site variation of an already-supported shape.
