import NotesClient from "./NotesClient";

export const metadata = { title: "Notes" };

/**
 * Example resource — safe to delete.
 *
 * Demonstrates the full frontend CRUD pattern (SWR fetch, mutation + revalidate,
 * shadcn form). Copy as the template for real resources.
 */
export default function NotesPage() {
    return <NotesClient />;
}
