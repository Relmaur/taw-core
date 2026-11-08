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
        .ring[data-state='editing'] {
            border: 2px dashed var(--ring);
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

        /* Header status (screen 07): not connected, connecting. */
        .status {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--lens-muted);
            white-space: nowrap;
        }
        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--lens-muted-soft);
        }
        .spinner {
            flex: 0 0 auto;
        }
        @media (prefers-reduced-motion: no-preference) {
            .spinner {
                animation: lens-spin 0.9s linear infinite;
            }
        }
        @keyframes lens-spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Conversation (component 01). */
        .conversation {
            display: flex;
            flex-direction: column;
            gap: 18px;
            padding: 20px;
        }
        .panel[data-mode='sheet'] .conversation {
            padding: 16px;
        }
        .turn {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .message {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
        }
        .message-chips {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 6px;
            max-width: 100%;
        }
        .bubble {
            max-width: 88%;
            background: var(--lens-bubble);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            line-height: 1.55;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }
        .reply {
            font-size: 14px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }
        .md > * {
            margin: 0;
        }
        .md > * + * {
            margin-top: 10px;
        }
        .md ul,
        .md ol {
            padding-left: 20px;
        }
        .md li + li {
            margin-top: 4px;
        }
        .md code,
        .step-text code,
        .notice-text code {
            font-family: var(--lens-font-mono);
            font-size: 0.88em;
        }
        .md pre {
            background: var(--lens-bubble);
            border-radius: 8px;
            padding: 10px 12px;
            overflow-x: auto;
            font-family: var(--lens-font-mono);
            font-size: 12px;
            line-height: 1.6;
            white-space: pre;
        }
        .md pre code {
            font-size: inherit;
        }
        .md a {
            color: inherit;
            text-decoration: underline;
            text-underline-offset: 2px;
        }
        .turn-note,
        .busy-line {
            font-size: 12px;
            color: var(--lens-muted-soft);
        }

        /* The activity line: quiet, one line per step kind. */
        .activity {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            font-size: 13px;
            color: var(--lens-muted);
        }
        .step-icon {
            flex: 0 0 auto;
            display: flex;
            color: var(--lens-muted);
        }
        .step-text {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .step-text code {
            color: var(--lens-text);
        }
        .step .step-details {
            margin-left: auto;
            flex: 0 0 auto;
        }
        .step-list {
            list-style: none;
            margin: 0;
            padding: 0 0 0 22px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 12px;
            color: var(--lens-muted);
        }
        .step-list li {
            display: flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
        }
        .step-list code {
            font-family: var(--lens-font-mono);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Approval card: amber means "needs your OK", and only that. */
        .approval,
        .busy {
            border: 1px solid var(--lens-amber-line);
            background: var(--lens-amber-fill);
            border-radius: 10px;
            overflow: hidden;
        }
        .approval-head,
        .busy-head {
            display: flex;
            align-items: baseline;
            gap: 8px;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 500;
            min-width: 0;
        }
        .approval-dot {
            flex: 0 0 auto;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--lens-amber-dot);
            align-self: center;
        }
        /* A long path gives way, never the title ("Edit 1 file" stays on one line). */
        .approval-title {
            flex: 0 0 auto;
            white-space: nowrap;
        }
        .approval-file {
            flex: 1 1 auto;
            font-family: var(--lens-font-mono);
            font-size: 12px;
            font-weight: 400;
            color: var(--lens-amber-text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
        }
        .diff,
        .approval-body {
            margin: 0;
            border-top: 1px solid var(--lens-amber-rule);
            border-bottom: 1px solid var(--lens-amber-rule);
            background: var(--lens-surface);
        }
        .diff {
            padding: 8px 0;
            font-family: var(--lens-font-mono);
            font-size: 12px;
            line-height: 1.75;
            max-height: 320px;
            overflow: auto;
        }
        .diff-line {
            padding: 0 16px;
            white-space: pre;
            color: var(--lens-muted);
        }
        .diff-line[data-kind='removed'] {
            background: var(--lens-diff-removed-fill);
            color: var(--lens-diff-removed-text);
        }
        .diff-line[data-kind='added'] {
            background: var(--lens-diff-added-fill);
            color: var(--lens-diff-added-text);
        }
        .diff-sign {
            display: inline-block;
            width: 1.4em;
        }
        .diff-more {
            font-style: italic;
        }
        .approval-body {
            padding: 10px 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .approval-note {
            font-size: 12px;
            color: var(--lens-muted);
        }
        .approval-code {
            margin: 0;
            font-family: var(--lens-font-mono);
            font-size: 12px;
            line-height: 1.6;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            max-height: 240px;
            overflow: auto;
        }
        .approval-actions,
        .busy-actions,
        .notice-actions {
            display: flex;
            gap: 8px;
            padding: 12px 16px 14px;
        }
        .approval-actions .card-button {
            height: 40px;
            font-size: 14px;
        }
        .busy-actions .card-button,
        .notice-actions .card-button {
            flex: 0 0 auto;
            padding: 0 14px;
        }
        .card-button:disabled {
            opacity: 0.45;
            cursor: default;
        }
        .approval-done {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--lens-muted);
            min-width: 0;
        }

        /* Theme busy (screen 07): warn, don't block. */
        .busy-text {
            margin: 0;
            padding: 0 16px;
            font-size: 13px;
            line-height: 1.55;
            color: var(--lens-amber-text);
        }
        .busy-compact {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            font-size: 12px;
            color: var(--lens-amber-text);
        }
        .busy-compact .text-button {
            margin-left: auto;
            flex: 0 0 auto;
        }

        /* Error (screen 07): ink, not red. */
        .notice {
            border: 1px solid var(--lens-line);
            border-radius: 10px;
            background: var(--lens-surface);
        }
        .notice-head {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px 0;
            font-size: 14px;
            font-weight: 600;
        }
        .notice-text {
            margin: 6px 0 0;
            padding: 0 16px;
            font-size: 13px;
            line-height: 1.55;
            color: var(--lens-muted);
        }
        .notice-detail {
            margin: 0 16px 14px;
            padding: 10px 12px;
            border-radius: 8px;
            background: var(--lens-bubble);
            font-family: var(--lens-font-mono);
            font-size: 11.5px;
            line-height: 1.6;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            max-height: 200px;
            overflow: auto;
        }

        /* Not connected (screen 07): the command to run. */
        .command {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            width: 100%;
            max-width: 300px;
            border: 1px solid var(--lens-composer-line);
            background: var(--lens-bubble);
            border-radius: 8px;
            padding: 6px 6px 6px 12px;
        }
        .command code {
            font-family: var(--lens-font-mono);
            font-size: 13px;
        }
        .command-copy {
            height: 28px;
            padding: 0 10px;
            border-radius: 6px;
            border: 1px solid var(--lens-button-line);
            background: var(--lens-surface);
            color: var(--lens-text);
            font-size: 12px;
        }
        .command-copy:hover {
            background: var(--lens-chip);
        }
        .empty-note {
            font-size: 12px;
            color: var(--lens-muted-soft);
        }

        /* Connecting (screen 07): skeleton lines. */
        .skeleton {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 20px;
        }
        .skeleton span {
            display: block;
            height: 10px;
            border-radius: 5px;
            background: var(--lens-chip);
        }

        /* Composer: grows with the message; Send becomes Stop while Claude works. */
        textarea.composer-input {
            resize: none;
            height: 30px;
            padding: 6px 0;
            font: inherit;
            font-size: 14px;
            line-height: 1.3;
        }
        .composer-box {
            align-items: flex-end;
        }
        .stop {
            flex: 0 0 auto;
            height: 32px;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 0 12px;
            border-radius: 6px;
            border: 1px solid var(--lens-button-line);
            background: var(--lens-surface);
            color: var(--lens-text);
            font-size: 13px;
        }
        .stop:hover {
            background: var(--lens-bubble);
        }

        /* Editing in place (component 05, screens 08–10): the card stays in the panel, the page gets the caret. */
        .card.edit {
            border-color: var(--lens-accent);
            display: flex;
            flex-direction: column;
            max-height: 75%;
        }
        .edit-status {
            margin-left: auto;
            flex: 0 0 auto;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
        }
        .edit-change {
            margin: 0 14px;
            padding: 8px 10px;
            background: var(--lens-bubble);
            border-radius: 8px;
            font-size: 12px;
            line-height: 1.6;
            overflow-wrap: anywhere;
            max-height: 140px;
            overflow-y: auto;
        }
        .edit-before {
            color: var(--lens-diff-removed-text);
            text-decoration: line-through;
        }
        .edit-after {
            color: var(--lens-diff-added-text);
        }
        .edit-hint {
            font-size: 12px;
            line-height: 1.5;
            color: var(--lens-muted-soft);
        }
        .edit-note {
            margin: 10px 14px 0;
        }
        .edit-error,
        .card-why {
            margin: 10px 14px 0;
            padding: 0;
            font-size: 12px;
            line-height: 1.5;
            color: var(--lens-text);
        }
        .edit-error {
            font-weight: 500;
        }
        .edit-actions {
            align-items: center;
            padding-top: 12px;
            /* Save and Cancel stay in view when the card scrolls (a short sheet, the link row open). */
            position: sticky;
            bottom: 0;
            background: var(--lens-surface);
        }
        .edit-actions .card-button {
            flex: 0 0 auto;
            padding: 0 16px;
            gap: 6px;
        }
        .edit-keys {
            margin-left: auto;
            font-size: 12px;
            color: var(--lens-muted-soft);
            white-space: nowrap;
        }
        .edit-toolbar {
            display: flex;
            align-items: center;
            gap: 2px;
            margin: 0 14px 10px;
            padding: 4px;
            border: 1px solid var(--lens-line);
            border-radius: 8px;
        }
        .tool {
            flex: 0 0 auto;
            width: 32px;
            height: 32px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: var(--lens-muted);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .tool:hover {
            background: var(--lens-chip);
            color: var(--lens-text);
        }
        .tool[aria-pressed='true'] {
            background: var(--lens-accent);
            color: var(--lens-on-accent);
        }
        .tool-b {
            font-weight: 700;
            font-size: 14px;
        }
        .tool-i {
            font-style: italic;
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 15px;
        }
        .tool-rule {
            width: 1px;
            height: 18px;
            background: var(--lens-line);
            margin: 0 4px;
        }
        .tool-note {
            margin-left: auto;
            padding-right: 6px;
            font-size: 11px;
            color: var(--lens-muted-soft);
            white-space: nowrap;
        }
        .edit-link {
            margin: 0 14px 10px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .edit-link-label {
            font-size: 12px;
            color: var(--lens-muted);
        }
        .edit-link-row {
            display: flex;
            gap: 6px;
        }
        .edit-link-row .card-button {
            flex: 0 0 auto;
            height: 32px;
            padding: 0 12px;
        }
        .edit-link-input {
            flex: 1 1 auto;
            min-width: 0;
            height: 32px;
            border: 1px solid var(--lens-accent);
            border-radius: 6px;
            padding: 0 10px;
            background: var(--lens-surface);
            color: var(--lens-text);
            font-size: 13px;
            outline: none;
        }
        .edit-link-input:focus-visible {
            box-shadow:
                0 0 0 2px var(--lens-surface),
                0 0 0 4px var(--lens-accent);
        }

        /* "Saved to hero_title · Undo" (screen 09), in the conversation where it happened. */
        .saved {
            border: 1px solid var(--lens-line);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
        }
        .saved-line {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .saved-text code {
            font-family: var(--lens-font-mono);
            font-size: 12px;
        }
        .saved-undo {
            margin-left: auto;
            border: 0;
            padding: 0;
            background: transparent;
            color: var(--lens-text);
            font-size: 12px;
            text-decoration: underline;
            text-underline-offset: 2px;
            display: flex;
            align-items: center;
        }
        .saved-error {
            margin-top: 6px;
            font-size: 12px;
            line-height: 1.5;
        }
        .message-from {
            font-size: 11px;
            color: var(--lens-muted-soft);
        }

        /* The conversation menu (component 06, screen 15): the header's title button and its menu. */
        .conv-title {
            display: flex;
            align-items: center;
            gap: 4px;
            min-width: 0;
            max-width: 136px;
            height: 28px;
            padding: 0 6px 0 8px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: var(--lens-text);
            font: 500 13px var(--lens-font-ui);
            cursor: pointer;
        }
        .conv-title:hover,
        .conv-title[aria-expanded='true'] {
            background: var(--lens-chip);
        }
        .conv-title-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .conv-title svg {
            flex: 0 0 auto;
        }
        .conv-menu {
            position: absolute;
            top: 58px;
            left: 12px;
            z-index: 2;
            width: 312px;
            max-width: calc(100% - 24px);
            max-height: calc(100% - 70px);
            overflow-y: auto;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 6px;
            background: var(--lens-surface);
            border: 1px solid var(--lens-line);
            border-radius: 10px;
            box-shadow: var(--lens-menu-shadow);
        }
        .panel[data-mode='sheet'] .conv-menu {
            top: 54px;
        }
        .conv-new,
        .conv-item {
            display: flex;
            gap: 10px;
            width: 100%;
            border: 0;
            border-radius: 6px;
            padding: 8px 10px;
            background: transparent;
            color: var(--lens-text);
            text-align: left;
            font: 500 13px var(--lens-font-ui);
            cursor: pointer;
        }
        .conv-new {
            align-items: center;
        }
        .conv-item {
            align-items: flex-start;
        }
        .conv-new:hover,
        .conv-item:hover,
        .conv-item[aria-checked='true'] {
            background: var(--lens-bubble);
        }
        .conv-new[aria-disabled='true'],
        .conv-item[aria-disabled='true'] {
            color: var(--lens-muted-soft);
            background: transparent;
            cursor: default;
        }
        .conv-reason {
            padding: 2px 10px 6px;
            font-size: 12px;
            line-height: 1.5;
            color: var(--lens-text);
        }
        .conv-rule {
            height: 1px;
            margin: 4px;
            background: var(--lens-line);
        }
        .conv-label {
            padding: 6px 10px 2px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--lens-muted);
        }
        .conv-tick {
            display: flex;
            flex: 0 0 14px;
            padding-top: 2px;
        }
        .conv-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }
        .conv-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .conv-detail {
            font-weight: 400;
            font-size: 12px;
            color: var(--lens-muted);
        }
        .conv-empty {
            padding: 4px 10px 8px;
            font-size: 12px;
            line-height: 1.5;
            color: var(--lens-muted-soft);
        }
        .conv-host {
            padding: 6px 10px 4px;
            font-family: var(--lens-font-mono);
            font-size: 11px;
            color: var(--lens-muted-soft);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        /* A replayed conversation: the dividers, and chips for what isn't on this page. */
        .divider {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 11px;
            color: var(--lens-muted-soft);
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1 1 auto;
            height: 1px;
            background: var(--lens-line);
        }
        .chip.chip-away {
            background: transparent;
            border: 1px dashed var(--lens-button-line);
            color: var(--lens-muted-soft);
            padding: 2px 7px;
        }
        .opening {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 20px 20px 0;
            font-size: 12px;
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
