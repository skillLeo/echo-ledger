package com.echoledger.mobile.core

/**
 * The transcription languages offered everywhere (docs/API.md "Languages", since v1.2): Auto, English, Urdu and
 * Arabic first, then the More languages. A code, or null for Auto (English, Urdu or Arabic; the client's or the
 * console default). Urdu and Arabic always allow English words inside; Hindi and the mixed choices are gone, and
 * older values read as today ("en+ur" and "hi" are Urdu), like the server.
 */
object Languages {
    data class Option(val code: String?, val label: String, val chip: String, val more: Boolean = false)

    val OPTIONS = listOf(
        Option(null, "Auto", "Auto"),
        Option("en", "English", "EN"),
        Option("ur", "Urdu", "UR"),
        Option("ar", "Arabic", "AR"),
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
