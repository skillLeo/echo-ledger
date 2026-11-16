package com.echoledger.mobile.core

/**
 * Engine and AI vendor names, which the phone never shows (since v1.8.1, bug P-001; contract 1.8.1-a): people read what
 * happens in plain words. The Free plan's "Add your free Groq key" step is the one place a vendor is named, because the
 * person must act on it there.
 */
object VendorNames {
    private val NAMES = Regex(
        "(?i)\\b(gemini|assembly ?ai|groq|eleven ?labs|open ?ai|anthropic|claude|whisper|soniox|deepgram|speechmatics|" +
            "chat ?gpt|gpt-?\\d\\S*|sonnet|haiku|llama|mistral|cohere|azure|parakeet|stt-rt\\S*|nova-\\d)\\b"
    )

    /** Whether [text] names an engine, an AI vendor or one of their models. */
    fun mentions(text: String?): Boolean = text != null && NAMES.containsMatchIn(text)

    /** [text] from the server when it is there and names no engine or vendor, else [plain]. */
    fun plain(text: String?, plain: String): String = text?.takeIf { it.isNotBlank() && !mentions(it) } ?: plain
}
