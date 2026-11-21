package com.echoledger.mobile.ui.v2.theme

import android.content.ContextWrapper
import android.content.res.Configuration
import android.content.res.Resources
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
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
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.LocalResources
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.TextUnit
import androidx.core.os.ConfigurationCompat
import com.echoledger.mobile.R
import com.echoledger.mobile.ui.design.ArabicNaskh
import com.echoledger.mobile.ui.design.Inter
import com.echoledger.mobile.ui.design.UrduNastaliq
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.util.Locale

/**
 * The colour roles of the v2 design system (docs/v2/DESIGN_SYSTEM.md) for one theme. Components read these, never the
 * raw palette. They are fixed: Wallpaper colours (Settings › Appearance) changes the classic screens only.
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
    /** No colour at all (a quiet button's fill, an unselected tab's line). */
    val clear: Color get() = Color.Transparent

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
 * bodyLarge), not the desktop's 14 sp. Durations and clocks use [mono]: tabular digits in the Latin face, also in
 * Urdu, so numbers stay left to right and line up.
 *
 * The faces are the app's own, as on the classic screens (ui/design/Type.kt): [latin] is Inter, bundled, so a phone
 * with a custom system font (Samsung's) still shows the app's letters; Urdu is Noto Nastaliq Urdu and Arabic Noto
 * Naskh Arabic. "Use the phone's font" (off by default) makes [latin] the phone's own ([Phone], [PhoneUrdu]).
 */
@Immutable
class EchoV2Type(private val family: FontFamily, val latin: FontFamily, private val urdu: Boolean) {
    /**
     * The style in this face. In Urdu each role takes its Nastaliq size and line from the type tokens [urduRole]
     * (D4: title.l 24/44, title.m 20/40, title.s 16/30, body 16/30, caption and meta 14/28: Urdu is never under 14 sp).
     */
    private fun TextStyle.face(urduRole: TextStyle? = null): TextStyle = when {
        !urdu -> copy(fontFamily = family)
        urduRole != null -> copy(fontFamily = family, fontSize = urduRole.fontSize, lineHeight = urduRole.lineHeight)
        else -> copy(fontFamily = family).nastaliqLine()
    }

    val titleL = EchoTokens.Type.titleL.face(EchoTokens.Type.urduTitleL)
    val titleM = EchoTokens.Type.titleM.face(EchoTokens.Type.urduTitleM)
    val titleS = EchoTokens.Type.titleS.face(EchoTokens.Type.urduTitleS)

    /** Default text on the phone: body.mobile, 16/24. */
    val body = EchoTokens.Type.bodyMobile.face(EchoTokens.Type.urduBody)

    /** Row titles and emphasis on the phone: 16 sp, strong. */
    val bodyStrong = EchoTokens.Type.bodyMobile.copy(fontWeight = EchoTokens.Type.bodyStrong.fontWeight).face(EchoTokens.Type.urduBody)

    /** Meta lines and supporting text: 14 sp. */
    val meta = EchoTokens.Type.body.face(EchoTokens.Type.urduCaption)

    /** Buttons, labels and chips: 14 sp, strong. */
    val label = EchoTokens.Type.bodyStrong.face(EchoTokens.Type.urduCaption)
    val caption = EchoTokens.Type.caption.face(EchoTokens.Type.urduCaption)
    val captionStrong = EchoTokens.Type.caption.copy(fontWeight = EchoTokens.Type.bodyStrong.fontWeight).face(EchoTokens.Type.urduCaption)

    /** Durations and clocks; always the Latin face with tabular digits. */
    val mono = EchoTokens.Type.mono.copy(fontFamily = latin, fontFeatureSettings = "tnum")

    /** Email addresses, links and codes inside any language: the Latin face, 14 sp. */
    val metaLatin = EchoTokens.Type.body.copy(fontFamily = latin)

    /** Content written in English whatever the screen's language (Live Assist's suggestions): the Latin face. */
    val contentStrong = EchoTokens.Type.bodyMobile.copy(fontWeight = EchoTokens.Type.bodyStrong.fontWeight, fontFamily = latin)
    val contentCaption = EchoTokens.Type.caption.copy(fontFamily = latin)

    /**
     * People's own words (a recording's title and preview, an answer, a quote) in [style] ([body], [bodyStrong] or
     * [meta]): the line takes its direction from its words, so an English sentence keeps its full stop at the end in
     * the Urdu screens, and Latin words keep the Latin face there rather than the Nastaliq font's Latin letters. Words
     * in Urdu's script take Noto Nastaliq Urdu (Noto Naskh Arabic when they are Arabic) on the English screens too.
     */
    fun content(text: String, style: TextStyle): TextStyle {
        val script = scriptOf(text)
        val latinInUrdu = urdu && script == Script.Latin
        val base = when {
            latinInUrdu -> when (style) {
                bodyStrong -> contentStrong

                meta -> metaLatin

                caption -> contentCaption

                body -> EchoTokens.Type.bodyMobile.copy(fontFamily = latin)

                // Titles keep their size and weight in the Latin face.
                else -> style.copy(fontFamily = latin)
            }

            script == Script.Urdu && !urdu -> style.copy(fontFamily = UrduNastaliq).nastaliqLine()

            script == Script.Arabic -> style.copy(fontFamily = ArabicNaskh)

            else -> style
        }
        return base.copy(textDirection = TextDirection.Content)
    }

    /** Transcript text in Urdu (urdu.body). */
    val urduBody = EchoTokens.Type.urduBody.copy(fontFamily = UrduNastaliq)

    /** Transcript text in Arabic (arabic.body). */
    val arabicBody = EchoTokens.Type.arabicBody.copy(fontFamily = ArabicNaskh)

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
        /** The app's own faces (the default): Inter, Noto Nastaliq Urdu. */
        val System = EchoV2Type(Inter, Inter, urdu = false)
        val Urdu = EchoV2Type(UrduNastaliq, Inter, urdu = true)

        /** "Use the phone's font": the phone's own Latin face; Urdu stays in Noto Nastaliq Urdu. */
        val Phone = EchoV2Type(FontFamily.Default, FontFamily.Default, urdu = false)
        val PhoneUrdu = EchoV2Type(UrduNastaliq, FontFamily.Default, urdu = true)

        fun of(language: EchoLanguage, phoneFont: Boolean): EchoV2Type = when {
            language == EchoLanguage.Urdu -> if (phoneFont) PhoneUrdu else Urdu
            else -> if (phoneFont) Phone else System
        }
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
        /** The words in the language of [res] (strings_v2_core.xml, values and values-ur). */
        fun from(res: Resources) = EchoV2Strings(
            clearSearch = res.getString(R.string.v2_clear_search),
            close = res.getString(R.string.v2_close),
            loading = res.getString(R.string.v2_loading),
            undo = res.getString(R.string.v2_undo),
            selected = res.getString(R.string.v2_selected),
            notSelected = res.getString(R.string.v2_not_selected),
            openMenu = res.getString(R.string.v2_open_menu),
            dragHandle = res.getString(R.string.v2_drag_handle),
            back = res.getString(R.string.v2_back),
            more = res.getString(R.string.v2_more)
        )
    }
}

val LocalEchoV2Colors = staticCompositionLocalOf { EchoV2Colors.Light }
val LocalEchoV2Type = staticCompositionLocalOf { EchoV2Type.System }
val LocalEchoV2Strings = staticCompositionLocalOf<EchoV2Strings?> { null }

/** Shortcuts to the current v2 theme values. */
object EchoV2 {
    val colors: EchoV2Colors
        @Composable @ReadOnlyComposable
        get() = LocalEchoV2Colors.current
    val type: EchoV2Type
        @Composable @ReadOnlyComposable
        get() = LocalEchoV2Type.current
    val strings: EchoV2Strings
        @Composable
        get() = LocalEchoV2Strings.current ?: LocalContext.current.resources.let { res -> remember(res) { EchoV2Strings.from(res) } }
}

/** Language of the phone: Urdu when the locale is Urdu, otherwise English. */
@Composable
@ReadOnlyComposable
fun systemEchoLanguage(): EchoLanguage =
    if (LocalConfiguration.current.locales[0]?.language == "ur") EchoLanguage.Urdu else EchoLanguage.English

/**
 * The v2 theme: EchoTokens mapped onto Material 3. Light or dark follows the system unless [darkTheme] says otherwise.
 * [wallpaperColours] (Settings › Appearance) changes the classic screens only: the redesigned screens keep EchoLedger's
 * own colours (DESIGN_SYSTEM "Colour roles"; a wallpaper's accent can be purple, which the brand rules forbid).
 * [language] sets the text face, the components' own words and the layout direction (Urdu is right to left).
 */
@Composable
fun EchoV2Theme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    language: EchoLanguage = systemEchoLanguage(),
    /** "Use the phone's font" (Settings › Appearance; off by default): the phone's own Latin face, not Inter. */
    phoneFont: Boolean = false,
    // Passed through by the shell; the redesigned screens don't use it (the accent stays teal).
    @Suppress("UNUSED_PARAMETER") wallpaperColours: Boolean = false,
    content: @Composable () -> Unit
) {
    val colors = if (darkTheme) EchoV2Colors.Dark else EchoV2Colors.Light
    val type = EchoV2Type.of(language, phoneFont)
    val direction = if (language.rtl) LayoutDirection.Rtl else LayoutDirection.Ltr
    ProvideLanguage(language) {
        val res = LocalContext.current.resources
        val strings = remember(res, LocalConfiguration.current) { EchoV2Strings.from(res) }
        CompositionLocalProvider(
            LocalEchoV2Colors provides colors,
            LocalEchoV2Type provides type,
            LocalEchoV2Strings provides strings,
            LocalLayoutDirection provides direction
        ) {
            MaterialTheme(colorScheme = colors.toMaterial(), typography = type.material(), shapes = EchoV2Shapes, content = content)
        }
    }
}

/** The locale whose values-* folder holds [this] language's words. */
val EchoLanguage.locale: Locale get() = if (this == EchoLanguage.Urdu) Locale.forLanguageTag("ur-PK") else Locale.UK

/**
 * Draws [content] with the resources of [language] when the phone's own language is another one (a preview, a test,
 * or an app language chosen in Settings). The activity stays the context's base, so starting activities still works.
 * The locals are always provided (the phone's own when they match), so changing the language keeps [content] where
 * it is instead of building it again.
 */
@Composable
fun ProvideLanguage(language: EchoLanguage, content: @Composable () -> Unit) {
    val config = LocalConfiguration.current
    val current = config.locales[0]?.language
    val matches = if (language == EchoLanguage.Urdu) current == "ur" else current != "ur"
    val context = LocalContext.current
    val localized = remember(context, config, language, matches) {
        if (matches) {
            context
        } else {
            val wanted = Configuration(config).apply { setLocale(language.locale) }
            val res = context.createConfigurationContext(wanted).resources
            object : ContextWrapper(context) {
                override fun getResources(): Resources = res
            }
        }
    }
    CompositionLocalProvider(
        LocalContext provides localized,
        LocalConfiguration provides if (matches) config else localized.resources.configuration,
        LocalResources provides localized.resources,
        content = content
    )
}

/**
 * The page's look as the shell set it (its language's context and resources, its text size, its direction), for
 * what draws in a window of its own (a dialog, a sheet, a menu): such a window starts again from the activity's, so
 * without this an app language or text size chosen in Settings would not reach it.
 */
class PageLook(
    val context: android.content.Context,
    val configuration: Configuration,
    val resources: Resources,
    val density: androidx.compose.ui.unit.Density,
    val direction: LayoutDirection
)

/** The look of the page this is drawn in. */
@Composable
fun rememberPageLook(): PageLook {
    val context = LocalContext.current
    val configuration = LocalConfiguration.current
    val resources = LocalResources.current
    val density = androidx.compose.ui.platform.LocalDensity.current
    val direction = LocalLayoutDirection.current
    return remember(context, configuration, resources, density, direction) {
        PageLook(context, configuration, resources, density, direction)
    }
}

/** Draws [content] (inside a dialog, sheet or menu window) with the page's [look]. */
@Composable
fun ProvidePageLook(look: PageLook, content: @Composable () -> Unit) = CompositionLocalProvider(
    LocalContext provides look.context,
    LocalConfiguration provides look.configuration,
    LocalResources provides look.resources,
    androidx.compose.ui.platform.LocalDensity provides look.density,
    LocalLayoutDirection provides look.direction,
    content = content
)

/** Dialogs on the phone share the sheet radius (16), so the phone uses three radii. */
val PhoneDialogRadius = EchoTokens.Component.sheetRadius

/**
 * Material shape classes on the phone's three radii: 4 (buttons, fields), 8 (cards, menus) and 16 (sheets and
 * dialogs). The pill (chips, avatars) does not count.
 */
private val EchoV2Shapes = Shapes(
    extraSmall = RoundedCornerShape(EchoTokens.Component.fieldRadius),
    small = RoundedCornerShape(EchoTokens.Component.buttonRadius),
    medium = RoundedCornerShape(EchoTokens.Component.menuRadius),
    large = RoundedCornerShape(PhoneDialogRadius),
    extraLarge = RoundedCornerShape(EchoTokens.Component.sheetRadius)
)

/** The screen's locale, read from the (observable) configuration so dates and numbers follow a language change. */
@Composable
fun screenLocale(): Locale = ConfigurationCompat.getLocales(LocalConfiguration.current)[0] ?: Locale.ROOT

/** Android's text size is 150 % or more: components drop decoration and give text more lines to keep words whole. */
@Composable
fun largeText(): Boolean = LocalDensity.current.fontScale >= 1.5f

/** The script a line is written in, by its letters: Urdu's own letters, else Arabic, else Latin. */
internal enum class Script { Latin, Urdu, Arabic }

/** Letters only Urdu uses (ٹ ڈ ڑ ں ے ہ ھ گ چ پ ژ ک ی): with any of them the words are Urdu, else Arabic. */
private const val URDU_LETTERS = "\u0679\u0688\u0691\u06BA\u06D2\u06C1\u06BE\u06AF\u0686\u067E\u0698\u06A9\u06CC"

internal fun scriptOf(text: String): Script {
    if (text.none { Character.UnicodeScript.of(it.code) == Character.UnicodeScript.ARABIC }) return Script.Latin
    return if (text.any { it in URDU_LETTERS }) Script.Urdu else Script.Arabic
}

/**
 * Nastaliq needs the taller line of urdu.body (16/30) at every size, and is never under urdu.caption's 14 sp (D4 2):
 * an Urdu title in an English list's 12 sp caption still reads.
 */
private fun TextStyle.nastaliqLine(): TextStyle {
    val ratio = EchoTokens.Type.urduBody.lineHeight.value / EchoTokens.Type.urduBody.fontSize.value
    val least = EchoTokens.Type.urduCaption.fontSize
    val size = if (fontSize.type == least.type && fontSize.value < least.value) least else fontSize
    return copy(fontSize = size, lineHeight = TextUnit(size.value * ratio, size.type))
}
