/**
 * The TAW Data sidebar (taw/core ADR-0007). Loaded only in the post editor,
 * for a post with at least one fieldset in the panel.
 */
import { registerPlugin } from '@wordpress/plugins';
import DataPanel, { ICON, SIDEBAR } from './DataPanel';
import './panel.scss';

registerPlugin(SIDEBAR, { render: DataPanel, icon: ICON });
