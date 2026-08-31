<?php

namespace Tests\Unit;

use App\Services\Media\AudioEditService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Cut 18 → 23" removes those five seconds. It is not a trim down to that
 * range — the opposite operation, and the easier one to build by accident —
 * so the arithmetic is asserted directly rather than through a render.
 */
class AudioEditServiceTest extends TestCase
{
    private AudioEditService $edits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->edits = new AudioEditService;
    }

    private function cut(float $start, float $end): array
    {
        return ['type' => 'cut', 'start' => $start, 'end' => $end];
    }

    // ── The worked example ──────────────────────────────────────────────────

    #[Test]
    public function removing_18_to_23_leaves_the_rest(): void
    {
        $cuts = $this->edits->normalize([$this->cut(18, 23)], 60.0);

        $this->assertSame(
            [['start' => 0.0, 'end' => 18.0], ['start' => 23.0, 'end' => 60.0]],
            $this->edits->keptSegments($cuts, 60.0),
        );

        $this->assertSame(55.0, $this->edits->keptDuration($cuts, 60.0));
        $this->assertSame(5.0, $this->edits->removedDuration($cuts, 60.0));
    }

    #[Test]
    public function no_cuts_leaves_the_duration_untouched(): void
    {
        $this->assertSame([['start' => 0.0, 'end' => 60.0]], $this->edits->keptSegments([], 60.0));
        $this->assertSame(60.0, $this->edits->keptDuration([], 60.0));
    }

    #[Test]
    public function several_cuts_sum_correctly(): void
    {
        $cuts = $this->edits->normalize([
            $this->cut(18, 23),
            $this->cut(44.5, 46),
            $this->cut(74, 79),
        ], 120.0);

        // 5 + 1.5 + 5 removed from two minutes.
        $this->assertSame(108.5, $this->edits->keptDuration($cuts, 120.0));
        $this->assertCount(4, $this->edits->keptSegments($cuts, 120.0));
    }

    #[Test]
    public function a_cut_from_the_very_start_produces_no_empty_leading_segment(): void
    {
        $cuts = $this->edits->normalize([$this->cut(0, 10)], 60.0);

        $this->assertSame([['start' => 10.0, 'end' => 60.0]], $this->edits->keptSegments($cuts, 60.0));
    }

    #[Test]
    public function a_cut_to_the_very_end_produces_no_empty_trailing_segment(): void
    {
        $cuts = $this->edits->normalize([$this->cut(50, 60)], 60.0);

        $this->assertSame([['start' => 0.0, 'end' => 50.0]], $this->edits->keptSegments($cuts, 60.0));
    }

    // ── Normalisation ───────────────────────────────────────────────────────

    #[Test]
    public function cuts_are_sorted_regardless_of_entry_order(): void
    {
        $cuts = $this->edits->normalize([$this->cut(44, 46), $this->cut(18, 23)], 60.0);

        $this->assertSame(18.0, $cuts[0]['start']);
        $this->assertSame(44.0, $cuts[1]['start']);
    }

    #[Test]
    public function touching_cuts_are_merged_into_one(): void
    {
        $cuts = $this->edits->normalize([$this->cut(18, 23), $this->cut(23, 25)], 60.0);

        // One span, not two: an island of a few milliseconds buys nothing.
        $this->assertCount(1, $cuts);
        $this->assertSame(['type' => 'cut', 'start' => 18.0, 'end' => 25.0], $cuts[0]);
    }

    // ── Refusals ────────────────────────────────────────────────────────────

    #[Test]
    public function overlapping_cuts_are_refused_rather_than_absorbed(): void
    {
        // Silently widening would remove audio the person never chose to.
        $this->expectException(ValidationException::class);
        $this->edits->normalize([$this->cut(18, 25), $this->cut(20, 30)], 60.0);
    }

    #[Test]
    public function an_end_before_the_start_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->edits->normalize([$this->cut(23, 18)], 60.0);
    }

    #[Test]
    public function a_zero_length_cut_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->edits->normalize([$this->cut(18, 18)], 60.0);
    }

    #[Test]
    public function a_negative_start_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->edits->normalize([$this->cut(-5, 10)], 60.0);
    }

    #[Test]
    public function a_cut_past_the_end_of_the_recording_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->edits->normalize([$this->cut(50, 90)], 60.0);
    }

    #[Test]
    public function removing_the_whole_recording_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->edits->normalize([$this->cut(0, 60)], 60.0);
    }

    // ── Filter graph ────────────────────────────────────────────────────────

    #[Test]
    public function the_filter_trims_restamps_and_concatenates(): void
    {
        $segments = $this->edits->keptSegments(
            $this->edits->normalize([$this->cut(18, 23)], 60.0),
            60.0,
        );

        $filter = $this->edits->buildCutFilter($segments, '[1:a]', '[acut]');

        $this->assertStringContainsString('atrim=start=0.000:end=18.000', $filter);
        $this->assertStringContainsString('atrim=start=23.000:end=60.000', $filter);

        // Without asetpts, concat sees overlapping timestamps and produces
        // silence or garbage rather than a join.
        $this->assertStringContainsString('asetpts=PTS-STARTPTS', $filter);
        $this->assertStringContainsString('concat=n=2:v=0:a=1[acut]', $filter);
    }

    #[Test]
    public function a_single_segment_needs_no_concat_at_all(): void
    {
        // Nothing cut: keep the existing one-input chain rather than paying
        // for a pointless one-segment concat.
        $this->assertSame('', $this->edits->buildCutFilter([['start' => 0.0, 'end' => 60.0]], '[1:a]', '[acut]'));
    }

    #[Test]
    public function timestamps_are_fixed_point_never_scientific(): void
    {
        // A float cast can render a small offset as "1.0E-5", which FFmpeg
        // rejects as a parse error.
        $filter = $this->edits->buildCutFilter(
            [['start' => 0.00001, 'end' => 5.0], ['start' => 10.0, 'end' => 20.0]],
            '[1:a]',
            '[acut]',
        );

        $this->assertStringNotContainsStringIgnoringCase('e-', $filter);
        $this->assertStringContainsString('start=0.000', $filter);
    }

    #[Test]
    public function nothing_from_the_request_reaches_the_filter_string(): void
    {
        // The request carries numbers; a `type` of anything at all cannot
        // introduce syntax, because only start and end are ever read.
        $segments = $this->edits->keptSegments(
            [['type' => "cut';drop", 'start' => 18.0, 'end' => 23.0]],
            60.0,
        );

        $filter = $this->edits->buildCutFilter($segments, '[1:a]', '[acut]');

        $this->assertStringNotContainsString('drop', $filter);
        $this->assertMatchesRegularExpression('/^[\[\]a-z0-9=:,;.\-]+$/i', $filter);
    }
}
