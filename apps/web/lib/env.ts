import type { AuthMode } from "./auth/adapter";

export const API_BASE_URL =
    process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000/api/v1";

export const AUTH_MODE: AuthMode =
    process.env.NEXT_PUBLIC_AUTH_MODE === "cookie"
        ? "cookie"
        : process.env.NEXT_PUBLIC_AUTH_MODE === "mock"
            ? "mock"
            : "bearer";

export const APP_NAME = process.env.NEXT_PUBLIC_APP_NAME ?? "Starter";
