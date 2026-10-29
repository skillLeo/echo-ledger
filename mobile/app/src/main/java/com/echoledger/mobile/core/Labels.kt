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
    TELEGRAM("telegram", "Telegram"),
    SIGNAL("signal", "Signal"),
    MESSENGER("messenger", "Messenger"),
    INSTAGRAM("instagram", "Instagram"),
    SKYPE("skype", "Skype"),
    SLACK("slack", "Slack"),
    DISCORD("discord", "Discord"),
    PHONE_CALL("phone_call", "Phone call"),
    VOICE_NOTE("voice_note", "Voice note"),
    OTHER("other", "Other")
    ;

    companion object {
        fun from(wire: String?): SourceApp = entries.firstOrNull { it.wire == wire } ?: OTHER

        /**
         * Package → app for call detection: the known call apps, SIM calls, and OTHER for a package the person added to
         * the call-app list themselves; null for nothing.
         */
        fun fromPackage(pkg: String?): SourceApp? {
            if (pkg.isNullOrEmpty()) return null
            // A SIM call (since v1.2): the helper's name for it, not a package.
            if (pkg == CallTargets.TELEPHONY) return PHONE_CALL
            return CallTargets.KNOWN[pkg] ?: OTHER
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
