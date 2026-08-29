<?php

/*
|--------------------------------------------------------------------------
| Kajian Tematik — video template definition
|--------------------------------------------------------------------------
|
| The single source of truth for this template's geometry and typography.
| TextLayoutService resolves these declarations into absolute pixel boxes;
| the FFmpeg renderer draws them and the API ships the *same* resolved boxes
| to the browser preview. Nothing here may be overridden by user input.
|
| Coordinates are in canvas pixels. For text, `y` is the TOP of the line box
| and `x` + `width` describe the box the text is aligned within.
|
*/

return [

    'key' => 'kajian-tematik',
    'name' => 'Kajian Tematik',
    'description' => 'Lecture template: topic and sequence top-left, brand top-right, '
        .'centred speaker line, one-line title over a two-line subtitle, part marker, waveform.',

    'canvas' => [
        'width' => 1280,
        'height' => 720,
    ],

    // Nothing is drawn closer than this to any canvas edge.
    'safe_margin' => 48,

    /*
    | Background handling. "cover" scales the uploaded artwork to fill 1280×720
    | preserving aspect ratio, then centre-crops the overflow — chosen over
    | fit+pad because this template is cinematic and letterboxing would show.
    | The readability overlay is a render-time effect only; the uploaded file
    | is never modified. Stops are [position 0..1, black alpha 0..1] and are
    | shared verbatim with the browser preview.
    */
    'background' => [
        'fit' => 'cover',
        'overlay' => [
            'enabled' => true,
            'stops' => [
                [0.00, 0.45],
                [0.35, 0.30],
                [1.00, 0.75],
            ],
        ],
    ],

    'elements' => [

        // #1 — Topic ("Riyadhush Shalihin"). Top-left, above the sequence.
        'topic' => [
            'type' => 'text',
            'x' => 48,
            'y' => 46,
            'width' => 640,
            'align' => 'left',
            'font' => 'sans_bold',
            'font_size' => 30,
            'min_font_size' => 20,
            'color' => '#FFFFFF',
            'max_lines' => 1,
            'transform' => 'none',
        ],

        // #2 — Topic sequence. Stored as an integer; presentation lives here.
        'topic_sequence' => [
            'type' => 'text',
            'x' => 48,
            'y' => 88,
            'width' => 640,
            'align' => 'left',
            'font' => 'sans_bold',
            'font_size' => 24,
            'min_font_size' => 18,
            'color' => '#DCDCDC',
            'max_lines' => 1,
            'format' => 'TEMA #%d',
        ],

        /*
        | #3 + #4 — Speaker line, drawn as one centred group so the muted label
        | and the bright name share a baseline despite differing sizes.
        */
        'speaker_line' => [
            'type' => 'text_group',
            'center_x' => 640,
            'baseline_y' => 232,
            'gap' => 14,
            'max_width' => 1000,
            'parts' => [
                // #3 — constant, supplied by the template, never by the form.
                'label' => [
                    'text' => 'USTADZ',
                    'font' => 'sans_bold',
                    'font_size' => 22,
                    'min_font_size' => 16,
                    'color' => '#B5B5B5',
                    'transform' => 'upper',
                ],
                // #4 — from the Speaker record; uppercased for render only.
                'name' => [
                    'font' => 'sans_bold',
                    'font_size' => 32,
                    'min_font_size' => 20,
                    'color' => '#FFFFFF',
                    'transform' => 'upper',
                ],
            ],
        ],

        // #5 — Constant branding. A committed template asset, not user input.
        'branding' => [
            'type' => 'image',
            'asset' => 'branding.png',
            'x' => 1022,
            'y' => 42,
            'width' => 210,
            'height' => 76,
        ],

        // #6 — Primary title. Largest element, and must never wrap.
        'primary_title' => [
            'type' => 'text',
            'x' => 48,
            'y' => 286,
            'width' => 1184,
            'align' => 'center',
            'font' => 'sans_bold',
            'font_size' => 72,
            'min_font_size' => 38,
            'color' => '#FFFFFF',
            'max_lines' => 1,
            'transform' => 'upper',
        ],

        // #7 — Supporting subtitle. Balanced wrap, hard ceiling of two lines.
        'subtitle' => [
            'type' => 'text',
            'x' => 100,
            'y' => 380,
            'width' => 1080,
            'align' => 'center',
            'font' => 'sans_bold',
            'font_size' => 38,
            'min_font_size' => 24,
            'color' => '#F0F0F0',
            'max_lines' => 2,
            'line_height' => 1.22,
            'transform' => 'upper',
        ],

        // #8 — Optional part marker. Omitted entirely when part_number is null.
        'part' => [
            'type' => 'text',
            'x' => 48,
            'y' => 486,
            'width' => 1184,
            'align' => 'center',
            'font' => 'sans_bold',
            'font_size' => 28,
            'min_font_size' => 20,
            'color' => '#FFFFFF',
            'max_lines' => 1,
            'format' => '~ PART-%d ~',
        ],

        /*
        | Waveform. Reserved zone: nothing else may be placed below y=540, so
        | the wave can never collide with the part marker or the subtitle.
        */
        'waveform' => [
            'type' => 'waveform',
            'x' => 320,
            'y' => 540,
            'width' => 640,
            'height' => 150,
            'color' => 'red',
            'mode' => 'cline',
        ],
    ],
];
