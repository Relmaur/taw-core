/**
 * The theme lock (umbrella ADR-0006 § 8): is another Claude session working in this theme?
 *
 * Two checks: the run file (one Lens bridge per theme, runfile.mjs), and any running Claude Code process whose
 * working folder is the theme or inside it, which also catches a terminal session. macOS and Linux (`ps`,
 * `lsof`); elsewhere it finds nothing and Lens never reports the theme busy.
 *
 * Claude Code is found by its command line, not its process name: the native install runs a binary named after
 * its version (`~/.local/share/claude/versions/2.1.319`, reached through `~/.local/bin/claude`), so `lsof -c
 * claude` misses it.
 */
import { execFile } from 'node:child_process';
import { realpathSync } from 'node:fs';

const run = (bin, args) =>
    new Promise((resolve) => {
        execFile(bin, args, { encoding: 'utf8', timeout: 4000 }, (error, stdout) => resolve(error && !stdout ? '' : stdout));
    });

const real = (path) => {
    try {
        return realpathSync(path);
    } catch {
        return path;
    }
};

/** Claude Code processes in `ps -axo pid=,args=` output: started as `claude`, from a versions folder, or the npm package. */
export function claudePids(ps) {
    const pids = [];
    for (const line of ps.split('\n')) {
        const match = /^\s*(\d+)\s+(.+)$/.exec(line);
        if (!match) {
            continue;
        }
        const args = match[2];
        if (
            /^(\S*\/)?claude(\s|$)/.test(args) ||
            /^\S*\/claude\/versions\/[^/\s]+(\s|$)/.test(args) ||
            /^\S*node\S*\s+\S*@anthropic-ai\/claude-code\//.test(args)
        ) {
            pids.push(Number(match[1]));
        }
    }

    return pids;
}

/** Parses `lsof -Fpn` output into the pids whose folder is `theme` or inside it. */
export function pidsIn(lsof, theme, resolve = real) {
    const pids = [];
    let pid = null;
    for (const line of lsof.split('\n')) {
        if (line.startsWith('p')) {
            pid = Number(line.slice(1));
        } else if (line.startsWith('n') && pid !== null) {
            const dir = resolve(line.slice(1));
            if ((dir === theme || dir.startsWith(`${theme}/`)) && !pids.includes(pid)) {
                pids.push(pid);
            }
        }
    }

    return pids;
}

/**
 * The other Claude sessions in this theme: their pids and when the oldest one started.
 * @param {string} theme  The theme folder's real path.
 * @param {number[]} own  This bridge's own agent (never counts).
 * @returns {Promise<{pids: number[], since: string|null}|null>}
 */
export async function otherSessions(theme, own = []) {
    if (process.platform === 'win32') {
        return null;
    }
    const candidates = claudePids(await run('ps', ['-axo', 'pid=,args='])).filter((pid) => !own.includes(pid) && pid !== process.pid);
    if (candidates.length === 0) {
        return null;
    }
    const pids = pidsIn(await run('lsof', ['-a', '-p', candidates.join(','), '-d', 'cwd', '-Fpn']), theme);
    if (pids.length === 0) {
        return null;
    }
    const starts = [];
    for (const pid of pids) {
        const started = Date.parse((await run('ps', ['-o', 'lstart=', '-p', String(pid)])).trim());
        if (!Number.isNaN(started)) {
            starts.push(started);
        }
    }

    return { pids, since: starts.length ? new Date(Math.min(...starts)).toISOString() : null };
}
