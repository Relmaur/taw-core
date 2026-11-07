/** The panel's icons, from the round-1 screens (24px grid, 1.8 stroke). All decorative: buttons carry the label. */
import type { JSX } from 'preact';

type Props = { size?: number };

const Stroke = ({
    size = 16,
    width = 1.8,
    children,
}: Props & { width?: number; children: JSX.Element | JSX.Element[] }) => (
    <svg
        width={size}
        height={size}
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width={width}
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
    >
        {children}
    </svg>
);

/** The Lens mark: a magnifier. */
export const LensMark = (props: Props) => (
    <Stroke {...props}>
        <circle cx="11" cy="11" r="6.5" />
        <path d="M16 16l4.5 4.5" />
    </Stroke>
);

export const PickIcon = (props: Props) => (
    <Stroke {...props}>
        <path d="M5 3l14 7-6 2-2 6z" />
    </Stroke>
);

export const TreeIcon = (props: Props) => (
    <Stroke {...props}>
        <path d="M4 5h6M8 12h6M8 19h6M6 5v14M6 12h2M6 19h2" />
    </Stroke>
);

/** Docked → "Undock into a sheet". */
export const UndockIcon = (props: Props) => (
    <Stroke {...props}>
        <rect x="4" y="6" width="12" height="12" rx="2" />
        <path d="M13 3h8v8M21 3l-8 8" />
    </Stroke>
);

/** Sheet → "Dock as Inspector": a window with its side panel. */
export const DockIcon = (props: Props) => (
    <Stroke {...props}>
        <rect x="3" y="4" width="18" height="16" rx="2" />
        <path d="M15 4v16" />
    </Stroke>
);

/** Docked "Hide Lens": a chevron toward the docked edge. */
export const HideDockedIcon = ({ side, ...props }: Props & { side: 'left' | 'right' }) => (
    <Stroke {...props}>
        <path d={side === 'right' ? 'M9 6l6 6-6 6' : 'M15 6l-6 6 6 6'} />
    </Stroke>
);

/** Sheet "Hide Lens": minimise. */
export const HideSheetIcon = (props: Props) => (
    <Stroke {...props}>
        <path d="M6 12h12" />
    </Stroke>
);

export const SendIcon = (props: Props) => (
    <Stroke {...props} width={2}>
        <path d="M12 19V5M6 11l6-6 6 6" />
    </Stroke>
);

/** Theme: following the system (half-filled circle). */
export const ThemeSystemIcon = (props: Props) => (
    <Stroke {...props}>
        <circle cx="12" cy="12" r="8" />
        <path d="M12 4a8 8 0 0 1 0 16z" fill="currentColor" />
    </Stroke>
);

export const ThemeLightIcon = (props: Props) => (
    <Stroke {...props}>
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
    </Stroke>
);

export const ThemeDarkIcon = (props: Props) => (
    <Stroke {...props}>
        <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z" />
    </Stroke>
);

/** The sheet's drag grip (six dots). */
export const GripIcon = ({ size = 14 }: Props) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
        <circle cx="9" cy="6" r="1.6" />
        <circle cx="15" cy="6" r="1.6" />
        <circle cx="9" cy="12" r="1.6" />
        <circle cx="15" cy="12" r="1.6" />
        <circle cx="9" cy="18" r="1.6" />
        <circle cx="15" cy="18" r="1.6" />
    </svg>
);
