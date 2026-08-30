import LoginForm from "./LoginForm";
import { AnonymousOnly } from "@/components/anonymous-only";

export const metadata = { title: "Sign in" };

export default function LoginPage() {
    return (
        <AnonymousOnly>
            <LoginForm />
        </AnonymousOnly>
    );
}
