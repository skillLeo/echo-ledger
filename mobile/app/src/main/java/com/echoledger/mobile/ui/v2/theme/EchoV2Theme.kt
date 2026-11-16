package com.echoledger.mobile.ui.v2.theme

import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.ReadOnlyComposable
import androidx.compose.runtime.remember
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.TextUnit
import com.echoledger.mobile.ui.design.UrduNastaliq
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * The colour roles of the v2 design system (docs/v2/DESIGN_SYSTEM.md) for one theme. Components read these, never the
 * raw palette. [record], [danger], [success] and [warning] are fixed: dynamic colour never changes them.
 */
@Immutable
data class EchoV2Colors(
    val isDark: Boolean,
    val bgCanvas: Color,
    val bgSurface: Color,
    val bgSubtle: Color,
    val bgOverlay: Color,
    val borderDefault: Color,
    val borderStrong: Color,
    val textPrimary: Color,
    val textSecondary: Color,
    val textOnAccent: Color,
    val textDisabled: Color,
    val accent: Color,
    val accentSoft: Color,
    val accentText: Color,
    val accentFocus: Color,
    val record: Color,
    val recordSoft: Color,
    val success: Color,
    val successSoft: Color,
    val warning: Color,
    val warningSoft: Color,
    val danger: Color,
    val dangerSoft: Color,
    /** The accent as it reads on the inverse surface (toasts): the other theme's accent text. */
    val inverseAccent: Color
) {
    companion object {
        val Light = EchoTokens.Light.run {
            EchoV2Colors(
                isDark = false,
                bgCanvas = bgCanvas,
                bgSurface = bgSurface,
                bgSubtle = bgSubtle,
                bgOverlay = bgOverlay,
                borderDefault = borderDefault,
                borderStrong = borderStrong,
                textPrimary = textPrimary,
                textSecondary = textSecondary,
                textOnAccent = textOnAccent,
                textDisabled = textDisabled,
                accent = accentDefault,
                accentSoft = accentSoft,
                accentText = accentText,
                accentFocus = accentFocus,
                record = recordDefault,
                recordSoft = recordSoft,
                success = successDefault,
                successSoft = successSoft,
                warning = warningDefault,
                warningSoft = warningSoft,
                danger = dangerDefault,
                dangerSoft = dangerSoft,
                inverseAccent = EchoTokens.Dark.accentText
            )
        }

        val Dark = EchoTokens.Dark.run {
            EchoV2Colors(
                isDark = true,
                bgCanvas = bgCanvas,
                bgSurface = bgSurface,
                bgSubtle = bgSubtle,
                bgOverlay = bgOverlay,
                borderDefault = borderDefault,
                borderStrong = borderStrong,
                textPrimary = textPrimary,
                textSecondary = textSecondary,
                textOnAccent = textOnAccent,
                textDisabled = textDisabled,
                accent = accentDefault,
                accentSoft = accentSoft,
                accentText = accentText,
                accentFocus = accentFocus,
                record = recordDefault,
                recordSoft = recordSoft,
                success = successDefault,
                successSoft = successSoft,
                warning = warningDefault,
                warningSoft = warningSoft,
                danger = dangerDefault,
                dangerSoft = dangerSoft,
                inverseAccent = EchoTokens.Light.accentText
            )
        }
    }

    /**
     * Dynamic colour: the wallpaper's scheme replaces the accent family only. Surfaces stay neutral (calm by default)
     * and the record, danger, success and warning colours keep their fixed values.
     */
    fun withDynamicAccent(scheme: ColorScheme): EchoV2Colors = copy(
        accent = scheme.primary,
        accentSoft = scheme.primaryContainer,
        accentText = scheme.primary,
        accentFocus = scheme.primary,
        textOnAccent = scheme.onPrimary,
        inverseAccent = scheme.inversePrimary
    )

    /** The Material 3 scheme of these roles, so stock Material parts (ripples, sliders, menus) agree with them. */
    fun toMaterial(): ColorScheme {
        val base = if (isDark) darkColorScheme() else lightColorScheme()
        return base.copy(
            primary = accent,
            onPrimary = textOnAccent,
            primaryContainer = accentSoft,
            onPrimaryContainer = accentText,
            inversePrimary = inverseAccent,
            secondary = textSecondary,
            onSecondary = bgSurface,
            secondaryContainer = accentSoft,
            onSecondaryContainer = accentText,
            tertiary = accent,
            onTertiary = textOnAccent,
            background = bgCanvas,
            onBackground = textPrimary,
            surface = bgCanvas,
            onSurface = textPrimary,
            surfaceVariant = bgSubtle,
            onSurfaceVariant = textSecondary,
            surfaceTint = bgCanvas,
            inverseSurface = textPrimary,
            inverseOnSurface = bgSurface,
            error = danger,
            onError = textOnAccent,
            errorContainer = dangerSoft,
            onErrorContainer = danger,
            outline = borderStrong,
            outlineVariant = borderDefault,
            surfaceBright = bgSurface,
            surfaceDim = bgCanvas,
            surfaceContainerLowest = bgSurface,
            surfaceContainerLow = bgSurface,
            surfaceContainer = bgSurface,
            surfaceContainerHigh = bgOverlay,
            surfaceContainerHighest = bgSubtle
        )
    }
}

/** The interface language of the v2 screens. Urdu reads right to left in Noto Nastaliq Urdu. */
enum class EchoLanguage(val rtl: Boolean) {
    English(rtl = false),
    Urdu(rtl = true)
}

/**
 * Text styles of the v2 design system on the phone. On mobile the default body text is 16 sp ([body], Material
 * bodyLarge), not the desktop's 14 sp. Durations and clocks use [mono]: tabular digits in the system face, also in
 * Urdu, so numbers stay left to right and line up.
 */
@Immutable
class EchoV2Type(private val family: FontFamily, private val urdu: Boolean) {
    private fun TextStyle.face(): TextStyle {
        if (!urdu) return copy(fontFamily = family)
        // Nastaliq needs the taller line of urdu.body (16/30) at every size.
        val ratio = EchoTokens.Type.urduBody.lineHeight.value / EchoTokens.Type.urduBody.fontSize.value
        return copy(fontFamily = family, lineHeight = TextUnit(fontSize.value * ratio, fontSize.type))
    }

    val titleL = EchoTokens.Type.titleL.face()
    val titleM = EchoTokens.Type.titleM.face()
    val titleS = EchoTokens.Type.titleS.face()

    /** Default text on the phone: 16 sp at the body weight. */
    val body = EchoTokens.Type.titleS.copy(fontWeight = EchoTokens.Type.body.fontWeight).face()

    /** Row titles and emphasis on the phone: 16 sp, strong. */
    val bodyStrong = EchoTokens.Type.titleS.copy(fontWeight = EchoTokens.Type.bodyStrong.fontWeight).face()

    /** Meta lines and supporting text: 14 sp. */
    val meta = EchoTokens.Type.body.face()

    /** Buttons, labels and chips: 14 sp, strong. */
    val label = EchoTokens.Type.bodyStrong.face()
    val caption = EchoTokens.Type.caption.face()
    val captionStrong = EchoTokens.Type.caption.copy(fontWeight = EchoTokens.Type.bodyStrong.fontWeight).face()

    /** Durations and clocks; always the system face with tabular digits. */
    val mono = EchoTokens.Type.mono.copy(fontFamily = FontFamily.Default, fontFeatureSettings = "tnum")

    /** Transcript text in Urdu (urdu.body). */
    val urduBody = EchoTokens.Type.urduBody.copy(fontFamily = UrduNastaliq)

    fun material(): Typography = Typography(
        displaySmall = EchoTokens.Type.display.face(),
        headlineSmall = titleL,
        titleLarge = titleM,
        titleMedium = titleS,
        titleSmall = label,
        bodyLarge = body,
        bodyMedium = meta,
        bodySmall = caption,
        labelLarge = label,
        labelMedium = captionStrong,
        labelSmall = caption
    )

    companion object {
        val System = EchoV2Type(FontFamily.Default, urdu = false)
        val Urdu = EchoV2Type(UrduNastaliq, urdu = true)
    }
}

/** The words the components say themselves (accessibility labels and default actions), per language. */
@Immutable
data class EchoV2Strings(
    val clearSearch: String,
    val close: String,
    val loading: String,
    val undo: String,
    val selected: String,
    val notSelected: String,
    val openMenu: String,
    val dragHandle: String,
    val back: String,
    val more: String
) {
    companion object {
        val English = EchoV2Strings(
            clearSearch = "Clear search",
            close = "Close",
            loading = "Loading",
            undo = "Undo",
            selected = "Selected",
            notSelected = "Not selected",
            openMenu = "Show choices",
            dragHandle = "Drag to resize or close",
            back = "Back",
            more = "More options"
        )
        val Urdu = EchoV2Strings(
            clearSearch = "تلاش صاف کریں",
            close = "بند کریں",
            loading = "لوڈ ہو رہا ہے",
            undo = "واپس لیں",
            selected = "منتخب",
            notSelected = "منتخب نہیں",
            openMenu = "انتخاب دکھائیں",
            dragHandle = "سائز بدلنے یا بند کرنے کے لیے کھینچیں",
            back = "واپس",
            more = "مزید"
        )
    }
}

val LocalEchoV2Colors = staticCompositionLocalOf { EchoV2Colors.Light }
val LocalEchoV2Type = staticCompositionLocalOf { EchoV2Type.System }
val LocalEchoV2Strings = staticCompositionLocalOf { EchoV2Strings.English }

/** Shortcuts to the current v2 theme values. */
object EchoV2 {
    val colors: EchoV2Colors
        @Composable @ReadOnlyComposable
        get() = LocalEchoV2Colors.current
    val type: EchoV2Type
        @Composable @ReadOnlyComposable
        get() = LocalEchoV2Type.current
    val strings: EchoV2Strings
        @Composable @ReadOnlyComposable
        get() = LocalEchoV2Strings.current
}

/** Language of the phone: Urdu when the locale is Urdu, otherwise English. */
@Composable
@ReadOnlyComposable
fun systemEchoLanguage(): EchoLanguage =
    if (LocalConfiguration.current.locales[0]?.language == "ur") EchoLanguage.Urdu else EchoLanguage.English

/**
 * The v2 theme: EchoTokens mapped onto Material 3. Light or dark follows the system unless [darkTheme] says otherwise.
 * [dynamicColor] (Android 12 and later) takes the accent from the wallpaper; brand and status colours stay fixed.
 * [language] sets the text face, the components' own words and the layout direction (Urdu is right to left).
 */
@Composable
fun EchoV2Theme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    dynamicColor: Boolean = false,
    language: EchoLanguage = systemEchoLanguage(),
    content: @Composable () -> Unit
) {
    val context = LocalContext.current
    val colors = remember(darkTheme, dynamicColor) {
        val fixed = if (darkTheme) EchoV2Colors.Dark else EchoV2Colors.Light
        if (dynamicColor && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            fixed.withDynamicAccent(if (darkTheme) dynamicDarkColorScheme(context) else dynamicLightColorScheme(context))
        } else {
            fixed
        }
    }
    val type = if (language == EchoLanguage.Urdu) EchoV2Type.Urdu else EchoV2Type.System
    val strings = if (language == EchoLanguage.Urdu) EchoV2Strings.Urdu else EchoV2Strings.English
    val direction = if (language.rtl) LayoutDirection.Rtl else LayoutDirection.Ltr
    CompositionLocalProvider(
        LocalEchoV2Colors provides colors,
        LocalEchoV2Type provides type,
        LocalEchoV2Strings provides strings,
        LocalLayoutDirection provides direction
    ) {
        MaterialTheme(colorScheme = colors.toMaterial(), typography = type.material(), shapes = EchoV2Shapes, content = content)
    }
}

/** Material shape classes on the token radii: fields and buttons, cards and menus, dialogs, sheets. */
private val EchoV2Shapes = Shapes(
    extraSmall = RoundedCornerShape(EchoTokens.Component.fieldRadius),
    small = RoundedCornerShape(EchoTokens.Component.buttonRadius),
    medium = RoundedCornerShape(EchoTokens.Component.menuRadius),
    large = RoundedCornerShape(EchoTokens.Component.dialogRadius),
    extraLarge = RoundedCornerShape(EchoTokens.Component.sheetRadius)
)
