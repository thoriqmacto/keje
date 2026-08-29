<?php

namespace Tests\Unit\Media;

use App\Exceptions\Media\TextDoesNotFitException;
use App\Services\Media\FontMetrics;
use App\Services\Media\TextLayoutService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Layout rules for the Kajian Tematik template.
 *
 * These use real font measurement (GD + FreeType) rather than a stub — the
 * whole point of the service is that measurement is correct, and FFmpeg is not
 * needed to check it.
 */
class TextLayoutServiceTest extends TestCase
{
    private TextLayoutService $layout;

    /** @var array<string, mixed> */
    private array $template;

    protected function setUp(): void
    {
        parent::setUp();

        $fontFile = config('media.fonts.sans_bold');

        if (! is_file($fontFile)) {
            $this->markTestSkipped("Render font not installed: {$fontFile}");
        }

        $this->layout = new TextLayoutService(app(FontMetrics::class));
        $this->template = app(\App\Services\Media\TemplateRegistry::class)->get('kajian-tematik');
    }

    /** @param array<string, mixed> $overrides */
    private function resolve(array $overrides = []): array
    {
        return $this->layout->resolve($this->template, [
            'topic' => 'Riyadhush Shalihin',
            'topic_sequence' => 11,
            'speaker_name' => 'Syafiq Riza Basalamah',
            'primary_title' => 'Keutamaan Lapar, Hidup',
            'subtitle' => 'Sederhana dan Merasa Cukup serta Mengekang Hawa Nafsu',
            'part_number' => 3,
            ...$overrides,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function elementsFor(array $layout, string $element): array
    {
        return array_values(array_filter(
            $layout['elements'],
            fn (array $e): bool => $e['element'] === $element,
        ));
    }

    private function find(array $layout, string $key): ?array
    {
        foreach ($layout['elements'] as $element) {
            if ($element['key'] === $key) {
                return $element;
            }
        }

        return null;
    }

    // ── #6 Primary title ────────────────────────────────────────────────────

    #[Test]
    public function a_short_primary_title_renders_at_the_preferred_size(): void
    {
        $title = $this->find($this->resolve(['primary_title' => 'Sabar']), 'primary_title');

        $this->assertSame(
            $this->template['elements']['primary_title']['font_size'],
            $title['font_size'],
        );
    }

    #[Test]
    public function a_long_primary_title_reduces_the_font_size(): void
    {
        $preferred = $this->template['elements']['primary_title']['font_size'];

        $title = $this->find(
            $this->resolve(['primary_title' => 'Keutamaan Menahan Lapar dan Hidup Sederhana']),
            'primary_title',
        );

        $this->assertLessThan($preferred, $title['font_size']);
        $this->assertGreaterThanOrEqual(
            $this->template['elements']['primary_title']['min_font_size'],
            $title['font_size'],
        );
    }

    #[Test]
    public function the_primary_title_never_wraps_to_a_second_line(): void
    {
        $layout = $this->resolve(['primary_title' => 'Keutamaan Menahan Lapar dan Hidup Sederhana']);

        $this->assertCount(1, $this->elementsFor($layout, 'primary_title'));
    }

    #[Test]
    public function the_primary_title_always_fits_its_box(): void
    {
        $title = $this->find(
            $this->resolve(['primary_title' => 'Keutamaan Menahan Lapar dan Hidup Sederhana']),
            'primary_title',
        );

        $this->assertLessThanOrEqual($title['width'], $title['text_width']);
    }

    #[Test]
    public function an_impossible_primary_title_is_rejected_rather_than_cropped(): void
    {
        $this->expectException(TextDoesNotFitException::class);
        $this->expectExceptionMessage('Primary title is too long');

        $this->resolve(['primary_title' => str_repeat('Keutamaan Lapar Hidup Sederhana ', 8)]);
    }

    // ── #7 Subtitle ─────────────────────────────────────────────────────────

    #[Test]
    public function a_short_subtitle_stays_on_one_line(): void
    {
        $lines = $this->elementsFor($this->resolve(['subtitle' => 'Merasa Cukup']), 'subtitle');

        $this->assertCount(1, $lines);
    }

    #[Test]
    public function a_long_subtitle_wraps_to_exactly_two_lines(): void
    {
        $lines = $this->elementsFor($this->resolve(), 'subtitle');

        $this->assertCount(2, $lines);
    }

    #[Test]
    public function the_subtitle_never_produces_a_third_line(): void
    {
        $lines = $this->elementsFor(
            $this->resolve(['subtitle' => 'Sederhana dan Merasa Cukup serta Mengekang Hawa Nafsu Dalam Kehidupan']),
            'subtitle',
        );

        $this->assertLessThanOrEqual(2, count($lines));
    }

    #[Test]
    public function the_subtitle_wraps_on_word_boundaries(): void
    {
        $lines = $this->elementsFor($this->resolve(), 'subtitle');
        $rejoined = implode(' ', array_column($lines, 'text'));

        $this->assertSame(
            mb_strtoupper('Sederhana dan Merasa Cukup serta Mengekang Hawa Nafsu'),
            $rejoined,
        );
    }

    #[Test]
    public function the_subtitle_lines_are_balanced_rather_than_greedily_filled(): void
    {
        $lines = $this->elementsFor($this->resolve(), 'subtitle');

        [$first, $second] = $lines;
        $difference = abs($first['text_width'] - $second['text_width']);

        // A greedy fill would leave the second line far shorter than the
        // first; a balanced split keeps them close.
        $this->assertLessThan(
            $first['width'] * 0.25,
            $difference,
            'Subtitle lines should be close in width, not greedily packed.',
        );
    }

    #[Test]
    public function both_subtitle_lines_fit_their_box(): void
    {
        foreach ($this->elementsFor($this->resolve(), 'subtitle') as $line) {
            $this->assertLessThanOrEqual($line['width'], $line['text_width']);
        }
    }

    #[Test]
    public function an_impossible_subtitle_is_rejected(): void
    {
        $this->expectException(TextDoesNotFitException::class);
        $this->expectExceptionMessage('does not fit');

        $this->resolve(['subtitle' => str_repeat('Mengekang Hawa Nafsu Sederhana ', 12)]);
    }

    // ── #8 Part ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_part_number_is_decorated_for_display(): void
    {
        $this->assertSame('~ PART-3 ~', $this->find($this->resolve(), 'part')['text']);
    }

    #[Test]
    public function a_null_part_number_renders_no_part_line(): void
    {
        $this->assertNull($this->find($this->resolve(['part_number' => null]), 'part'));
    }

    // ── #2 Topic sequence ───────────────────────────────────────────────────

    #[Test]
    public function the_topic_sequence_is_decorated_for_display(): void
    {
        $this->assertSame('TEMA #11', $this->find($this->resolve(), 'topic_sequence')['text']);
    }

    // ── #3 / #4 Speaker ─────────────────────────────────────────────────────

    #[Test]
    public function the_speaker_label_is_a_template_constant_and_is_muted(): void
    {
        $label = $this->find($this->resolve(), 'speaker_label');
        $name = $this->find($this->resolve(), 'speaker_name');

        $this->assertSame('USTADZ', $label['text']);
        $this->assertSame('#B5B5B5', $label['color']);
        // The label must read as subordinate to the name.
        $this->assertNotSame($name['color'], $label['color']);
        $this->assertLessThan($name['font_size'], $label['font_size']);
    }

    #[Test]
    public function the_speaker_name_is_bright_and_uppercased_for_render_only(): void
    {
        $name = $this->find($this->resolve(), 'speaker_name');

        $this->assertSame('SYAFIQ RIZA BASALAMAH', $name['text']);
        $this->assertSame('#FFFFFF', $name['color']);
    }

    #[Test]
    public function the_speaker_label_and_name_share_a_baseline(): void
    {
        $layout = $this->resolve();

        $this->assertSame(
            $this->find($layout, 'speaker_label')['baseline'],
            $this->find($layout, 'speaker_name')['baseline'],
        );
    }

    // ── #5 Branding ─────────────────────────────────────────────────────────

    #[Test]
    public function branding_is_present_without_any_user_upload(): void
    {
        $branding = $this->find($this->resolve(), 'branding');

        $this->assertNotNull($branding);
        $this->assertSame('image', $branding['type']);
        $this->assertFileExists(
            app(\App\Services\Media\TemplateRegistry::class)
                ->assetPath('kajian-tematik', $branding['asset']),
        );
    }

    // ── Composition ─────────────────────────────────────────────────────────

    #[Test]
    public function the_waveform_zone_never_overlaps_the_part_or_subtitle(): void
    {
        $layout = $this->resolve();
        $wave = $this->find($layout, 'waveform');

        foreach (['part', 'subtitle_line_1', 'subtitle_line_2'] as $key) {
            $element = $this->find($layout, $key);

            if ($element === null) {
                continue;
            }

            $bottom = $element['y'] + $element['font_size'];
            $this->assertLessThan(
                $wave['y'],
                $bottom,
                "Element {$key} must sit above the reserved waveform zone.",
            );
        }
    }

    #[Test]
    public function every_element_stays_inside_the_safe_margin(): void
    {
        $layout = $this->resolve();
        $margin = $layout['safe_margin'];
        $canvas = $layout['canvas'];

        foreach ($layout['elements'] as $element) {
            $this->assertGreaterThanOrEqual($margin, $element['x'], "{$element['key']} left margin");
            $this->assertGreaterThanOrEqual($margin - 10, $element['y'], "{$element['key']} top margin");
            $this->assertLessThanOrEqual(
                $canvas['width'] - $margin,
                $element['x'] + ($element['text_width'] ?? $element['width']),
                "{$element['key']} right margin",
            );
        }
    }

    #[Test]
    public function the_visual_hierarchy_orders_title_over_subtitle_over_speaker(): void
    {
        $layout = $this->resolve();

        $title = $this->find($layout, 'primary_title')['font_size'];
        $subtitle = $this->find($layout, 'subtitle_line_1')['font_size'];
        $speaker = $this->find($layout, 'speaker_name')['font_size'];
        $topic = $this->find($layout, 'topic')['font_size'];
        $sequence = $this->find($layout, 'topic_sequence')['font_size'];

        $this->assertGreaterThan($subtitle, $title);
        $this->assertGreaterThan($speaker, $subtitle);
        $this->assertGreaterThanOrEqual($topic, $speaker);
        $this->assertGreaterThan($sequence, $topic);
    }

    #[Test]
    public function the_layout_carries_the_shared_preview_contract(): void
    {
        $layout = $this->resolve();

        // The browser preview reproduces the composition from exactly these
        // values, so they must always be present.
        $this->assertSame(1280, $layout['canvas']['width']);
        $this->assertSame(720, $layout['canvas']['height']);
        $this->assertArrayHasKey('overlay', $layout['background']);
        $this->assertNotEmpty($layout['background']['overlay']['stops']);
    }
}
