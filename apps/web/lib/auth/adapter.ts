import type { StoredAuth } from "./storage";

export type LoginPayload = { email: string; password: string };

export type RegisterPayload = {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
};

export type AuthMode = "bearer" | "cookie" | "mock";

export type AuthAdapter = {
    mode: AuthMode;
    login: (payload: LoginPayload) => Promise<StoredAuth>;
    register: (payload: RegisterPayload) => Promise<StoredAuth>;
    me: () => Promise<StoredAuth["user"]>;
    logout: () => Promise<void>;
};
