"use client";

import { useMemo } from "react";
import type {
    LayoutTextElement,
    LayoutWaveformElement,
    TemplateLayout,
} from "@/lib/types/studio";

/**
 * Browser approximation of the rendered frame.
 *
 * Every coordinate comes from the API's resolved layout — the same structure
 * FFmpeg draws from — so the preview cannot drift from the render. The only
 * transform applied here is a uniform scale from the 1280×720 canvas to
 * whatever width the container has, done with a CSS `scale` on a fixed-size
 * canvas so all positions, sizes and gaps scale together.
 *
 * It is an approximation, not a pixel-exact copy: the browser has its own font
 * and its own text shaping. Close enough to approve a composition before
 * spending minutes on a render.
 */
export function KajianTematikPreview({
    layout,
    backgroundUrl,
    className,
}: {
    layout: TemplateLayout;
    backgroundUrl?: string | null;
    className?: string;
}) {
    const { width, height } = layout.canvas;

    // Rebuild the readability gradient from the same stops the renderer bakes
    // into overlay.png.
    const overlayGradient = useMemo(() => {
        if (!layout.background.overlay.enabled) return undefined;

        const stops = layout.background.overlay.stops
            .map(([position, alpha]) => `rgba(0,0,0,${alpha}) ${position * 100}%`)
            .join(", ");

        return `linear-gradient(to bottom, ${stops})`;
    }, [layout.background.overlay]);

    const texts = layout.elements.filter(
        (element): element is LayoutTextElement => element.type === "text",
    );
    const waveform = layout.elements.find(
        (element): element is LayoutWaveformElement => element.type === "waveform",
    );
    const branding = layout.elements.find((element) => element.key === "branding");

    return (
        // The outer box owns the 16:9 aspect; the inner canvas is always
        // exactly 1280×720 and is scaled to fit, so layout maths never changes.
        <div
            className={`relative w-full overflow-hidden rounded-lg border bg-neutral-950 ${className ?? ""}`}
            style={{
                aspectRatio: `${width} / ${height}`,
                // Establishes the query container the inner canvas scales
                // against — `cqw` below resolves to this element's width.
                containerType: "inline-size",
            }}
        >
            <div
                className="absolute left-0 top-0 origin-top-left"
                style={{
                    width,
                    height,
                    transform: `scale(calc(100cqw / ${width}))`,
                }}
            >
                {/* Background: cover + centre crop, matching the renderer. */}
                {backgroundUrl ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                        src={backgroundUrl}
                        alt=""
                        className="absolute inset-0 h-full w-full object-cover"
                    />
                ) : (
                    <div className="absolute inset-0 bg-gradient-to-br from-slate-800 to-slate-950" />
                )}

                {overlayGradient && (
                    <div className="absolute inset-0" style={{ background: overlayGradient }} />
                )}

                {/* #5 Branding — a template asset, so the preview draws its own
                    equivalent rather than fetching the PNG. */}
                {branding && (
                    <div
                        className="absolute flex flex-col items-start justify-center leading-none"
                        style={{
                            left: branding.x,
                            top: branding.y,
                            width: "width" in branding ? branding.width : undefined,
                            height: "height" in branding ? branding.height : undefined,
                        }}
                    >
                        <span className="font-bold tracking-tight text-white" style={{ fontSize: 27 }}>
                            KAJIAN
                        </span>
                        <span
                            className="flex items-center gap-1.5 font-bold tracking-tight text-white"
                            style={{ fontSize: 27 }}
                        >
                            <span
                                className="inline-block rounded-full"
                                style={{ width: 9, height: 9, background: "#E8B44A" }}
                            />
                            TEMATIK
                        </span>
                    </div>
                )}

                {/* #1 #2 #3 #4 #6 #7 #8 — positioned from the resolved layout.
                    `top` uses the glyph-box top the layout service computed. */}
                {texts.map((element) => (
                    <div
                        key={element.key}
                        className="absolute font-bold"
                        style={{
                            left: element.x,
                            top: element.y,
                            width: element.width,
                            fontSize: element.font_size,
                            color: element.color,
                            lineHeight: 1,
                            textAlign: element.align,
                            whiteSpace: "nowrap",
                        }}
                    >
                        {element.text}
                    </div>
                ))}

                {/* Waveform placeholder — the real wave is generated by FFmpeg
                    from the audio, so the preview marks out its reserved zone. */}
                {waveform && (
                    <div
                        className="absolute flex items-center justify-center"
                        style={{
                            left: waveform.x,
                            top: waveform.y,
                            width: waveform.width,
                            height: waveform.height,
                        }}
                    >
                        <WaveformPlaceholder
                            width={waveform.width}
                            height={waveform.height}
                            color={waveform.color}
                        />
                    </div>
                )}
            </div>
        </div>
    );
}

/** A static stand-in for the showwaves output, drawn at the reserved size. */
function WaveformPlaceholder({
    width,
    height,
    color,
}: {
    width: number;
    height: number;
    color: string;
}) {
    // Deterministic pseudo-random bars: a fixed shape is calmer than something
    // that reshuffles on every render.
    const bars = useMemo(() => {
        const values: number[] = [];
        for (let i = 0; i < 120; i++) {
            const wave =
                Math.sin(i * 0.35) * 0.4 + Math.sin(i * 0.11) * 0.35 + Math.sin(i * 0.73) * 0.25;
            values.push(Math.abs(wave));
        }
        return values;
    }, []);

    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            className="opacity-80"
            aria-hidden
        >
            {bars.map((value, index) => {
                const barWidth = width / bars.length;
                const barHeight = Math.max(2, value * height * 0.9);
                return (
                    <rect
                        key={index}
                        x={index * barWidth}
                        y={(height - barHeight) / 2}
                        width={Math.max(1, barWidth * 0.55)}
                        height={barHeight}
                        fill={color}
                    />
                );
            })}
        </svg>
    );
}
