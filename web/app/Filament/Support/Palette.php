<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * The phone's colours (docs/DESIGN_SYSTEM.md: Radix slate and indigo) as Filament palettes. Filament reads the low
 * shades in the light theme and the high ones in the dark theme, so each scale runs from the light background (50)
 * to the dark background (950), with the design's text, border and brand steps where Filament looks for them.
 */
final class Palette
{
    /** Slate: 50 light background, 100 muted surface, 300 border, 500/600 secondary text, 900 dark surface, 950 dark background. */
    public const SLATE = [
        50 => '#F9F9FB',
        100 => '#F0F0F3',
        200 => '#E8E8EC',
        300 => '#D9D9E0',
        400 => '#B0B4BA',
        500 => '#6F727B',
        600 => '#60646C',
        700 => '#363A3F',
        800 => '#272A2D',
        900 => '#18191B',
        950 => '#111113',
    ];

    /** Indigo: 50 brand subtle, 400 brand text on dark, 500/600 the brand fill, 700 pressed, 950 brand subtle on dark. */
    public const INDIGO = [
        50 => '#EDF2FE',
        100 => '#E1E9FF',
        200 => '#D2DEFF',
        300 => '#ABBDF9',
        400 => '#9EB1FF',
        500 => '#3E63DD',
        600 => '#3E63DD',
        700 => '#3358D4',
        800 => '#3A5BC7',
        900 => '#1F2D5C',
        950 => '#182449',
    ];

    /** Red: danger text #CE2C31 on light, #FF9592 on dark; white text sits on #CE2C31. */
    public const RED = [
        50 => '#FEEBEC',
        100 => '#FFDBDC',
        200 => '#FDBDBE',
        300 => '#F4A9AA',
        400 => '#FF9592',
        500 => '#CE2C31',
        600 => '#CE2C31',
        700 => '#B5262B',
        800 => '#8C1D21',
        900 => '#5C1316',
        950 => '#3B1219',
    ];

    /** Amber: the warning fill #FFC53D, text #AB6400 on light and #FFCA16 on dark. */
    public const AMBER = [
        50 => '#FEFBE9',
        100 => '#FFF7C2',
        200 => '#FFEE9C',
        300 => '#FBE577',
        400 => '#FFCA16',
        500 => '#FFC53D',
        600 => '#AB6400',
        700 => '#8F5300',
        800 => '#6E4000',
        900 => '#4A2B00',
        950 => '#302008',
    ];

    /** Green, used rarely: #218358 on light, #3DD68C on dark. */
    public const GREEN = [
        50 => '#E9F6E9',
        100 => '#DAF1DB',
        200 => '#C9E8CA',
        300 => '#B2DDB5',
        400 => '#3DD68C',
        500 => '#30A46C',
        600 => '#218358',
        700 => '#1B6E4A',
        800 => '#16583B',
        900 => '#0E3D29',
        950 => '#132D21',
    ];

    /** Teal for information and the other side of a call (the phone's "Client" colour). */
    public const TEAL = [
        50 => '#E0F8F3',
        100 => '#CCF3EA',
        200 => '#B8EAE0',
        300 => '#A1DED2',
        400 => '#0BD8B6',
        500 => '#12A594',
        600 => '#008573',
        700 => '#0D7A6B',
        800 => '#0D5E53',
        900 => '#0D3D38',
        950 => '#0D2D2A',
    ];
}
