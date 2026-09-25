import { useEffect, useState } from 'react';

/**
 * Fetch after the input settles (search boxes). While a newer request is
 * pending, the last results stay on screen and `loading` is true.
 */
export function useDebouncedFetch<T>(key: string, fetcher: () => Promise<T>, enabled = true, delay = 250) {
    const [state, setState] = useState<{ key: string | null; data: T | undefined; error: boolean }>({
        key: null,
        data: undefined,
        error: false,
    });

    useEffect(() => {
        if (!enabled) return undefined;
        let live = true;
        const timer = window.setTimeout(() => {
            fetcher()
                .then((data) => live && setState({ key, data, error: false }))
                .catch(() => live && setState({ key, data: undefined, error: true }));
        }, delay);
        return () => {
            live = false;
            window.clearTimeout(timer);
        };
        // The key stands for everything the fetcher reads.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key, enabled, delay]);

    return { data: state.data, error: state.error && state.key === key, loading: state.key !== key };
}
