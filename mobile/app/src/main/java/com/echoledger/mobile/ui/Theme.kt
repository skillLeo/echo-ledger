package com.echoledger.mobile.ui

import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Call
import androidx.compose.material.icons.outlined.Computer
import androidx.compose.material.icons.outlined.Forum
import androidx.compose.material.icons.outlined.Language
import androidx.compose.material.icons.outlined.Mic
import androidx.compose.material.icons.outlined.MoreHoriz
import androidx.compose.material.icons.outlined.PhoneAndroid
import androidx.compose.material.icons.outlined.Storefront
import androidx.compose.material.icons.outlined.Videocam
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.ReadOnlyComposable
import androidx.compose.runtime.remember
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.SourceDevice
import com.echoledger.mobile.ui.design.EchoType

/** The colour tokens of the current theme (Tokens.kt); read them through [Brand] or `MaterialTheme.colorScheme`. */
val LocalEchoColors = staticCompositionLocalOf { DarkColors }

/**
 * EchoLedger colours by role, for the current theme (light, dark, or the wallpaper's colours when dynamic colour is
 * on). One accent is used sparingly on primary actions and live states.
 */
object Brand {
    val Background: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.bg
    val Surface: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.surface
    val SurfaceAlt: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.surface2
    val Elevated: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.surface3
    val Border: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.border
    val BorderStrong: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.borderStrong
    val Text: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.text
    val Muted: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.textMuted
    val Faint: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.textFaint

    val Violet: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.accent
    val AccentText: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.accentText
    val Cyan: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.client
    val Danger: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.danger
    val Success: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.success
    val Warning: Color
        @Composable @ReadOnlyComposable
        get() = LocalEchoColors.current.warning

    /** Fill of primary actions (solid: no gradients on controls). */
    val Gradient: Brush
        @Composable @ReadOnlyComposable
        get() = SolidColor(LocalEchoColors.current.accentStrong)
    val GradientSoft: Brush
        @Composable @ReadOnlyComposable
        get() = SolidColor(LocalEchoColors.current.accentSoft)

    /** Per-source accent used for the small device avatar and badges. */
    @Composable
    @ReadOnlyComposable
    fun accent(device: SourceDevice): Color = when (device) {
        SourceDevice.PHONE -> Success
        SourceDevice.DESKTOP -> Cyan
        SourceDevice.WEB -> AccentText
    }
}

/** Corner radii by size class: tags 6, chips 10, cards 14, sheets and dialogs 20. */
private val EchoShapes = Shapes(
    extraSmall = RoundedCornerShape(Radius.tag),
    small = RoundedCornerShape(Radius.sm),
    medium = RoundedCornerShape(Radius.md),
    large = RoundedCornerShape(Radius.lg),
    extraLarge = RoundedCornerShape(Radius.lg)
)

/** The type scale of the current theme (bundled Inter, or the phone's font when Appearance says so). */
val LocalEchoType = staticCompositionLocalOf { EchoType.Bundled }

/** `system`, `light` or `dark` (the General setting) resolved against the phone's dark theme. */
@Composable
fun isDarkTheme(themeMode: String): Boolean = when (themeMode) {
    "light" -> false
    "dark" -> true
    else -> isSystemInDarkTheme()
}

/** Dynamic colour (Material You) needs Android 12. */
val supportsDynamicColour: Boolean get() = Build.VERSION.SDK_INT >= Build.VERSION_CODES.S

/** Our tokens with the wallpaper's accent and surfaces (dynamic colour); text and status colours stay ours. */
private fun EchoColors.withScheme(scheme: ColorScheme): EchoColors = copy(
    bg = scheme.surface,
    surface = scheme.surfaceContainerLow,
    surface2 = scheme.surfaceContainerHigh,
    text = scheme.onSurface,
    textMuted = scheme.onSurfaceVariant,
    accent = scheme.primary,
    accentText = scheme.primary,
    accentStrong = scheme.primary,
    accentSoft = scheme.primary.copy(alpha = if (isDark) 0.16f else 0.1f),
    onAccent = scheme.onPrimary,
    me = scheme.primary
)

/** The Material 3 colour scheme of these tokens (the app theme and the home-screen widget). */
fun EchoColors.toScheme(): ColorScheme {
    val base = if (isDark) darkColorScheme() else lightColorScheme()
    return base.copy(
        primary = accentStrong,
        onPrimary = onAccent,
        primaryContainer = accentSoft,
        // Material picks secondaryContainer for selected chips and segments: ours, not its default lavender.
        secondaryContainer = accentSoft,
        onSecondaryContainer = accentText,
        surfaceTint = bg,
        onPrimaryContainer = accentText,
        secondary = client,
        background = bg,
        onBackground = text,
        surface = bg,
        onSurface = text,
        surfaceVariant = surface2,
        onSurfaceVariant = textMuted,
        surfaceContainerLowest = bg,
        surfaceContainerLow = surface,
        surfaceContainer = surface,
        surfaceContainerHigh = surface2,
        surfaceContainerHighest = surface2,
        error = danger,
        outline = borderStrong,
        outlineVariant = border
    )
}

@Composable
fun EchoLedgerTheme(
    themeMode: String = "system",
    dynamicColour: Boolean = false,
    phoneFont: Boolean = false,
    content: @Composable () -> Unit
) {
    val dark = isDarkTheme(themeMode)
    val base = if (dark) DarkColors else LightColors
    val context = LocalContext.current
    val dynamicScheme = if (dynamicColour && supportsDynamicColour) {
        if (dark) dynamicDarkColorScheme(context) else dynamicLightColorScheme(context)
    } else {
        null
    }
    val colors = dynamicScheme?.let { base.withScheme(it) } ?: base
    val type = if (phoneFont) EchoType.Phone else EchoType.Bundled
    CompositionLocalProvider(LocalEchoColors provides colors, LocalEchoType provides type) {
        MaterialTheme(
            colorScheme = dynamicScheme ?: colors.toScheme(),
            typography = type.material(),
            shapes = EchoShapes,
            content = content
        )
    }
}

fun SourceDevice.icon(): ImageVector = when (this) {
    SourceDevice.PHONE -> Icons.Outlined.PhoneAndroid
    SourceDevice.DESKTOP -> Icons.Outlined.Computer
    SourceDevice.WEB -> Icons.Outlined.Language
}

@Composable
@ReadOnlyComposable
fun SourceDevice.tint(): Color = Brand.accent(this)

fun SourceApp.icon(): ImageVector = when (this) {
    SourceApp.WHATSAPP, SourceApp.WHATSAPP_WEB -> Icons.Outlined.Forum

    SourceApp.WHATSAPP_BUSINESS -> Icons.Outlined.Storefront

    SourceApp.GOOGLE_MEET, SourceApp.ZOOM, SourceApp.MICROSOFT_TEAMS, SourceApp.SKYPE -> Icons.Outlined.Videocam

    SourceApp.TELEGRAM, SourceApp.SIGNAL, SourceApp.MESSENGER, SourceApp.INSTAGRAM, SourceApp.SLACK, SourceApp.DISCORD ->
        Icons.Outlined.Forum

    SourceApp.PHONE_CALL -> Icons.Outlined.Call

    SourceApp.VOICE_NOTE -> Icons.Outlined.Mic

    SourceApp.OTHER -> Icons.Outlined.MoreHoriz
}

/** "Remove animations" is on in the phone's accessibility settings: motion is skipped, states change at once. */
@Composable
fun reduceMotion(): Boolean {
    val context = LocalContext.current
    return remember(context) {
        android.provider.Settings.Global.getFloat(context.contentResolver, android.provider.Settings.Global.ANIMATOR_DURATION_SCALE, 1f) ==
            0f
    }
}
