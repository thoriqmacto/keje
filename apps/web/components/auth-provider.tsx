"use client";

import {
    createContext,
    ReactNode,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import {
    authAdapter,
    clearAuth,
    readAuth,
    writeAuth,
    type LoginPayload,
    type RegisterPayload,
    type StoredAuthUser,
} from "@/lib/auth";
import { AUTH_EXPIRED_EVENT } from "@/lib/api";
import { authenticatedDestination, DEFAULT_AUTHENTICATED_PATH } from "@/lib/auth/redirects";

type AuthStatus = "loading" | "authenticated" | "anonymous";

type AuthContextValue = {
    status: AuthStatus;
    user: StoredAuthUser | null;
    login: (payload: LoginPayload) => Promise<void>;
    register: (payload: RegisterPayload) => Promise<void>;
    logout: () => Promise<void>;
    refresh: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

/**
 * Where to go after a successful sign-in: the `next` the middleware attached
 * when it turned away an anonymous request, or the dashboard. Validated by
 * authenticatedDestination, so an off-site `next` can never be honoured.
 */
function postAuthDestination(): string {
    if (typeof window === "undefined") return DEFAULT_AUTHENTICATED_PATH;
    return authenticatedDestination(new URLSearchParams(window.location.search).get("next"));
}

export function useAuth() {
    const ctx = useContext(AuthContext);
    if (!ctx) throw new Error("useAuth must be used within <AuthProvider>");
    return ctx;
}

export function AuthProvider({ children }: { children: ReactNode }) {
    const router = useRouter();
    const [user, setUser] = useState<StoredAuthUser | null>(null);
    const [status, setStatus] = useState<AuthStatus>("loading");
    const bootstrapped = useRef(false);

    const refresh = useCallback(async () => {
        const stored = readAuth();
        const usesLocalStorage = authAdapter.mode !== "cookie";
        if (usesLocalStorage && !stored) {
            // No token here, so the auth_hint cookie is stale — a leftover from
            // a cleared localStorage, another tab, or an old deployment. Drop
            // it, or middleware keeps bouncing / to /dashboard forever.
            // Guarded by usesLocalStorage so cookie mode, whose session lives
            // in the browser's own cookie jar, is left alone.
            clearAuth();
            setUser(null);
            setStatus("anonymous");
            return;
        }
        try {
            const me = await authAdapter.me();
            setUser(me);
            setStatus("authenticated");
            if (stored) writeAuth({ ...stored, user: me });
        } catch {
            clearAuth();
            setUser(null);
            setStatus("anonymous");
        }
    }, []);

    useEffect(() => {
        if (bootstrapped.current) return;
        bootstrapped.current = true;
        refresh();
    }, [refresh]);

    useEffect(() => {
        function onExpired() {
            clearAuth();
            setUser(null);
            setStatus("anonymous");
            toast.error("Your session has expired. Please sign in again.");
            router.push("/login");
        }
        window.addEventListener(AUTH_EXPIRED_EVENT, onExpired);
        return () => window.removeEventListener(AUTH_EXPIRED_EVENT, onExpired);
    }, [router]);

    const login = useCallback(
        async (payload: LoginPayload) => {
            const auth = await authAdapter.login(payload);
            if (authAdapter.mode !== "cookie") writeAuth(auth);
            setUser(auth.user);
            setStatus("authenticated");
            router.push(postAuthDestination());
        },
        [router],
    );

    const register = useCallback(
        async (payload: RegisterPayload) => {
            const auth = await authAdapter.register(payload);
            if (authAdapter.mode !== "cookie") writeAuth(auth);
            setUser(auth.user);
            setStatus("authenticated");
            router.push(postAuthDestination());
        },
        [router],
    );

    const logout = useCallback(async () => {
        try {
            await authAdapter.logout();
        } catch {
            /* ignore network errors on logout; clear locally anyway */
        }
        clearAuth();
        setUser(null);
        setStatus("anonymous");
        router.push("/login");
    }, [router]);

    const value = useMemo<AuthContextValue>(
        () => ({ status, user, login, register, logout, refresh }),
        [status, user, login, register, logout, refresh],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
