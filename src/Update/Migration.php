<?php

declare(strict_types=1);

namespace TAW\Update;

/**
 * One change a taw/core release makes to a theme, done by code instead of a
 * "Check" in UPGRADING.md for a person to carry out (umbrella plan
 * docs/plans/taw-platform.md § 5.0: updates as `composer update`).
 *
 * A migration finds its own work (pending()), so running it again does
 * nothing: no record of what ran is kept, the theme itself is the record.
 * When it meets something it shouldn't decide (a file the site edited), it
 * leaves it and returns the steps for a person, never a guess.
 *
 * explain() is the human path (umbrella ADR-0004): what it changes, why, how
 * to do it by hand, how to undo it. `bin/taw upgrade --explain <id>` prints it.
 */
interface Migration
{
    /** Stable id: "<the taw/core version that introduced it>/<slug>", e.g. "1.91.0/agent-docs". */
    public function id(): string;

    /** One line: what it does. */
    public function title(): string;

    /** "classic", "block" or "any". */
    public function themeKind(): string;

    /** What, why, by hand, undo — in plain words, for a person picking it up without an agent. */
    public function explain(): string;

    /** Whether this theme still needs it. Read-only. */
    public function pending(string $themeDir): bool;

    /** Does it. Must leave pending() false, or report why not in the result's manual steps. */
    public function run(string $themeDir): MigrationResult;
}
