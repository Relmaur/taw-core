/**
 * The TAW data popup's look (outer editor document), in WordPress admin
 * colors. Chips inside the canvas iframe are styled by
 * Bindings::enqueueCanvasStyles().
 */
const CSS = `
.taw-data-dropdown .components-popover__content{width:400px;max-width:calc(100vw - 32px);padding:0;overflow:hidden}
.taw-chip-popover .components-popover__content{width:400px;max-width:calc(100vw - 32px);padding:0}
.taw-data-popup{font-size:13px;color:#1e1e1e}
.taw-data-header{display:flex;align-items:center;gap:8px;padding:10px 8px 10px 16px;border-bottom:1px solid #e0e0e0}
.taw-data-header strong{flex:1;font-weight:600}
.taw-data-header .dashicon{color:var(--wp-admin-theme-color,#3858e9)}
.taw-data-mode{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px 0}
.taw-data-mode__label{color:#757575;font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:500}
.taw-data-mode__options{display:inline-flex;background:#f0f0f0;border-radius:4px;padding:2px}
.taw-data-mode__options button{display:inline-flex;align-items:center;gap:4px;border:0;background:none;border-radius:3px;padding:4px 10px;font-size:12px;color:#1e1e1e;cursor:pointer}
.taw-data-mode__options button .dashicon{font-size:16px;width:16px;height:16px}
.taw-data-mode__options button.is-selected{background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.12);font-weight:500}
.taw-data-mode__options button:disabled{opacity:.4;cursor:default}
.taw-data-note{display:flex;gap:6px;align-items:flex-start;margin:10px 16px 0;padding:8px 10px;background:#f0f6fc;border-radius:2px;color:#1e1e1e;font-size:12px;line-height:1.4}
.taw-data-note .dashicon{color:var(--wp-admin-theme-color,#3858e9);flex-shrink:0;font-size:16px;width:16px;height:16px}
.taw-data-tabs .components-tab-panel__tabs{padding:4px 8px 0;border-bottom:1px solid #e0e0e0}
.taw-data-fields,.taw-data-expression{padding:12px 16px 16px}
.taw-data-groups{max-height:320px;overflow:auto;margin:10px -16px -16px;padding:0 8px 8px}
.taw-data-group h3{display:flex;justify-content:space-between;position:sticky;top:0;z-index:1;background:#fff;margin:0;padding:10px 8px 4px;font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:.04em;color:#757575}
.taw-data-group h3 span{color:#949494;font-weight:400}
.taw-data-item{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;border:0;background:none;border-radius:2px;padding:6px 8px;text-align:left;cursor:pointer;color:inherit}
.taw-data-item:hover:not(:disabled),.taw-data-item:focus-visible{background:#f0f4ff;outline:none}
.taw-data-item:focus-visible{box-shadow:inset 0 0 0 1.5px var(--wp-admin-theme-color,#3858e9)}
.taw-data-item:disabled{opacity:.45;cursor:default}
.taw-data-item__main{display:flex;flex-direction:column;min-width:0;gap:1px}
.taw-data-item__label{font-weight:500}
.taw-data-item__main code{font-size:11px;background:none;padding:0;color:#757575}
.taw-data-item__value{color:#757575;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:45%}
.taw-data-empty{color:#757575;padding:16px 8px;margin:0;text-align:center}
.taw-data-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}
.taw-data-footer{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 16px;border-top:1px solid #e0e0e0;background:#fafafa}
.taw-data-footer__status{display:flex;align-items:center;gap:6px;min-width:0;color:#757575;font-size:12px}
.taw-data-footer__status code{font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:inline-block;max-width:220px;vertical-align:bottom}
.taw-data-footer__status .dashicon{font-size:16px;width:16px;height:16px;flex-shrink:0}
.taw-expression-field{position:relative}
.taw-expression-field textarea{display:block;width:100%;box-sizing:border-box;min-height:76px;padding:10px 12px;font-family:Menlo,Consolas,monospace;font-size:13px;line-height:1.5;border:1px solid #949494;border-radius:2px;resize:vertical;background:#fff}
.taw-expression-field textarea:focus{border-color:var(--wp-admin-theme-color,#3858e9);box-shadow:0 0 0 1px var(--wp-admin-theme-color,#3858e9);outline:none}
.taw-expression-suggestions{position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:10;list-style:none;margin:0;padding:4px;border:1px solid #e0e0e0;border-radius:2px;background:#fff;max-height:220px;overflow:auto;box-shadow:0 4px 12px rgba(0,0,0,.12)}
.taw-expression-suggestions li{margin:0}
.taw-expression-suggestions button{display:flex;justify-content:space-between;gap:12px;width:100%;text-align:left;border:0;background:none;border-radius:2px;padding:6px 8px;cursor:pointer;font-size:12px;color:#1e1e1e}
.taw-expression-suggestions button.is-active,.taw-expression-suggestions button:hover{background:var(--wp-admin-theme-color,#3858e9);color:#fff}
.taw-expression-suggestions button.is-active span,.taw-expression-suggestions button:hover span{color:rgba(255,255,255,.85)}
.taw-expression-suggestions code{background:none;padding:0;font-size:12px;color:inherit}
.taw-expression-suggestions span{color:#757575;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.taw-expression-helpers{display:flex;flex-wrap:wrap;gap:4px;margin-top:8px}
.taw-expression-helpers button{border:1px solid #e0e0e0;background:#fff;border-radius:12px;padding:2px 8px;font-family:Menlo,Consolas,monospace;font-size:11px;color:#1e1e1e;cursor:pointer}
.taw-expression-helpers button:hover{border-color:var(--wp-admin-theme-color,#3858e9);color:var(--wp-admin-theme-color,#3858e9)}
.taw-expression-preview{display:flex;flex-direction:column;gap:4px;margin-top:12px;padding:10px 12px;background:#f6f7f7;border-radius:2px}
.taw-expression-preview__label{font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:.04em;color:#757575}
.taw-expression-preview__value{word-break:break-word;line-height:1.5}
.taw-expression-preview__value em{color:#757575}
.taw-expression-errors{list-style:none;margin:8px 0 0;padding:0}
.taw-expression-errors li{display:flex;gap:6px;align-items:flex-start;margin:0 0 4px;color:#cc1818;font-size:12px}
.taw-expression-errors .dashicon{font-size:16px;width:16px;height:16px;flex-shrink:0}
.taw-chip-popover .taw-data-popup{padding:12px 16px 16px}
.taw-data-expression .taw-data-note{margin:10px 0 0}
`;

export function injectStyles(): void {
    if (document.getElementById('taw-data-popup-styles')) return;
    const style = document.createElement('style');
    style.id = 'taw-data-popup-styles';
    style.textContent = CSS;
    document.head.appendChild(style);
}
