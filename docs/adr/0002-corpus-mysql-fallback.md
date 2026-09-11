# ADR-0002: MySQL-backed fallback for reference-corpus storage when pdo_sqlite is unavailable

## Status

Accepted.

## Context

ADR-0001 built the Bible Reader Corpus subsystem entirely on `pdo_sqlite`: `ProtectedSqlite`,
`Corpus\Storage`, and `BibleReader` all assumed a working SQLite driver was available on every
host. That assumption broke in production: `bin/taw corpus:install` against the real
~18MB Straubinger corpus file on the fsspx-taw client site (WPMUdev managed hosting) fataled
with `PDOException: could not find driver` — `pdo_sqlite` (and `sqlite3`) are missing from both
PHP-FPM and CLI on that host, confirmed on two PHP versions. WPMUdev support declined to add
the extension: *"As a Managed Hosting service, we are unable to implement custom
extensions... WordPress natively requires MySQL or MariaDB, so the database we offer is MySQL,
not SQLite."*

This isn't a one-off: any TAW client site on similarly locked-down managed hosting hits the
identical wall the moment it uses `ProtectedSqlite`-backed storage. `$wpdb` — and the `mysqli`
extension it's built on, not PDO — is guaranteed on every WordPress host, since WP core itself
can't function without it, while `pdo_sqlite` is not guaranteed anywhere.

Two questions needed an explicit answer:

1. Detect the gap, or keep failing with a bare `PDOException` from deep inside the reader?
2. If a MySQL-backed alternative is built, how does data get from the source `.sqlite` file
   into MySQL when the *installing* environment might also lack `pdo_sqlite`?

Scope was deliberately split in two, prioritized in this order (the second half tracked as
separate, later scope, not solved here):

1. `Corpus\Storage`/`BibleReader` (read-only reference data) — this ADR.
2. `Rag\Storage` (RAG vector chunks, written continuously by `PostIndexer`/`IngestionPipeline`)
   — harder, since it needs writes, not a one-time load; not attempted in this pass.

## Decisions

**1. Explicit capability detection, not just try/catch at the point of failure.**
`ProtectedSqlite::isAvailable()` checks `extension_loaded('pdo_sqlite')` *and* attempts a real
`sqlite::memory:` connection — "loaded" isn't always "functional" on every build. Every caller
that needs to choose a backend (the CLI install command, the REST endpoint's reader resolver)
checks this once, explicitly, rather than discovering the gap via a caught exception deep in a
query.

**2. A genuinely separate, independent `MysqlBibleReader`, not a shared abstract base with
`BibleReader`.** The two backends' query/escaping logic differs enough — SQLite FTS5 vs MySQL
boolean-mode `FULLTEXT`, no `snippet()` equivalent in MySQL (`MysqlBibleReader` builds excerpts
by hand) — that forcing a shared base would mostly move complexity around rather than remove
it. More importantly, this keeps `BibleReader` — already shipped, already tested, working today
on every host with `pdo_sqlite` — completely untouched: its only change is a one-line
`implements BibleReaderInterface`, zero internal logic touched. **Zero behavior change for any
host where `pdo_sqlite` already works** was a hard requirement, and "don't touch the working
code" is the most direct way to guarantee it.

**3. `BibleReaderInterface` extracted now.** ADR-0001 deliberately didn't build an interface —
"one real consumer doesn't justify a formal contract... revisit once a second implementation
actually exists to shape it against." That point has now arrived. The interface declares the
same four methods (`books()`/`chapter()`/`searchVerses()`/`searchNotes()`) both readers already
implemented identically; `BibleEndpoint::reader()`'s `apply_filters('taw_corpus_bible_reader',
...)` resolution now type-checks against the interface instead of the concrete `BibleReader`
class, so a theme-supplied override can be either backend, or a third one, transparently.

**4. Export-then-import via portable JSON, not raw `.sqlite` transfer.** The real design
constraint: if the *target* server lacks `pdo_sqlite`, it can't open the raw `.sqlite` file
itself — parsing the SQLite file format without the extension isn't worth reimplementing.
Instead: `bin/taw corpus:export <sqlite-path> <json-output-path>` runs on a machine that *does*
have `pdo_sqlite` (practically always a developer's local machine), reading the corpus via
plain PDO and writing a portable JSON export — only the columns `BibleReader` actually reads
(`books`/`chapters`/`verses`/`sections`/`notes`, dropping e.g. `notes.anchor_type`/`anchor_id`,
which no reader surfaces). That JSON file is then transferred to the target and imported with
`bin/taw corpus:install <json-path> <filename>` — the same command as the `.sqlite` path,
routed by content-sniffing the source file, not a separate command, so there's one install
entry point regardless of format. No SQLite parsing ever happens on the target server, at
install time or runtime.

**5. MySQL schema mirrors only what `BibleReader` reads, prefixed and namespaced under this
subsystem.** `{$wpdb->prefix}taw_corpus_bible_{books,chapters,verses,sections,notes}`, with
`FULLTEXT` indexes on `verses.text` and `notes.body` replacing SQLite's `verses_fts`/`notes_fts`
virtual tables. `MATCH(col) AGAINST (? IN BOOLEAN MODE)` with `+word1 +word2` syntax maps
directly onto the AND-of-words search semantics `BibleReader::escapeFtsPhrase()` already uses
(see the concurrent search-semantics fix landed just before this ADR) — translated, not
regressed.

**6. Manually-escaped SQL literals for the bulk import, not `$wpdb->prepare()`'s placeholders.**
`$wpdb->prepare()` coerces a PHP `null` passed for a `%s` placeholder into an empty string, not
a real SQL `NULL` — wrong for nullable columns (`division`, `full_name`, `parent_id`, `marker`).
`MysqlBibleInstaller` builds `INSERT` statements with `esc_sql()`-escaped string literals and a
literal `NULL` keyword for PHP `null`, batched (200 rows/statement) inside a transaction,
truncating each table first — safe to re-run, same posture as the `.sqlite` path's file copy.

## Consequences

- A host with a working `pdo_sqlite` sees **zero change** — `BibleReader`'s code, tests, and
  behavior are untouched; the resolver picks it first, unconditionally, whenever it's both
  available and installed.
- A host without `pdo_sqlite` gets a real, working Bible reader for the first time, via
  `corpus:export` (run elsewhere) + `corpus:install <json>` (run there) — no PHP extension
  required on that host beyond what WordPress itself already needs.
- `MysqlBibleReader`'s excerpt highlighting is a hand-built approximation of SQLite FTS5's
  `snippet()`, not identical output — acceptable, since the REST response shape (`excerpt` with
  `<mark>` tags) is what's contractually promised, not byte-identical highlighting logic between
  backends.
- `Rag\Storage`'s vector chunks remain `pdo_sqlite`-only. On a host without it, `PostIndexer`'s
  existing `Logger::warning('rag.storage_unavailable', ...)` catch-and-log behavior means the
  RAG chatbot degrades to "logs a warning and no-ops" rather than fataling the site — not fixed,
  but not actively breaking either. A MySQL-backed vector store (chunks + embeddings as a
  JSON/blob column, cosine similarity computed in PHP at query time) is a plausible follow-up,
  but out of scope here: continuous writes from ongoing post saves don't fit the
  export-once-to-JSON approach this ADR relies on.
