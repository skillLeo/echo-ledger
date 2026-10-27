package com.echoledger.mobile.core

/**
 * The apps whose calls are recorded automatically: WhatsApp and WhatsApp Business. One list for call detection (the
 * helper's voice-call player lookup), labels and the capture test, so the two can never drift apart.
 */
object CallTargets {
    const val WHATSAPP = "com.whatsapp"
    const val WHATSAPP_BUSINESS = "com.whatsapp.w4b"
    val PACKAGES: Set<String> = setOf(WHATSAPP, WHATSAPP_BUSINESS)

    fun isTarget(packageName: String?): Boolean = packageName in PACKAGES

    /** The first target among the packages sharing a uid (a uid can hold several packages). */
    fun firstTarget(packages: Array<String>?): String? = packages?.firstOrNull { it in PACKAGES }
}
