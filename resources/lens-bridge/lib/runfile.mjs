/**
 * The bridge's run file: `<theme>/.taw/lens/bridge.json`, where the site's PHP finds the running bridge
 * (TAW\Core\Lens\Bridge) to hand its address and token to the developer's page. It is also the lock that keeps
 * one Lens bridge per theme.
 *
 * The folder ignores itself (a `.gitignore` of `*`), so the token never reaches a commit even in a theme
 * whose own .gitignore lacks `/.taw`. The file is readable by its owner only.
 */
import { chmodSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

export const RUN_DIR = join('.taw', 'lens');
export const RUN_FILE = 'bridge.json';

export const runPath = (theme) => join(theme, RUN_DIR, RUN_FILE);

/** Where the last session's id is kept, for `taw lens --resume` (TAW\Core\Lens\Bridge::lastSession()). */
export const sessionPath = (theme) => join(theme, RUN_DIR, 'last-session');

/** Whether a process is alive (signal 0 only checks). */
export function alive(pid) {
    if (!Number.isInteger(pid) || pid <= 0) {
        return false;
    }
    try {
        process.kill(pid, 0);
        return true;
    } catch (error) {
        return error?.code === 'EPERM';
    }
}

/** The bridge recorded for this theme, if its process is still running. */
export function current(theme) {
    try {
        const info = JSON.parse(readFileSync(runPath(theme), 'utf8'));
        return info && alive(info.pid) ? info : null;
    } catch {
        return null;
    }
}

/** Records this bridge. */
export function write(theme, info) {
    const dir = join(theme, RUN_DIR);
    mkdirSync(dir, { recursive: true, mode: 0o700 });
    writeFileSync(join(dir, '.gitignore'), '# TAW Lens run files: never committed.\n*\n');
    const file = runPath(theme);
    writeFileSync(file, `${JSON.stringify(info, null, 2)}\n`, { mode: 0o600 });
    chmodSync(file, 0o600);
}

/** Removes the run file, only if it is this process's. */
export function remove(theme, pid = process.pid) {
    try {
        const info = JSON.parse(readFileSync(runPath(theme), 'utf8'));
        if (info?.pid !== pid) {
            return;
        }
    } catch {
        return;
    }
    rmSync(runPath(theme), { force: true });
}

/** Keeps the session's id so the next `taw lens --resume` continues it. */
export function keepSession(theme, session) {
    try {
        writeFileSync(sessionPath(theme), `${session}\n`, { mode: 0o600 });
    } catch {
        // The folder is gone: nothing to resume from, which is fine.
    }
}
