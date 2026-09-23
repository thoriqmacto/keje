import type { GoogleHealth, GoogleHealthStatus } from "@/lib/types/studio";

/**
 * How a connection's health should be presented.
 *
 * Pure and dependency-free so the rules can be tested directly. They are
 * shared between the app-wide banner and the Integrations page on purpose:
 * two copies of "is this worth interrupting somebody about" is how a banner
 * ends up disagreeing with the page it links to.
 */

export type HealthSeverity = "ok" | "info" | "warning" | "critical";

type Tone = {
    severity: HealthSeverity;
    /** Two or three words for a pill. The full sentence is `message`. */
    label: string;
};

const TONES: Record<GoogleHealthStatus, Tone> = {
    healthy: { severity: "ok", label: "Working" },
    expiring_soon: { severity: "warning", label: "Expires soon" },

    // Not a fault. Nobody has set this up, or nobody has connected it, and
    // saying "error" about a feature somebody chose not to use is noise.
    not_configured: { severity: "info", label: "Not configured" },
    disconnected: { severity: "info", label: "Not connected" },

    renew_required: { severity: "critical", label: "Needs reconnect" },
    invalid_client: { severity: "critical", label: "Bad credentials" },
    scope_error: { severity: "critical", label: "Missing permission" },
    api_disabled: { severity: "critical", label: "API disabled" },
    unknown_error: { severity: "critical", label: "Not working" },

    /*
     * The one that looks like a failure and is not. The server could not get
     * to Google, so nothing was learned about the credentials either way —
     * and a warning saying so is very different from one blaming the token.
     */
    unreachable: { severity: "warning", label: "Could not check" },
};

export function healthTone(status: GoogleHealthStatus): Tone {
    return TONES[status] ?? TONES.unknown_error;
}

export function healthSeverity(status: GoogleHealthStatus): HealthSeverity {
    return healthTone(status).severity;
}

/**
 * Whether this belongs in the app-wide banner.
 *
 * Exactly the set the API alerts on — broken, plus the one real countdown.
 * `unreachable` is excluded even though it is a warning: a banner on every
 * page for a network blip is how somebody learns to stop reading the banner.
 * `disconnected` and `not_configured` are excluded because they describe a
 * choice, not a fault; the Integrations page still shows them.
 */
export function needsAttention(health: GoogleHealth): boolean {
    const severity = healthSeverity(health.status);

    return severity === "critical" || health.status === "expiring_soon";
}

/** The worse of two severities, for a banner covering both services. */
export function worstSeverity(items: GoogleHealth[]): HealthSeverity {
    const order: HealthSeverity[] = ["ok", "info", "warning", "critical"];

    return items.reduce<HealthSeverity>((worst, item) => {
        const current = healthSeverity(item.status);
        return order.indexOf(current) > order.indexOf(worst) ? current : worst;
    }, "ok");
}

/**
 * One line for a banner covering however many connections are unhappy.
 *
 * Naming the services is the whole point: "a Google connection needs
 * attention" makes somebody open a page to find out which, and the answer
 * fits in the sentence.
 */
export function attentionSummary(items: GoogleHealth[]): string | null {
    const unhappy = items.filter(needsAttention);

    if (unhappy.length === 0) return null;
    if (unhappy.length === 1) return `${unhappy[0].label}: ${unhappy[0].message}`;

    return `${unhappy.map((item) => item.label).join(" and ")} both need attention before the next upload.`;
}

/**
 * Whether the last check is old enough that it should not be trusted alone.
 *
 * The scheduled run is hourly, so a report from three hours ago means the
 * scheduler is not running — and a banner that says "working" on the strength
 * of a stale check is worse than no banner, because it is the reassurance
 * somebody acts on.
 */
export function isStale(
    checkedAt: string | null,
    now: Date = new Date(),
    staleAfterHours = 3,
): boolean {
    if (!checkedAt) return true;

    const at = new Date(checkedAt).getTime();
    if (Number.isNaN(at)) return true;

    return now.getTime() - at > staleAfterHours * 3_600_000;
}
