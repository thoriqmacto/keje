import Link from "next/link";
import { APP_NAME } from "@/lib/env";

export default function PublicLayout({ children }: { children: React.ReactNode }) {
    return (
        <div className="flex min-h-screen flex-col">
            <header className="border-b">
                <div className="mx-auto flex h-14 w-full max-w-5xl items-center justify-between px-4">
                    <Link href="/" className="font-semibold tracking-tight">
                        {APP_NAME}
                    </Link>
                    <nav className="flex items-center gap-4 text-sm">
                        <Link href="/login" className="hover:underline underline-offset-4">
                            Sign in
                        </Link>
                        <Link href="/register" className="hover:underline underline-offset-4">
                            Create account
                        </Link>
                    </nav>
                </div>
            </header>
            <main className="flex-1">{children}</main>
            <footer className="border-t">
                <div className="mx-auto w-full max-w-5xl px-4 py-6 text-sm text-muted-foreground">
                    {APP_NAME} starter &middot; Laravel API + Next.js
                </div>
            </footer>
        </div>
    );
}
