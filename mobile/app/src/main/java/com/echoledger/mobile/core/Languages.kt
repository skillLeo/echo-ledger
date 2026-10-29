package com.echoledger.mobile.core

/**
 * The transcription languages offered everywhere (docs/API.md "Languages", since v1.2): Auto, English, Urdu and
 * Arabic first, then the More languages. A code, or null for Auto (English, Urdu or Arabic; the client's or the
 * console default). Urdu and Arabic always allow English words inside; Hindi and the mixed choices are gone, and
 * older values read as today ("en+ur" and "hi" are Urdu), like the server.
 */
object Languages {
    /** [label] is the name inside a sentence ("Re-transcribing in Urdu"); [menu] says what the choice does (since v1.3). */
    data class Option(val code: String?, val label: String, val chip: String, val more: Boolean = false, val menu: String = label)

    val OPTIONS = listOf(
        Option(null, "Auto", "Auto", menu = "Auto (English / Urdu / Arabic)"),
        Option("en", "English", "EN", menu = "English only"),
        Option("ur", "Urdu", "UR", menu = "Urdu (with English words)"),
        Option("ar", "Arabic", "AR", menu = "Arabic (with English words)"),
        Option("fr", "French", "FR", more = true),
        Option("de", "German", "DE", more = true),
        Option("es", "Spanish", "ES", more = true),
        Option("it", "Italian", "IT", more = true),
        Option("pt", "Portuguese", "PT", more = true),
        Option("nl", "Dutch", "NL", more = true),
        Option("tr", "Turkish", "TR", more = true),
        Option("ru", "Russian", "RU", more = true)
    )

    /** The main choices (the chip on the popup steps through these). */
    val MAIN = OPTIONS.filterNot { it.more }

    /** "More languages". */
    val MORE = OPTIONS.filter { it.more }

    private val ALIASES = mapOf("hi" to "ur", "hin" to "ur", "hindi" to "ur", "urd" to "ur", "urdu" to "ur", "eng" to "en", "ara" to "ar")

    /** One of the offered codes, else null (Auto): a mixed value keeps its non-English language, Hindi is Urdu. */
    fun canonical(code: String?): String? {
        val codes = code?.lowercase()?.split('+')
            ?.map { it.trim().split('-', '_').first() }
            ?.map { ALIASES[it] ?: it }
            ?.filter { c -> OPTIONS.any { it.code == c } }
            ?: return null
        return codes.firstOrNull { it != "en" } ?: codes.firstOrNull()
    }

    private fun option(code: String?) = OPTIONS.firstOrNull { it.code == canonical(code) } ?: OPTIONS[0]

    fun chip(code: String?): String = option(code).chip

    fun label(code: String?): String = option(code).label

    /** "Urdu (with English words)", "English only": what a choice does, for menus and under the chips. */
    fun menu(code: String?): String = option(code).menu

    private val ROMAN_URDU = setOf(
        "hai", "hain", "nahi", "nahin", "kya", "kyun", "kaise", "theek", "acha", "achha", "haan", "yaar", "abhi", "aap",
        "mujhe", "raha", "rahe", "karo", "bohat", "bahut", "kuch", "lekin", "matlab"
    )

    /**
     * "English only" would make the engine translate Urdu speech into English: true when the recording was heard as
     * Urdu (or Arabic), or its text has Urdu letters or Roman Urdu words, so the person is warned once.
     */
    fun englishOnlyRisk(heard: String?, sample: String?): Boolean {
        val code = canonical(heard)
        if (code == "ur" || code == "ar") return true
        val text = sample.orEmpty()
        if (text.any { it in '؀'..'ۿ' || it in 'ऀ'..'ॿ' }) return true
        return text.lowercase().split(Regex("[^a-z]+")).filter { it in ROMAN_URDU }.toSet().size >= 2
    }

    /** The next main choice when a chip is tapped: Auto, EN, UR, AR, then Auto again; a More language steps to Auto. */
    fun next(code: String?): String? {
        val at = MAIN.indexOf(option(code))
        return if (at < 0) null else MAIN[(at + 1) % MAIN.size].code
    }

    /** "Use Urdu for Acme from now on?" only when the recording has a client that uses another language. */
    fun learnPrompt(clientName: String?, clientLanguage: String?, chosen: String?): String? {
        if (clientName.isNullOrBlank() || chosen == null || canonical(clientLanguage) == canonical(chosen)) return null
        return "Use ${label(chosen)} for $clientName from now on?"
    }
}
