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

/** Inspect card: clear the selection (screen 06). */
export const CloseIcon = ({ size = 14 }: Props) => (
    <Stroke size={size} width={2}>
        <path d="M6 6l12 12M18 6L6 18" />
    </Stroke>
);

/* Structure tree (screen 04). */
export const ChevronDownIcon = ({ size = 12 }: Props) => (
    <Stroke size={size} width={2}>
        <path d="M6 9l6 6 6-6" />
    </Stroke>
);

export const ChevronRightIcon = ({ size = 12 }: Props) => (
    <Stroke size={size} width={2}>
        <path d="M9 6l6 6-6 6" />
    </Stroke>
);

export const PageIcon = ({ size = 14 }: Props) => (
    <Stroke size={size}>
        <rect x="5" y="3" width="14" height="18" rx="2" />
        <path d="M9 8h6M9 12h6" />
    </Stroke>
);

export const PartIcon = ({ size = 14 }: Props) => (
    <Stroke size={size}>
        <rect x="4" y="4" width="16" height="16" rx="2" />
        <path d="M4 9h16" />
    </Stroke>
);

export const BlockIcon = ({ size = 14 }: Props) => (
    <Stroke size={size}>
        <rect x="4" y="4" width="7" height="7" rx="1" />
        <rect x="13" y="4" width="7" height="7" rx="1" />
        <rect x="4" y="13" width="16" height="7" rx="1" />
    </Stroke>
);

export const FieldIcon = ({ size = 14 }: Props) => (
    <Stroke size={size}>
        <path d="M5 7h14M5 12h10M5 17h7" />
    </Stroke>
);

export const TextIcon = ({ size = 14 }: Props) => (
    <Stroke size={size}>
        <path d="M6 6h12M12 6v12" />
    </Stroke>
);

/* Chat (screens 01, 07). */
export const CheckIcon = ({ size = 14 }: Props) => (
    <Stroke size={size} width={2}>
        <path d="M5 12.5l4.5 4.5L19 7.5" />
    </Stroke>
);

/** A step that failed or was refused: ink, never red. */
export const FailedIcon = ({ size = 14 }: Props) => (
    <Stroke size={size} width={2}>
        <path d="M7 7l10 10M17 7L7 17" />
    </Stroke>
);

/** The working spinner: a three-quarter ring, turned by CSS (still when motion is reduced). */
export const SpinnerIcon = ({ size = 14 }: Props) => (
    <svg
        class="spinner"
        width={size}
        height={size}
        viewBox="0 0 24 24"
        fill="none"
        aria-hidden="true"
        focusable="false"
    >
        <path d="M12 3.5a8.5 8.5 0 1 1-8.5 8.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
    </svg>
);

/** "Claude stopped before finishing" (screen 07 error). */
export const AlertIcon = ({ size = 16 }: Props) => (
    <Stroke size={size}>
        <circle cx="12" cy="12" r="8.5" />
        <path d="M12 7.5v5.5M12 16.4v.1" />
    </Stroke>
);

/** "Lens isn't connected" (screen 07): a broken link. */
export const UnlinkIcon = ({ size = 22 }: Props) => (
    <Stroke size={size}>
        <path d="M9.5 8H8a4 4 0 0 0 0 8h1.5M14.5 8H16a4 4 0 0 1 0 8h-1.5" />
        <path d="M10 12h.01M14 12h.01" />
    </Stroke>
);

/** Stop (the Send button while Claude works). */
export const StopIcon = ({ size = 10 }: Props) => (
    <svg width={size} height={size} viewBox="0 0 10 10" fill="currentColor" aria-hidden="true" focusable="false">
        <rect width="10" height="10" rx="1.5" />
    </svg>
);

/** The rich-text toolbar (screen 10). */
export const LinkIcon = (props: Props) => (
    <Stroke {...props} width={2}>
        <path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1" />
    </Stroke>
);

export const BulletListIcon = (props: Props) => (
    <Stroke {...props} width={2}>
        <path d="M9 6h11M9 12h11M9 18h11" />
        <circle cx="4.5" cy="6" r="1" />
        <circle cx="4.5" cy="12" r="1" />
        <circle cx="4.5" cy="18" r="1" />
    </Stroke>
);

export const NumberedListIcon = (props: Props) => (
    <Stroke {...props} width={2}>
        <path d="M10 6h10M10 12h10M10 18h10M4 5h1v4M4 15h2l-2 3h2" />
    </Stroke>
);

/** New chat (component 06): a page with a pen. */
export const NewChatIcon = (props: Props) => (
    <Stroke {...props}>
        <path d="M12 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-6" />
        <path d="M17.5 3.5a2.1 2.1 0 0 1 3 3L13 14l-4 1 1-4z" />
    </Stroke>
);
