package com.echoledger.mobile.ui

import androidx.compose.animation.core.CubicBezierEasing
import androidx.compose.animation.core.Easing
import androidx.compose.runtime.Immutable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp

/**
 * EchoLedger design tokens (docs/DESIGN_SYSTEM.md). Since v1.3 the phone uses the slate + indigo palette (Radix Colors
 * 3.0, mapped onto Material 3 roles); the desktop app and the console keep their own until they are redesigned.
 */
object Space {
    val s0 = 2.dp
    val s1 = 4.dp
    val s2 = 8.dp
    val s3 = 12.dp
    val s4 = 16.dp
    val s4h = 20.dp
    val s5 = 24.dp
    val s6 = 32.dp
    val s6h = 40.dp
    val s7 = 48.dp

    /** Side margin of every screen. */
    val screen = 16.dp

    /** Content never gets wider than this on tablets and unfolded phones. */
    val maxContent = 640.dp
}

/** 6 tags · 10 chips · 12 inputs and row tiles · 14 cards · 20 sheets and dialogs; buttons and pills are full. */
object Radius {
    val tag = 6.dp
    val sm = 10.dp
    val field = 12.dp
    val md = 14.dp
    val lg = 20.dp
}

/** Press 120 ms · state change 200 ms · enter 300 ms · exit 150 ms; animations check [reduceMotion] first. */
object Motion {
    const val PRESS = 120
    const val FAST = 150
    const val BASE = 200
    const val SLOW = 300
    val Standard: Easing = CubicBezierEasing(0.2f, 0f, 0f, 1f)
    val EaseOut: Easing = CubicBezierEasing(0f, 0f, 0f, 1f)
    val EaseIn: Easing = CubicBezierEasing(0.3f, 0f, 1f, 1f)
}

/**
 * Colour tokens, one instance per theme (and one built from the wallpaper when dynamic colour is on). [text],
 * [textMuted] and [textFaint] all keep 4.5:1 on [bg] and [surface]; [danger] is the readable red for text and icons,
 * [dangerStrong] the fill behind white text.
 */
@Immutable
data class EchoColors(
    val bg: Color,
    val surface: Color,
    val surface2: Color,
    val surface3: Color,
    val border: Color,
    val borderStrong: Color,
    val text: Color,
    val textMuted: Color,
    val textFaint: Color,
    val accent: Color,
    val accentText: Color,
    val accentStrong: Color,
    val accentPressed: Color,
    val accentSoft: Color,
    val onAccent: Color,
    val danger: Color,
    val dangerStrong: Color,
    val dangerSoft: Color,
    val success: Color,
    val warning: Color,
    val warningFill: Color,
    val me: Color,
    val client: Color,
    val isDark: Boolean
)

val DarkColors = EchoColors(
    bg = Color(0xFF111113),
    surface = Color(0xFF18191B),
    surface2 = Color(0xFF212225),
    surface3 = Color(0xFF272A2D),
    border = Color(0xFF272A2D),
    borderStrong = Color(0xFF363A3F),
    text = Color(0xFFEDEEF0),
    textMuted = Color(0xFFB0B4BA),
    textFaint = Color(0xFF80848E),
    accent = Color(0xFF3E63DD),
    accentText = Color(0xFF9EB1FF),
    accentStrong = Color(0xFF3E63DD),
    accentPressed = Color(0xFF5472E4),
    accentSoft = Color(0xFF182449),
    onAccent = Color.White,
    danger = Color(0xFFFF9592),
    dangerStrong = Color(0xFFCE2C31),
    dangerSoft = Color(0xFF3B1219),
    success = Color(0xFF3DD68C),
    warning = Color(0xFFFFCA16),
    warningFill = Color(0xFFFFC53D),
    me = Color(0xFF9EB1FF),
    client = Color(0xFF0BD8B6),
    isDark = true
)

val LightColors = EchoColors(
    bg = Color(0xFFF9F9FB),
    surface = Color(0xFFFFFFFF),
    surface2 = Color(0xFFF0F0F3),
    surface3 = Color(0xFFE8E8EC),
    border = Color(0xFFE8E8EC),
    borderStrong = Color(0xFFD9D9E0),
    text = Color(0xFF1C2024),
    textMuted = Color(0xFF60646C),
    textFaint = Color(0xFF6F727B),
    accent = Color(0xFF3E63DD),
    accentText = Color(0xFF3A5BC7),
    accentStrong = Color(0xFF3E63DD),
    accentPressed = Color(0xFF3358D4),
    accentSoft = Color(0xFFEDF2FE),
    onAccent = Color.White,
    danger = Color(0xFFCE2C31),
    dangerStrong = Color(0xFFCE2C31),
    dangerSoft = Color(0xFFFEEBEC),
    success = Color(0xFF218358),
    warning = Color(0xFFAB6400),
    warningFill = Color(0xFFFFC53D),
    me = Color(0xFF3A5BC7),
    client = Color(0xFF008573),
    isDark = false
)
