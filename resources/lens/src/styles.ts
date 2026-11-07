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

        /* Rings and label chips on the page (component 02, screen 05): click-through, under the panel. */
        .overlay {
            position: fixed;
            inset: 0;
            z-index: 2147483645;
            pointer-events: none;
        }
        .ring {
            position: fixed;
            border-radius: 4px;
        }
        .ring[data-state='selected'] {
            border: 2px solid var(--ring);
            box-shadow:
                inset 0 0 0 2px var(--halo),
                0 0 0 3px var(--halo);
        }
        .ring[data-state='hover'] {
            border: 1px solid var(--ring);
            box-shadow:
                inset 0 0 0 1px var(--halo),
                0 0 0 1px var(--halo);
        }
        .ring-label {
            position: fixed;
            height: 24px;
            display: flex;
            align-items: center;
            padding: 0 8px;
            border-radius: 4px;
            background: var(--ring);
            color: var(--halo);
            box-shadow: 0 0 0 2px var(--halo);
            font-family: var(--lens-font-mono);
            font-size: 12px;
            line-height: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Inspect card (component 03, screen 06): in the panel, above the composer. */
        .card {
            flex: 0 0 auto;
            margin: 0 16px;
            border: 1px solid var(--lens-line);
            border-radius: 10px;
            overflow: hidden;
            max-height: 55%;
            overflow-y: auto;
            background: var(--lens-surface);
        }
        .panel[data-mode='sheet'] .card {
            margin: 0 14px;
        }
        .card-head {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 14px 4px;
            min-height: 44px;
        }
        .card-title {
            font-size: 13px;
            font-weight: 500;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .card-badge {
            flex: 0 0 auto;
            font-size: 12px;
            color: var(--lens-muted);
            background: var(--lens-chip);
            border-radius: 4px;
            padding: 1px 6px;
            white-space: nowrap;
        }
        .card-close {
            flex: 0 0 auto;
            margin-left: auto;
            width: 28px;
            height: 28px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: var(--lens-muted-soft);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card-close:hover {
            background: var(--lens-chip);
            color: var(--lens-text);
        }
        .card-location {
            padding: 0 14px 10px;
            font-size: 13px;
            line-height: 1.55;
            color: var(--lens-text);
        }
        .card-value {
            margin: 0 14px;
            padding: 10px 12px;
            background: var(--lens-bubble);
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.5;
            overflow-wrap: anywhere;
            max-height: 160px;
            overflow-y: auto;
        }
        .card-empty {
            color: var(--lens-muted);
        }
        .card-note {
            padding: 10px 14px 0;
            font-size: 12px;
            line-height: 1.5;
            color: var(--lens-muted);
        }
        .card-meta {
            padding: 10px 14px 4px;
            font-size: 12px;
            color: var(--lens-muted-soft);
            overflow-wrap: anywhere;
        }
        .card-code {
            margin: 0 14px;
            padding: 8px 10px;
            background: var(--lens-bubble);
            border-radius: 8px;
            font-size: 12px;
            line-height: 1.7;
            color: var(--lens-text);
            overflow-x: auto;
        }
        .card-code-line {
            display: flex;
            gap: 10px;
            white-space: pre;
            padding: 0 4px;
            margin: 0 -4px;
            border-radius: 3px;
        }
        .card-code-line[data-current] {
            background: var(--lens-chip);
        }
        .card-code-number {
            flex: 0 0 auto;
            min-width: 2ch;
            text-align: right;
            color: var(--lens-muted-soft);
        }
        .card-files {
            list-style: none;
            margin: 0;
            padding: 0 14px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 12px;
        }
        .card-files a,
        .card-link {
            color: inherit;
            text-decoration: none;
        }
        .card-files a:hover,
        .card-link:hover {
            text-decoration: underline;
        }
        .card-sources {
            list-style: none;
            margin: 8px 0 0;
            padding: 0 14px 4px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .card-sources li {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 0 8px;
            padding: 8px 10px;
            background: var(--lens-bubble);
            border-radius: 8px;
            font-size: 12px;
        }
        .card-source-name {
            font-size: 12px;
            overflow-wrap: anywhere;
        }
        .card-source-where {
            grid-column: 1;
            color: var(--lens-muted);
            overflow-wrap: anywhere;
        }
        .card-link {
            grid-column: 2;
            grid-row: 1 / span 2;
            align-self: center;
            font-size: 12px;
            font-weight: 500;
        }
        .card-actions {
            display: flex;
            gap: 8px;
            padding: 10px 14px 14px;
        }
        .card-actions:empty {
            padding: 0 0 10px;
        }
        .card-button {
            flex: 1 1 0;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            border: 1px solid var(--lens-button-line);
            background: var(--lens-surface);
            color: var(--lens-text);
            font-size: 13px;
            text-decoration: none;
            white-space: nowrap;
        }
        .card-button:hover {
            background: var(--lens-bubble);
        }
        .card-button.primary {
            border-color: var(--lens-accent);
            background: var(--lens-accent);
            color: var(--lens-on-accent);
            font-weight: 500;
        }
        .card-button[aria-disabled='true'] {
            opacity: 0.45;
            cursor: default;
        }

        /* Selection chips above the composer. */
        .chips {
            flex: 0 0 auto;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            padding: 12px 16px 0;
        }
        .chip {
            font-family: var(--lens-font-mono);
            border: 0;
            background: var(--lens-chip);
            color: var(--lens-text);
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 12px;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .chip[data-primary] {
            box-shadow: inset 0 0 0 1px var(--lens-accent);
        }
        .chips-hint {
            font-size: 12px;
            color: var(--lens-muted-soft);
        }

        /* Structure view (screen 04). */
        .structure {
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 20px 20px 12px;
        }
        .structure > * {
            flex-shrink: 0;
        }
        .panel[data-mode='sheet'] .structure {
            padding: 16px 16px 10px;
        }
        .structure-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .structure-title {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--lens-muted);
        }
        .text-button {
            border: 0;
            background: transparent;
            padding: 2px 4px;
            margin: -2px -4px;
            border-radius: 4px;
            font-size: 12px;
            color: var(--lens-muted-soft);
        }
        .text-button:hover {
            color: var(--lens-text);
        }
        .filter {
            height: 34px;
            border: 1px solid var(--lens-composer-line);
            border-radius: 8px;
            padding: 0 10px;
            background: var(--lens-surface);
            font-size: 13px;
            color: var(--lens-text);
            outline: none;
            -webkit-appearance: none;
            appearance: none;
        }
        .filter::placeholder {
            color: var(--lens-muted-soft);
        }
        .filter:focus-visible {
            border-color: var(--lens-accent);
            outline: none;
        }
        .tree {
            display: flex;
            flex-direction: column;
            gap: 1px;
        }
        .tree-row {
            display: flex;
            align-items: center;
            gap: 6px;
            height: 30px;
            padding-right: 8px;
            border-radius: 6px;
            font-size: 13px;
            cursor: default;
            min-width: 0;
        }
        .tree-row:hover {
            background: var(--lens-bubble);
        }
        .tree-row[data-selected] {
            background: var(--lens-chip);
        }
        .tree-row[data-selected] .tree-name {
            font-weight: 600;
        }
        .tree-row:focus-visible {
            outline-offset: -2px;
        }
        .tree-twisty {
            flex: 0 0 12px;
            width: 12px;
            height: 12px;
            display: flex;
            color: var(--lens-muted);
        }
        .tree-icon {
            flex: 0 0 14px;
            display: flex;
            color: var(--lens-muted);
        }
        .tree-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex: 0 0 auto;
            max-width: 62%;
        }
        .tree-detail {
            font-size: 11px;
            color: var(--lens-muted-soft);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex: 0 1000 auto;
            min-width: 24px;
        }
        .tree-hint {
            margin-left: auto;
            flex: 0 0 auto;
            font-size: 11px;
            color: var(--lens-muted-soft);
        }
        .tree-none,
        .structure-help {
            font-size: 12px;
            line-height: 1.5;
            color: var(--lens-muted-soft);
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
