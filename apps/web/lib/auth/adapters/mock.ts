import type { AuthAdapter } from "../adapter";
import type { StoredAuth } from "../storage";

const MOCK_USER: StoredAuth["user"] = {
    id: 1,
    name: "Mock User",
    email: "mock@example.com",
};

/**
 * Frontend-only development adapter. Use by setting
 * NEXT_PUBLIC_AUTH_MODE=mock. No API calls are made. Token is stored in
 * localStorage so reloads stay "authenticated".
 */
export const mockAdapter: AuthAdapter = {
    mode: "mock",

    async login() {
        return { token: "mock-token", user: MOCK_USER, expiresAt: null };
    },

    async register({ name, email }) {
        return {
            token: "mock-token",
            user: { ...MOCK_USER, name: name || MOCK_USER.name, email: email || MOCK_USER.email },
            expiresAt: null,
        };
    },

    async me() {
        return MOCK_USER;
    },

    async logout() {
        /* no-op */
    },
};
