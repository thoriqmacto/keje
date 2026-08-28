import { AUTH_MODE } from "@/lib/env";
import type { AuthAdapter } from "./adapter";
import { bearerAdapter } from "./adapters/bearer";
import { cookieAdapter } from "./adapters/cookie";
import { mockAdapter } from "./adapters/mock";

export const authAdapter: AuthAdapter =
    AUTH_MODE === "mock"
        ? mockAdapter
        : AUTH_MODE === "cookie"
            ? cookieAdapter
            : bearerAdapter;

export type { AuthAdapter, AuthMode, LoginPayload, RegisterPayload } from "./adapter";
export {
    clearAuth,
    getToken,
    readAuth,
    writeAuth,
    type StoredAuth,
    type StoredAuthUser,
} from "./storage";
