/**
 * The activity line (component 01): Claude's tool work, summarised into a few quiet lines ("Read 3 files in
 * `Blocks/Hero/`", "Editing `Blocks/Hero/style.css`"), with every step one click away under Details.
 */
import type { Step, StepKind } from './chatModel';
import { __ } from './i18n';

export interface ActivityLine {
    /** The words, with `%s` where the code part goes (at most once). */
    text: string;
    /** A file, folder, pattern or command, shown in mono. */
    code: string;
    state: Step['state'];
}

/** The folder every path shares, with its trailing slash ("Blocks/Hero/"), or "" when they share none. */
export function commonFolder(paths: string[]): string {
    if (paths.length === 0) {
        return '';
    }
    const split = paths.map((p) => p.split('/').slice(0, -1));
    const first = split[0];
    let depth = 0;
    while (depth < first.length && split.every((parts) => parts[depth] === first[depth])) {
        depth++;
    }

    return depth > 0 ? `${first.slice(0, depth).join('/')}/` : '';
}

const stateOf = (steps: Step[]): Step['state'] =>
    steps.some((s) => s.state === 'running') ? 'running' : steps.some((s) => s.state === 'failed') ? 'failed' : 'done';

/** Steps of one kind in a row go on one line; edits, writes and commands get a line each. */
const groupable = (kind: StepKind): boolean => kind === 'read' || kind === 'search' || kind === 'plan';

function group(steps: Step[]): Step[][] {
    const groups: Step[][] = [];
    for (const step of steps) {
        const last = groups[groups.length - 1];
        if (last && groupable(step.kind) && last[0].kind === step.kind) {
            last.push(step);
        } else {
            groups.push([step]);
        }
    }

    return groups;
}

function lineFor(steps: Step[]): ActivityLine {
    const state = stateOf(steps);
    const running = state === 'running';
    const failed = state === 'failed';
    const [first] = steps;
    // A step that failed or was refused says it didn't happen, never "Wrote…" next to the cross.
    if (failed && steps.length === 1) {
        switch (first.kind) {
            case 'read':
                return { text: __('Couldn’t read %s', 'taw-core'), code: first.target, state };
            case 'edit':
                return { text: __('Didn’t edit %s', 'taw-core'), code: first.target, state };
            case 'write':
                return { text: __('Didn’t write %s', 'taw-core'), code: first.target, state };
            case 'run':
                return { text: __('Didn’t run %s', 'taw-core'), code: first.target, state };
        }
    }
    switch (first.kind) {
        case 'read': {
            const files = [...new Set(steps.map((s) => s.target).filter(Boolean))];
            if (files.length <= 1) {
                return {
                    text: running ? __('Reading %s', 'taw-core') : __('Read %s', 'taw-core'),
                    code: files[0] ?? '',
                    state,
                };
            }
            const folder = commonFolder(files);
            const count = String(files.length);
            return folder
                ? {
                      text: (running
                          ? __('Reading %d files in %s', 'taw-core')
                          : __('Read %d files in %s', 'taw-core')
                      ).replace('%d', count),
                      code: folder,
                      state,
                  }
                : {
                      text: (running ? __('Reading %d files', 'taw-core') : __('Read %d files', 'taw-core')).replace(
                          '%d',
                          count,
                      ),
                      code: '',
                      state,
                  };
        }
        case 'search':
            return steps.length === 1
                ? {
                      text: running ? __('Searching for %s', 'taw-core') : __('Searched for %s', 'taw-core'),
                      code: first.target,
                      state,
                  }
                : {
                      text: __('Searched the theme %d times', 'taw-core').replace('%d', String(steps.length)),
                      code: '',
                      state,
                  };
        case 'edit':
            return {
                text: running ? __('Editing %s', 'taw-core') : __('Edited %s', 'taw-core'),
                code: first.target,
                state,
            };
        case 'write':
            return {
                text: running ? __('Writing %s', 'taw-core') : __('Wrote %s', 'taw-core'),
                code: first.target,
                state,
            };
        case 'run':
            return {
                text: running ? __('Running %s', 'taw-core') : __('Ran %s', 'taw-core'),
                code: first.target,
                state,
            };
        case 'skill':
            return { text: __('Used the %s skill', 'taw-core'), code: first.target, state };
        case 'plan':
            return { text: __('Updated its plan', 'taw-core'), code: '', state };
        default:
            return { text: __('Used %s', 'taw-core'), code: first.name, state };
    }
}

/** The activity line for a run of steps. */
export const activity = (steps: Step[]): ActivityLine[] => group(steps).map(lineFor);

/** One step for Details: the tool and what it worked on. */
export const stepDetail = (step: Step): string => (step.target ? `${step.name} ${step.target}` : step.name);
