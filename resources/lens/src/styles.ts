/**
 * The panel's stylesheet, for the shadow root only (design round 1: screens 01–03 and 07, the shared
 * rules in screens/README). Values come from the tokens (`--lens-*` on :host); the rest is rhythm.
 *
 * Inherited properties (font, colour, line height…) cross a shadow boundary, so `.lens` sets every one
 * of them itself: the site's typography never reaches the panel.
 */
import { tokensCss } from './tokens';

const css = String.raw;

export const panelCss = (): string =>
    tokensCss() +
    css`
        :host {
            all: initial;
        }
        .lens,
        .lens * {
            box-sizing: border-box;
        }
        .lens {
            font-family: var(--lens-font-ui);
            font-size: 14px;
            line-height: 1.5;
            font-weight: 400;
            font-style: normal;
            letter-spacing: normal;
            text-transform: none;
            text-align: left;
            text-indent: 0;
            word-spacing: normal;
            white-space: normal;
            color: var(--lens-text);
            -webkit-font-smoothing: antialiased;
            cursor: auto;
        }
        .lens button,
        .lens input {
            font: inherit;
            color: inherit;
            margin: 0;
        }
        .lens button {
            cursor: pointer;
        }
        .lens :focus {
            outline: none;
        }
        .lens :focus-visible {
            outline: 2px solid var(--lens-accent);
            outline-offset: 2px;
        }
        .mono {
            font-family: var(--lens-font-mono);
        }
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
        }

        /* The panel: docked (full height, square, a hairline, no shadow) or a sheet (radius, faint shadow). */
        .panel {
            position: fixed;
            z-index: 2147483647;
            display: flex;
            flex-direction: column;
            width: var(--lens-docked-width);
            background: var(--lens-surface);
            color: var(--lens-text);
        }
        .panel[data-mode='docked'] {
            top: var(--lens-top, 0px);
            bottom: 0;
        }
        .panel[data-mode='docked'][data-side='right'] {
            right: 0;
            border-left: 1px solid var(--lens-line);
        }
        .panel[data-mode='docked'][data-side='left'] {
            left: 0;
            border-right: 1px solid var(--lens-line);
        }
        .panel[data-mode='sheet'] {
            width: var(--lens-sheet-width);
            border: 1px solid var(--lens-line);
            border-radius: var(--lens-sheet-radius);
            box-shadow: var(--lens-sheet-shadow);
            overflow: hidden;
        }
        .panel[data-dragging] {
            user-select: none;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex: 0 0 auto;
            height: 52px;
            padding: 0 12px 0 16px;
            border-bottom: 1px solid var(--lens-line);
        }
        .panel[data-mode='sheet'] .header {
            height: 48px;
            padding: 0 10px 0 12px;
            cursor: grab;
            touch-action: none;
        }
        .panel[data-dragging] .header {
            cursor: grabbing;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }
        .brand-name {
            font-weight: 600;
            font-size: 14px;
        }
        .brand-host {
            font-family: var(--lens-font-mono);
            font-size: 12px;
            color: var(--lens-muted-soft);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .grip {
            display: flex;
            color: var(--lens-muted-soft);
        }
        .tools {
            display: flex;
            align-items: center;
            gap: 2px;
            flex: 0 0 auto;
        }
        .icon-button {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 0;
            padding: 0;
            background: transparent;
            color: var(--lens-muted);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .icon-button:hover,
        .icon-button[aria-pressed='true'] {
            background: var(--lens-chip);
            color: var(--lens-text);
        }
        .icon-button[aria-disabled='true'] {
            cursor: default;
            opacity: 0.45;
            background: transparent;
            color: var(--lens-muted);
        }

        .body {
            flex: 1 1 auto;
            min-height: 0;
            overflow: auto;
            display: flex;
            flex-direction: column;
        }
        .empty {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 32px;
            text-align: center;
        }
        .empty-title {
            font-size: 15px;
            font-weight: 600;
        }
        .empty-text {
            font-size: 13px;
            line-height: 1.6;
            color: var(--lens-muted);
            max-width: 280px;
        }
        .gestures {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 8px;
            font-size: 12px;
            color: var(--lens-muted);
        }
        .kbd {
            font-family: var(--lens-font-mono);
            border: 1px solid var(--lens-button-line);
            border-radius: 4px;
            padding: 1px 5px;
            color: var(--lens-text);
        }

        .composer {
            flex: 0 0 auto;
            padding: 12px 16px 16px;
        }
        .panel[data-mode='sheet'] .composer {
            padding: 10px 14px 14px;
        }
        .composer-box {
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--lens-composer-line);
            background: var(--lens-surface);
            border-radius: 10px;
            padding: 6px 6px 6px 12px;
        }
        .composer-box[data-disabled] {
            background: var(--lens-bubble);
        }
        .composer-input {
            flex: 1 1 auto;
            min-width: 0;
            height: 30px;
            border: 0;
            background: transparent;
            outline: none;
            font-size: 14px;
            color: var(--lens-text);
        }
        .composer-input::placeholder {
            color: var(--lens-muted-soft);
            opacity: 1;
        }
        .composer-input:disabled {
            cursor: not-allowed;
        }
        .send {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 0;
            padding: 0;
            flex: 0 0 auto;
            background: var(--lens-accent);
            color: var(--lens-on-accent);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .send:disabled {
            background: var(--lens-composer-line);
            color: var(--lens-muted-soft);
            cursor: not-allowed;
        }

        /* Hidden: a small tab on the docked edge brings the panel back. */
        .reopen {
            position: fixed;
            z-index: 2147483647;
            bottom: 16px;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid var(--lens-line);
            background: var(--lens-surface);
            color: var(--lens-text);
            box-shadow: var(--lens-sheet-shadow);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        .reopen[data-side='right'] {
            right: 16px;
        }
        .reopen[data-side='left'] {
            left: 16px;
        }
        .reopen:hover {
            background: var(--lens-chip);
        }

        /* Drag-to-dock: the zone that appears while a sheet is within reach of an edge. */
        .dock-zone {
            position: fixed;
            z-index: 2147483646;
            top: calc(var(--lens-top, 0px) + 12px);
            bottom: 12px;
            width: var(--lens-docked-width);
            border: 1.5px dashed var(--lens-accent);
            border-radius: 12px;
            background: color-mix(in srgb, var(--lens-accent) 4%, transparent);
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding-bottom: 28px;
            pointer-events: none;
        }
        .dock-zone[data-side='left'] {
            left: 12px;
        }
        .dock-zone[data-side='right'] {
            right: 12px;
        }
        .dock-zone-label {
            background: var(--lens-surface);
            color: var(--lens-text);
            font-size: 13px;
            font-weight: 500;
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid var(--lens-button-line);
        }

        @media (prefers-reduced-motion: no-preference) {
            .icon-button,
            .send,
            .reopen {
                transition:
                    background-color 0.12s ease,
                    color 0.12s ease;
            }
        }
    `;
