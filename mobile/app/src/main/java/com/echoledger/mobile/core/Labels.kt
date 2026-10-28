package com.echoledger.mobile.core

/** Shared vocabulary (see CONTRIBUTING.md). Labels are always shown together with an icon. */
enum class SourceDevice(val wire: String, val label: String) {
    PHONE("phone", "Phone"),
    DESKTOP("desktop", "Desktop"),
    WEB("web", "Web")
    ;

    companion object {
        fun from(wire: String?): SourceDevice = entries.firstOrNull { it.wire == wire } ?: WEB
    }
}

enum class SourceApp(val wire: String, val label: String) {
    WHATSAPP("whatsapp", "WhatsApp"),
    WHATSAPP_BUSINESS("whatsapp_business", "WhatsApp Business"),
    WHATSAPP_WEB("whatsapp_web", "WhatsApp Web"),
    GOOGLE_MEET("google_meet", "Google Meet"),
    ZOOM("zoom", "Zoom"),
    MICROSOFT_TEAMS("microsoft_teams", "Microsoft Teams"),
    PHONE_CALL("phone_call", "Phone call"),
    VOICE_NOTE("voice_note", "Voice note"),
    OTHER("other", "Other")
    ;

    companion object {
        fun from(wire: String?): SourceApp = entries.firstOrNull { it.wire == wire } ?: OTHER

        /** Package → app for call detection (WhatsApp, WhatsApp Business and SIM calls). */
        fun fromPackage(pkg: String?): SourceApp? = when (pkg) {
            CallTargets.WHATSAPP -> WHATSAPP

            CallTargets.WHATSAPP_BUSINESS -> WHATSAPP_BUSINESS

            // A SIM call (since v1.2): the helper's name for it, not a package.
            CallTargets.TELEPHONY -> PHONE_CALL

            else -> null
        }
    }
}

/** Channel 1 (left) = "Me" (local mic); channel 2 (right) = "Client" (far party). */
object Speakers {
    const val ME = "Me"
    const val CLIENT = "Client"
    const val SPEAKER = "Speaker"

    fun forChannel(channel: Int, stereo: Boolean = true): String = when {
        !stereo -> SPEAKER
        channel == 2 -> CLIENT
        else -> ME
    }
}
