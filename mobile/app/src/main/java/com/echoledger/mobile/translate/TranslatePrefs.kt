package com.echoledger.mobile.translate

import kotlinx.serialization.Serializable

/** The two Live Translate modes (plan B3/B4): game voice chat, or a film or video. */
enum class TranslateMode(val key: String, val label: String) {
    GAME("game", "Game translate"),
    WATCH("watch", "Watch translate");

    companion object {
        fun of(key: String?): TranslateMode? = entries.firstOrNull { it.key == key }
    }
}

/** A language Live Translate can hear or write. */
data class TranslateLanguage(val code: String, val label: String)

object TranslateLanguages {
    /** Soniox's real-time model hears and translates all of these; the first three are the ones that matter here. */
    val ALL = listOf(
        TranslateLanguage("en", "English"),
        TranslateLanguage("ur", "Urdu"),
        TranslateLanguage("ar", "Arabic"),
        TranslateLanguage("hi", "Hindi"),
        TranslateLanguage("fa", "Persian"),
        TranslateLanguage("tr", "Turkish"),
        TranslateLanguage("ru", "Russian"),
        TranslateLanguage("es", "Spanish"),
        TranslateLanguage("fr", "French"),
        TranslateLanguage("de", "German"),
        TranslateLanguage("pt", "Portuguese"),
        TranslateLanguage("id", "Indonesian"),
        TranslateLanguage("ja", "Japanese"),
        TranslateLanguage("ko", "Korean"),
        TranslateLanguage("zh", "Chinese")
    )

    /** What the box writes in: the plan's three, then the rest. */
    val TARGETS = ALL.filter { it.code in setOf("en", "ur", "ar") } + ALL.filterNot { it.code in setOf("en", "ur", "ar", "hi") }

    fun label(code: String?): String = ALL.firstOrNull { it.code == code }?.label ?: (code ?: "Any language")

    /** "Arabic + English", or "Any language" for no hints. */
    fun spoken(codes: List<String>): String = if (codes.isEmpty()) "Any language" else codes.joinToString(" + ") { label(it) }

    /** Right-to-left scripts: the box and the subtitles align and shape these from the right. */
    fun rtl(code: String?): Boolean = code in setOf("ar", "ur", "fa", "he")
}

/** Background of the game box (plan B2): dark (black 72 %), light (white 85 %) or none (outlined text). */
enum class BoxBackground(val label: String) { DARK("Dark"), LIGHT("Light"), NONE("None") }

/** How the floating box looks; one per game, falling back to the mode's default. */
@Serializable
data class BoxStyle(
    val textSp: Int = 16,
    val lines: Int = 2,
    val background: String = BoxBackground.DARK.name,
    /** The whole window's opacity, 0.3-0.8: Android 12+ lets a touch pass through an app's overlay only up to 0.8. */
    val opacity: Float = 0.8f,
    val showOriginal: Boolean = false,
    val fadeAfterSeconds: Int = 6
) {
    fun sanitized(): BoxStyle = copy(
        textSp = textSp.coerceIn(MIN_TEXT_SP, MAX_TEXT_SP),
        lines = lines.coerceIn(1, 4),
        background = BoxBackground.entries.firstOrNull { it.name == background }?.name ?: BoxBackground.DARK.name,
        opacity = opacity.coerceIn(MIN_OPACITY, MAX_OPACITY),
        fadeAfterSeconds = fadeAfterSeconds.coerceIn(0, 60)
    )

    val backgroundKind: BoxBackground get() = BoxBackground.entries.firstOrNull { it.name == background } ?: BoxBackground.DARK

    companion object {
        const val MIN_TEXT_SP = 12
        const val MAX_TEXT_SP = 28
        const val MIN_OPACITY = 0.3f

        /** InputManager's maximum obscuring opacity: above it Android blocks touches under the window (untrusted touch). */
        const val MAX_OPACITY = 0.8f
    }
}

/** Subtitles of Watch translate (plan B4): bottom centre, white with an outline, no box unless chosen. */
@Serializable
data class SubtitleStyle(
    val textSp: Int = 20,
    val lines: Int = 2,
    val box: Boolean = false,
    /** How far above the bottom the subtitles sit, in dp, so they clear the player's own controls. */
    val offsetDp: Int = 96,
    val showOriginal: Boolean = false
) {
    fun sanitized(): SubtitleStyle =
        copy(textSp = textSp.coerceIn(14, 32), lines = lines.coerceIn(1, 3), offsetDp = offsetDp.coerceIn(0, 320))
}

/** Where a box sits for one app in one orientation: its top-left corner and size as fractions of the screen. */
@Serializable
data class BoxPlacement(val x: Float, val y: Float, val width: Float, val height: Float)

/** Game translate (plan B3): picked games, what is spoken and what to show, and the box. */
@Serializable
data class GamePrefs(
    val games: List<String> = emptyList(),
    val autoStart: Boolean = true,
    val spoken: List<String> = listOf("ar", "en"),
    val target: String = "en",
    val speed: String = SpeedPreset.FASTEST.name,
    val style: BoxStyle = BoxStyle(),
    /** Per game package: its own style (falls back to [style]). */
    val perGame: Map<String, BoxStyle> = emptyMap(),
    val profanityMask: Boolean = false,
    val saveText: Boolean = false
)

/** Watch translate (plan B4): which apps, the subtitles' language and look. */
@Serializable
data class WatchPrefs(
    /** Empty = whatever is playing. */
    val apps: List<String> = emptyList(),
    val spoken: List<String> = emptyList(),
    val target: String = "ur",
    val speed: String = SpeedPreset.BALANCED.name,
    val style: SubtitleStyle = SubtitleStyle(),
    val saveSrt: Boolean = false
)

/** Everything Live Translate keeps on this phone. Nothing here is a secret; no audio or text is ever stored here. */
@Serializable
data class LiveTranslatePrefs(
    val game: GamePrefs = GamePrefs(),
    val watch: WatchPrefs = WatchPrefs(),
    /** Monthly spending cap for Live Translate in US dollars (plan B1): warn at 80 %, pause at 100 %. */
    val monthlyBudgetUsd: Double = 10.0,
    /** "yyyy-MM" of a month in which the person chose to go past the budget. */
    val budgetOverrideMonth: String? = null,
    /** The small dot that opens the box's controls; hidden, the notification and tiles still control the box. */
    val showGrip: Boolean = true,
    /** Latency, data rate and reconnects shown in the box (off by default). */
    val debugHud: Boolean = false,
    /** Box positions per app and orientation ("<package>|land" / "<package>|port"). */
    val placements: Map<String, BoxPlacement> = emptyMap()
)
