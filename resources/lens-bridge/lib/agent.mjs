/**
 * One headless Claude Code agent: the developer's own `claude` CLI, run in the theme folder over its
 * stream-json protocol (umbrella ADR-0006 § 8). Lines in, lines out; this class only frames them.
 *
 * - Approvals arrive as `can_use_tool` control requests and are answered with `respond()`.
 * - The theme lock is a PreToolUse hook registered at `initialize`: the CLI asks `guard()` before every
 *   tool in GUARDED_TOOLS, including the ones the developer's settings already allow.
 * - `--strict-mcp-config` with no MCP config: the developer's own MCP servers (mail, drive, notes) are never
 *   loaded. `--tools` names every built-in tool the agent gets.
 */
import { spawn } from 'node:child_process';
import { EventEmitter } from 'node:events';
import { GUARDED_TOOLS } from './translate.mjs';

/** The built-in tools a Lens agent gets. No web access, no sub-agents, no interactive questions. */
export const TOOLS = ['Read', 'Glob', 'Grep', 'Edit', 'Write', 'Bash', 'Skill', 'TodoWrite'];

const GUARD_ID = 'lens_theme_lock';

/** The CLI arguments for a Lens agent. */
export function agentArgs({ model = '', resume = '', prompt = '', permissionMode = '' } = {}) {
    const args = [
        '-p',
        '--input-format',
        'stream-json',
        '--output-format',
        'stream-json',
        '--verbose',
        '--include-partial-messages',
        '--permission-prompts',
        'host',
        '--permission-prompt-tool',
        'stdio',
        '--strict-mcp-config',
        '--tools',
        TOOLS.join(','),
    ];
    if (prompt) {
        args.push('--append-system-prompt', prompt);
    }
    if (model) {
        args.push('--model', model);
    }
    // Unset: the developer's own mode, as in their terminal (owner, 2026-11-07).
    if (permissionMode) {
        args.push('--permission-mode', permissionMode);
    }
    if (resume) {
        args.push('--resume', resume);
    }

    return args;
}

/** `claude`, or a script standing in for it (tests): a .mjs/.js path runs with this Node. */
export function command(claude, args) {
    return /\.(mjs|cjs|js)$/.test(claude) ? [process.execPath, [claude, ...args]] : [claude, args];
}

export class Agent extends EventEmitter {
    /**
     * @param {{claude: string, theme: string, model?: string, resume?: string, prompt?: string,
     *   permissionMode?: string, guard: (input: object) => string | null}} options  `guard` returns why a tool is refused, or null.
     */
    constructor(options) {
        super();
        this.options = options;
        this.child = null;
        this.stderr = '';
        this.session = options.resume || null;
        this.requests = 0;
    }

    get running() {
        return this.child !== null;
    }

    start() {
        if (this.child) {
            return;
        }
        const [bin, args] = command(
            this.options.claude,
            agentArgs({
                model: this.options.model,
                resume: this.session ?? '',
                prompt: this.options.prompt,
                permissionMode: this.options.permissionMode,
            }),
        );
        const child = spawn(bin, args, { cwd: this.options.theme, stdio: ['pipe', 'pipe', 'pipe'], env: process.env });
        this.child = child;
        this.stderr = '';

        let buffer = '';
        child.stdout.setEncoding('utf8');
        child.stdout.on('data', (chunk) => {
            // A process left behind by reset() may still be talking: it belongs to another conversation.
            if (this.child !== child) {
                return;
            }
            buffer += chunk;
            let end;
            while ((end = buffer.indexOf('\n')) >= 0) {
                const line = buffer.slice(0, end);
                buffer = buffer.slice(end + 1);
                if (line.trim()) {
                    this.line(line);
                }
            }
        });
        child.stderr.setEncoding('utf8');
        child.stderr.on('data', (chunk) => {
            if (this.child === child) {
                this.stderr = (this.stderr + chunk).slice(-8000);
            }
        });
        child.stdin.on('error', () => {});
        child.on('error', (error) => {
            this.stderr += `\n${error.message}`;
        });
        child.on('close', (code) => {
            if (this.child !== child) {
                return;
            }
            this.child = null;
            this.emit('exit', code, this.stderr);
        });

        this.write({
            type: 'control_request',
            request_id: 'lens-init',
            request: {
                subtype: 'initialize',
                hooks: { PreToolUse: [{ matcher: GUARDED_TOOLS.join('|'), hookCallbackIds: [GUARD_ID] }] },
            },
        });
    }

    /** One line from the CLI. */
    line(line) {
        let ev;
        try {
            ev = JSON.parse(line);
        } catch {
            return;
        }
        if (typeof ev.session_id === 'string') {
            this.session = ev.session_id;
        }
        if (ev.type === 'control_request' && ev.request?.subtype === 'hook_callback') {
            const reason = ev.request.callback_id === GUARD_ID ? this.options.guard(ev.request.input ?? {}) : null;
            this.write({
                type: 'control_response',
                response: {
                    subtype: 'success',
                    request_id: ev.request_id,
                    response: reason
                        ? {
                              hookSpecificOutput: {
                                  hookEventName: 'PreToolUse',
                                  permissionDecision: 'deny',
                                  permissionDecisionReason: reason,
                              },
                          }
                        : {},
                },
            });
            return;
        }
        this.emit('event', ev);
    }

    /** A message from the developer, with the selections already in its text. */
    send(text) {
        this.start();
        this.write({ type: 'user', message: { role: 'user', content: text } });
    }

    /** Answers an approval: the tool runs as Claude asked, or Claude is told the developer said no. */
    respond(requestId, allow, input, message) {
        this.write({
            type: 'control_response',
            response: {
                subtype: 'success',
                request_id: requestId,
                response: allow ? { behavior: 'allow', updatedInput: input } : { behavior: 'deny', message },
            },
        });
    }

    /** Stop: the current turn ends; the session stays. */
    interrupt() {
        this.requests += 1;
        this.write({ type: 'control_request', request_id: `lens-stop-${this.requests}`, request: { subtype: 'interrupt' } });
    }

    /**
     * Another conversation: ends this process and starts one for `session` (resumed), or for a new session (null).
     * The old process gets no say any more: its last lines and its exit are ignored.
     */
    reset(session) {
        const child = this.child;
        this.child = null;
        this.session = session || null;
        if (child) {
            child.stdin.end();
            const timer = setTimeout(() => child.kill('SIGTERM'), 2000);
            timer.unref();
        }
        this.start();
    }

    /** Ends the process (the session is kept on disk, so a later start resumes it). */
    close() {
        const child = this.child;
        if (!child) {
            return;
        }
        child.stdin.end();
        const timer = setTimeout(() => child.kill('SIGTERM'), 2000);
        timer.unref();
    }

    write(message) {
        if (this.child?.stdin.writable) {
            this.child.stdin.write(`${JSON.stringify(message)}\n`);
        }
    }
}
