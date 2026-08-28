import { api } from "@/lib/api";
import type { AuthAdapter } from "../adapter";
import type { StoredAuth } from "../storage";

type AuthResponse = {
    user: StoredAuth["user"];
    token: string;
    expires_at: string | null;
};

export const bearerAdapter: AuthAdapter = {
    mode: "bearer",

    async login({ email, password }) {
        const { data } = await api.post<AuthResponse>("/login", { email, password });
        return { token: data.token, user: data.user, expiresAt: data.expires_at };
    },

    async register(payload) {
        const { data } = await api.post<AuthResponse>("/register", payload);
        return { token: data.token, user: data.user, expiresAt: data.expires_at };
    },

    async me() {
        const { data } = await api.get<{ user: StoredAuth["user"] }>("/me");
        return data.user;
    },

    async logout() {
        await api.post("/logout");
    },
};
