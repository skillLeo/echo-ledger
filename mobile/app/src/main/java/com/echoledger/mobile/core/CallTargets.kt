package com.echoledger.mobile.core

/**
 * The apps whose voice calls are calls (since v1.3 an editable list): the helper starts the call recorder only when a
 * voice-call player (USAGE_VOICE_COMMUNICATION) belongs to one of them, so games and other apps never start it. One
 * list for call detection, labels, the auto-record switches and the capture test, so they can never drift apart.
 */
object CallTargets {
    const val WHATSAPP = "com.whatsapp"
    const val WHATSAPP_BUSINESS = "com.whatsapp.w4b"

    /** The known call apps: package → the source it is recorded as. */
    val KNOWN: Map<String, SourceApp> = linkedMapOf(
        WHATSAPP to SourceApp.WHATSAPP,
        WHATSAPP_BUSINESS to SourceApp.WHATSAPP_BUSINESS,
        "com.google.android.apps.tachyon" to SourceApp.GOOGLE_MEET,
        "com.google.android.apps.meetings" to SourceApp.GOOGLE_MEET,
        "us.zoom.videomeetings" to SourceApp.ZOOM,
        "com.microsoft.teams" to SourceApp.MICROSOFT_TEAMS,
        "org.telegram.messenger" to SourceApp.TELEGRAM,
        "org.telegram.messenger.web" to SourceApp.TELEGRAM,
        "org.thoughtcrime.securesms" to SourceApp.SIGNAL,
        "com.facebook.orca" to SourceApp.MESSENGER,
        "com.instagram.android" to SourceApp.INSTAGRAM,
        "com.skype.raider" to SourceApp.SKYPE,
        "com.Slack" to SourceApp.SLACK,
        "com.discord" to SourceApp.DISCORD
    )

    /** Every known call app's package (the default list). */
    val DEFAULT_PACKAGES: Set<String> = KNOWN.keys

    /** What the helper reports for a SIM phone call (since v1.2; not a package, never in [KNOWN]). */
    const val TELEPHONY = "phone_call"

    /** A package name as Android writes it (letters, digits, underscores, dots; at least two parts). */
    private val PACKAGE = Regex("^[A-Za-z][A-Za-z0-9_]*(\\.[A-Za-z0-9_]+)+$")

    /** The packages the helper watches: the known apps plus the person's own additions (cleaned, unique). */
    fun packages(extra: Collection<String>): Set<String> = DEFAULT_PACKAGES + extra.mapNotNull(::clean)

    /** [value] as a package name, or null when it is not one. */
    fun clean(value: String?): String? = value?.trim()?.takeIf { it.length <= 120 && PACKAGE.matches(it) }

    fun isTarget(packageName: String?, list: Collection<String> = DEFAULT_PACKAGES): Boolean = packageName in list

    /** The first call app among the packages sharing a uid (a uid can hold several packages). */
    fun firstTarget(packages: Array<String>?, list: Collection<String> = DEFAULT_PACKAGES): String? = packages?.firstOrNull { it in list }
}
