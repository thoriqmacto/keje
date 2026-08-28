import axios, { AxiosError, AxiosInstance } from "axios";
import { API_BASE_URL, AUTH_MODE } from "./env";
import { getToken } from "./auth/storage";

export const AUTH_EXPIRED_EVENT = "auth:expired";

function createApi(): AxiosInstance {
    const instance = axios.create({
        baseURL: API_BASE_URL,
        withCredentials: AUTH_MODE === "cookie",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
        },
    });

    instance.interceptors.request.use((config) => {
        if (AUTH_MODE === "bearer") {
            const token = getToken();
            if (token) {
                config.headers = config.headers ?? {};
                (config.headers as Record<string, string>).Authorization = `Bearer ${token}`;
            }
        }
        return config;
    });

    instance.interceptors.response.use(
        (response) => response,
        (error: AxiosError) => {
            if (error.response?.status === 401 && typeof window !== "undefined") {
                window.dispatchEvent(new CustomEvent(AUTH_EXPIRED_EVENT));
            }
            return Promise.reject(error);
        },
    );

    return instance;
}

export const api = createApi();
