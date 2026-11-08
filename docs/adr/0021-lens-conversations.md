# ADR-0021: TAW Lens keeps its own transcript of each conversation

## Status

Accepted (2026-11-08, the owner's pick of the recommendation). Under umbrella ADR-0006 (TAW Lens) and
ADR-0020 (its page script). Issue #223.

## Context

Until v1.101.0 the Lens bridge kept a conversation only in memory: a page that reloaded got it back in the
bridge's `hello`, but `taw lens --resume` opened an empty panel (Claude remembered; the panel didn't), and the
only way to start a new conversation was to restart `taw lens`.

The panel now has a conversation menu: the header names the conversation, and its menu starts a new one or
reopens one of the recent ones for this theme. Reopening one, or `taw lens --resume`, replays it in the panel.
That needs each conversation's history somewhere the bridge can read it back. Two sources were possible:

- **Claude Code's own session files** (`~/.claude/projects/<folder>/<session>.jsonl`). They exist for every
  session, including ones started in a terminal. But their format is the CLI's, undocumented and free to change
  with any update; they hold each message's full selection payload (`<lens-selections>`); they don't record
  whether the developer applied or skipped an approval; and field saves made from the page never reach Claude,
  so they aren't there at all.
- **A transcript Lens writes itself**: the page events the bridge already emits, one JSON line each.

## Decision

1. **The bridge writes a transcript per conversation**, `<theme>/.taw/lens/conversations/<id>.jsonl`
   (`resources/lens-bridge/lib/transcript.mjs`): the page events it emits, except the theme lock's `busy`
   (which describes the bridge now, not the conversation). Streamed text is joined into one line per run of
   text. The folder sits inside `.taw/lens/`, which ignores itself, so transcripts never reach a commit; files
   are `0600`, the folder `0700`. Only the newest 50 are kept.
2. **A conversation is named by the first session id Claude reports.** Events wait in memory until then. If a
   resumed session ever comes back under another id, a `meta` line records it and resuming uses the latest.
   Ids are checked against `^[A-Za-z0-9][A-Za-z0-9_-]{3,63}$` before they become a file name.
3. **Field saves made from the page go in the conversation.** The page reports a save (and an undo) with
   `POST /note {id, name, undone?}`; the bridge records it as a `saved` event, so a replay shows it (without
   Undo) and it counts as a change. A save made while `taw lens` isn't running isn't recorded.
4. **Page protocol 2** (`PROTOCOL` in `lib/bridge.mjs`, `Bridge::PROTOCOL`, `bridge.ts`):
   - `hello` carries `conversations`: the current one (once something was said) and the newest others, up to
     8, each `{id, title, at, changes, current}`. Title = the first message's first line; `at` = last activity;
     `changes` = files Claude changed plus fields saved, each counted once. A `conversations` event updates it
     after every turn, end of turn and save.
   - `POST /conversation {session}`: a transcript's id reopens it, `null` starts a new one. Refused with 409
     while a turn runs or an approval waits, 422 for a malformed id, 404 for an unknown one. The bridge ends
     the old `claude` process (whose late output it then ignores), starts one with `--resume <latest id>` or
     none, and sends every page a new `hello`.
   - A reopened transcript replays with its open turns closed as stopped, followed by a `resumed` marker; turn
     numbers go on from the last one.
   - `turn` events carry `at` and the page's path (`page`), `done` and `saved` carry `at`.
5. **`taw lens --resume`** finds the transcript for the session (by its id, or a `meta` line naming it) and
   replays it the same way.

## Trade-offs

- Conversations from before v1.102.0, and sessions started in a terminal, have no transcript: reopening one
  with `--resume` continues Claude's session but the panel shows nothing before it, as before.
- Two copies of each conversation now exist (Claude Code's and Lens's), both on the developer's machine. Lens's
  holds what the panel showed: the developer's messages and Claude's replies, tool targets, approval diffs
  (inputs are already capped at 100 KB), never the selection payloads.
- A protocol bump: a page and a `taw lens` from different taw/core versions show "Restart needed" until
  `taw lens` is restarted.

## Consequences

- `translate.mjs` stays the only file that knows the CLI's stream-json; nothing reads Claude Code's files.
- Replays are exact: what a page that watched it live showed, including approval outcomes and field saves.
- The page's own Undo stays on the page's own notes; recorded saves arrive with the page's id, so the page that
  made a save doesn't show it twice, and other pages (or a reload) show it without Undo.
