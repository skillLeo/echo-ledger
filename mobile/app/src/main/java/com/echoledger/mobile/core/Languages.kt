package com.echoledger.mobile.core

/**
 * The transcription languages offered everywhere (docs/API.md "Languages"): a code, or null for automatic (the
 * client's or the console default). Mixed languages are written English first, like the server ("ur+en" is "en+ur").
 */
object Languages {
    data class Option(val code: String?, val label: String, val chip: String)

    val OPTIONS = listOf(
        Option(null, "Automatic", "Auto"),
        Option("en", "English", "EN"),
        Option("ur", "Urdu", "UR"),
        Option("en+ur", "Urdu + English", "UR+EN"),
        Option("hi", "Hindi", "HI"),
        Option("en+hi", "Hindi + English", "HI+EN")
    )

    /** One of the offered codes, else null (automatic). */
    fun canonical(code: String?): String? {
        val parts = code?.lowercase()?.split('+')?.map { it.trim() }?.filter { it.isNotEmpty() } ?: return null
        val joined = parts.sortedBy { it != "en" }.joinToString("+")
        return joined.takeIf { c -> OPTIONS.any { it.code == c } }
    }

    private fun option(code: String?) = OPTIONS.firstOrNull { it.code == canonical(code) } ?: OPTIONS[0]

    fun chip(code: String?): String = option(code).chip

    fun label(code: String?): String = option(code).label

    /** The next choice when a chip is tapped: Auto, EN, UR, UR+EN, HI, HI+EN, then Auto again. */
    fun next(code: String?): String? = OPTIONS[(OPTIONS.indexOf(option(code)) + 1) % OPTIONS.size].code

    /** "Use Urdu for Acme from now on?" only when the recording has a client that uses another language. */
    fun learnPrompt(clientName: String?, clientLanguage: String?, chosen: String?): String? {
        if (clientName.isNullOrBlank() || chosen == null || canonical(clientLanguage) == canonical(chosen)) return null
        return "Use ${label(chosen)} for $clientName from now on?"
    }
}
