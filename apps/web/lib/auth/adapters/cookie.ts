import axios from "axios";
import { api } from "@/lib/api";
import { API_BASE_URL } from "@/lib/env";
import type { AuthAdapter } from "../adapter";
import type { StoredAuth } from "../storage";

function deriveCsrfUrl(): string {
    try {
        const base = new URL(API_BASE_URL);
        return `${base.protocol}//${base.host}/sanctum/csrf-cookie`;
    } catch {
        return "/sanctum/csrf-cookie";
    }
}

async function primeCsrf() {
    await axios.get(deriveCsrfUrl(), { withCredentials: true });
}

export const cookieAdapter: AuthAdapter = {
    mode: "cookie",

    async login({ email, password }) {
        await primeCsrf();
        const { data } = await api.post<{ user: StoredAuth["user"] }>("/login", {
            email,
            password,
        });
        return { token: "", user: data.user, expiresAt: null };
    },

    async register(payload) {
        await primeCsrf();
        const { data } = await api.post<{ user: StoredAuth["user"] }>("/register", payload);
        return { token: "", user: data.user, expiresAt: null };
    },

    async me() {
        const { data } = await api.get<{ user: StoredAuth["user"] }>("/me");
        return data.user;
    },

    async logout() {
        await api.post("/logout");
    },
};
