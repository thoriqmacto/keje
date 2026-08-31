import DashboardClient from "./DashboardClient";

// The dashboard reads authenticated data in the browser, so the page itself
// is a client component and cannot export metadata. This server wrapper
// carries the title, matching the pattern the other routes already use.
export const metadata = { title: "Dashboard" };

export default function DashboardPage() {
    return <DashboardClient />;
}
