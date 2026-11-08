import { describe, expect, it } from 'vitest';
import { activity, commonFolder, stepDetail } from './activity';
import type { Step } from './chatModel';

const step = (id: string, name: string, kind: Step['kind'], target: string, state: Step['state'] = 'done'): Step => ({
    id,
    name,
    kind,
    target,
    state,
});

describe('the activity line', () => {
    it('reads like screen 01: "Read 3 files in Blocks/Hero/"', () => {
        const lines = activity([
            step('1', 'Read', 'read', 'Blocks/Hero/Hero.php'),
            step('2', 'Read', 'read', 'Blocks/Hero/index.php'),
            step('3', 'Read', 'read', 'Blocks/Hero/style.css'),
        ]);
        expect(lines).toEqual([{ text: 'Read 3 files in %s', code: 'Blocks/Hero/', state: 'done' }]);
    });

    it('reads like screen 07: a finished step, then the current one', () => {
        const lines = activity([
            step('1', 'Read', 'read', 'Blocks/WorkGrid/WorkGrid.php'),
            step('2', 'Read', 'read', 'Blocks/WorkGrid/style.css'),
            step('3', 'Edit', 'edit', 'Blocks/WorkGrid/style.css', 'running'),
        ]);
        expect(lines).toEqual([
            { text: 'Read 2 files in %s', code: 'Blocks/WorkGrid/', state: 'done' },
            { text: 'Editing %s', code: 'Blocks/WorkGrid/style.css', state: 'running' },
        ]);
    });

    it('names one file, a command, a search, a skill; groups plan updates', () => {
        expect(activity([step('1', 'Read', 'read', 'page.php', 'running')])).toEqual([
            { text: 'Reading %s', code: 'page.php', state: 'running' },
        ]);
        expect(activity([step('1', 'Bash', 'run', 'npm run build', 'failed')])).toEqual([
            { text: 'Didn’t run %s', code: 'npm run build', state: 'failed' },
        ]);
        expect(activity([step('1', 'Grep', 'search', 'hero_title'), step('2', 'Glob', 'search', '**/*.php')])).toEqual([
            { text: 'Searched the theme 2 times', code: '', state: 'done' },
        ]);
        expect(activity([step('1', 'Skill', 'skill', 'make-metablock')])[0].code).toBe('make-metablock');
        expect(activity([step('1', 'TodoWrite', 'plan', ''), step('2', 'TodoWrite', 'plan', '')])).toHaveLength(1);
        expect(activity([step('1', 'Read', 'read', 'a/x.php'), step('2', 'Read', 'read', 'b/y.php')])[0].text).toBe(
            'Read 2 files',
        );
    });

    it('never says a refused change happened', () => {
        expect(activity([step('1', 'Write', 'write', 'a.txt', 'failed')])[0].text).toBe('Didn’t write %s');
        expect(activity([step('1', 'Edit', 'edit', 'a.css', 'failed')])[0].text).toBe('Didn’t edit %s');
    });

    it('finds the folder files share', () => {
        expect(commonFolder(['Blocks/Hero/a.php', 'Blocks/Hero/b/c.php'])).toBe('Blocks/Hero/');
        expect(commonFolder(['page.php', 'single.php'])).toBe('');
        expect(stepDetail(step('1', 'Read', 'read', 'page.php'))).toBe('Read page.php');
    });
});
