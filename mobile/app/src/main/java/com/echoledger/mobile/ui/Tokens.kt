package com.echoledger.mobile.ui

import androidx.compose.animation.core.CubicBezierEasing
import androidx.compose.animation.core.Easing
import androidx.compose.runtime.Immutable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp

/**
 * EchoLedger design tokens. The same names and values live in desktop/src/styles.css and
 * web/resources/css/filament/admin/theme.css, so the three apps read as one family.
 */
object Space {
    val s1 = 4.dp
    val s2 = 8.dp
    val s3 = 12.dp
    val s4 = 16.dp
    val s5 = 24.dp
    val s6 = 32.dp
    val s7 = 48.dp
}

object Radius {
    val sm = 8.dp
    val md = 12.dp
    val lg = 16.dp
}

/** 150-220 ms, ease-out; animations check [reduceMotion] first. */
object Motion {
    const val FAST = 150
    const val BASE = 180
    const val SLOW = 220
    val EaseOut: Easing = CubicBezierEasing(0f, 0f, 0f, 1f)
}

/** Colour tokens, one instance per theme (and one built from the wallpaper when dynamic colour is on). */
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
    val accentSoft: Color,
    val onAccent: Color,
    val danger: Color,
    val dangerStrong: Color,
    val success: Color,
    val warning: Color,
    val me: Color,
    val client: Color,
    val isDark: Boolean
)

val DarkColors = EchoColors(
    bg = Color(0xFF111214),
    surface = Color(0xFF1A1B1F),
    surface2 = Color(0xFF222328),
    surface3 = Color(0x0FFFFFFF),
    border = Color(0x14FFFFFF),
    borderStrong = Color(0x26FFFFFF),
    text = Color(0xFFF2F3F5),
    textMuted = Color(0xFFA8ACB7),
    textFaint = Color(0xFF8D919D),
    accent = Color(0xFF8B5CF6),
    accentText = Color(0xFFB4A0FB),
    accentStrong = Color(0xFF7C3AED),
    accentSoft = Color(0x298B5CF6),
    onAccent = Color.White,
    danger = Color(0xFFF87185),
    dangerStrong = Color(0xFFD61F45),
    success = Color(0xFF3CCF91),
    warning = Color(0xFFF5B731),
    me = Color(0xFFB4A0FB),
    client = Color(0xFF4FD1E8),
    isDark = true
)

val LightColors = EchoColors(
    bg = Color(0xFFF5F6F8),
    surface = Color(0xFFFFFFFF),
    surface2 = Color(0xFFF0F1F4),
    surface3 = Color(0x0D0F172A),
    border = Color(0x1A0F172A),
    borderStrong = Color(0x2E0F172A),
    text = Color(0xFF15171B),
    textMuted = Color(0xFF565B69),
    textFaint = Color(0xFF636877),
    accent = Color(0xFF7C3AED),
    accentText = Color(0xFF6D28D9),
    accentStrong = Color(0xFF7C3AED),
    accentSoft = Color(0x1A7C3AED),
    onAccent = Color.White,
    danger = Color(0xFFC4183A),
    dangerStrong = Color(0xFFC4183A),
    success = Color(0xFF047857),
    warning = Color(0xFF8F4A0C),
    me = Color(0xFF6D28D9),
    client = Color(0xFF0B7187),
    isDark = false
)
