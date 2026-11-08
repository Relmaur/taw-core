#!/usr/bin/env node
/**
 * TAW Lens bridge (umbrella ADR-0006 § 8). Started by `taw lens` in the theme folder; zero dependencies.
 *
 *   node bridge.mjs --theme <dir> --origin <https://site.local> [--origin …] [--port 47615]
 *                   [--model <model>] [--resume <session>] [--permission-mode <mode>] [--claude <path>]
 *                   [--prompt-file <file>]
 *
 * Runs in the foreground until Ctrl-C. Everything it needs to say goes to the terminal; the page talks to it
 * over 127.0.0.1 only.
 */
import { randomBytes } from 'node:crypto';
import { readFileSync, realpathSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Bridge, PROTOCOL, serve } from './lib/bridge.mjs';
import * as runfile from './lib/runfile.mjs';

export const DEFAULT_PORT = 47615;

/** Reads `--name value` pairs; repeated names collect into a list. */
export function parseArgs(argv) {
    const out = { origin: [] };
    for (let i = 0; i < argv.length; i++) {
        const arg = argv[i];
        if (!arg.startsWith('--')) {
            continue;
        }
        const name = arg.slice(2);
        const value = argv[i + 1] !== undefined && !argv[i + 1].startsWith('--') ? argv[++i] : 'true';
        if (name === 'origin') {
            out.origin.push(value);
        } else {
            out[name] = value;
        }
    }

    return out;
}

const here = dirname(fileURLToPath(import.meta.url));

async function main() {
    const args = parseArgs(process.argv.slice(2));
    const say = (line) => process.stdout.write(`${line}\n`);
    if (!args.theme || args.origin.length === 0) {
        say('Usage: node bridge.mjs --theme <dir> --origin <site origin> [--port n] [--model m] [--resume id]');
        process.exit(2);
    }
    const theme = realpathSync(args.theme);
    const running = runfile.current(theme);
    if (running && running.pid !== process.pid) {
        say(`TAW Lens is already running for this theme (process ${running.pid}, port ${running.port}).`);
        say('Use that one, or stop it (Ctrl-C in its terminal) and run `taw lens` again.');
        process.exit(1);
    }

    const prompt = readFileSync(args['prompt-file'] ?? join(here, 'agent-prompt.md'), 'utf8');
    const token = randomBytes(24).toString('hex');
    const bridge = new Bridge({
        theme,
        claude: args.claude ?? process.env.TAW_LENS_CLAUDE ?? 'claude',
        model: args.model === 'true' ? '' : (args.model ?? ''),
        resume: args.resume === 'true' ? '' : (args.resume ?? ''),
        prompt,
        permissionMode: args['permission-mode'] === 'true' ? '' : (args['permission-mode'] ?? ''),
        log: (line) => say(`  ${line}`),
    });
    const { server, port } = await serve(bridge, {
        port: Number(args.port ?? DEFAULT_PORT),
        token,
        origins: args.origin,
    });
    runfile.write(theme, {
        pid: process.pid,
        port,
        token,
        origins: args.origin,
        protocol: PROTOCOL,
        started: new Date().toISOString(),
    });
    bridge.start();

    say(`TAW Lens is running for ${theme}`);
    say(`  Open ${args.origin[0]} in your browser (logged in, Vite dev server on, "Enable Lens" checked).`);
    say(`  Listening on 127.0.0.1:${port}. Ctrl-C stops it.`);

    let closing = false;
    const shutdown = () => {
        if (closing) {
            return;
        }
        closing = true;
        runfile.remove(theme);
        bridge.close();
        server.close();
        const session = bridge.session;
        if (session) {
            runfile.keepSession(theme, session);
        }
        say(session ? `\nTAW Lens stopped. Continue this conversation later with: taw lens --resume ${session}` : '\nTAW Lens stopped.');
        setTimeout(() => process.exit(0), 300).unref();
    };
    process.on('SIGINT', shutdown);
    process.on('SIGTERM', shutdown);
    process.on('SIGHUP', shutdown);
    process.on('exit', () => runfile.remove(theme));
}

if (process.argv[1] && realpathSync(process.argv[1]) === fileURLToPath(import.meta.url)) {
    main().catch((error) => {
        process.stderr.write(`TAW Lens could not start: ${error?.message ?? error}\n`);
        process.exit(1);
    });
}
