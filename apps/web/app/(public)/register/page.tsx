import RegisterForm from "./RegisterForm";
import { AnonymousOnly } from "@/components/anonymous-only";

export const metadata = { title: "Create account" };

export default function RegisterPage() {
    return (
        <AnonymousOnly>
            <RegisterForm />
        </AnonymousOnly>
    );
}
