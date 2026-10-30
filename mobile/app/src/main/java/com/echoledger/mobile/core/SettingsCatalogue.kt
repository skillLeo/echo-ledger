package com.echoledger.mobile.core

import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.CaptionStyle
import com.echoledger.mobile.translate.GamePrefs
import com.echoledger.mobile.translate.LiveTranslatePrefs
import com.echoledger.mobile.translate.WatchPrefs

/**
 * The panels of You (since v1.3, plan B5/C7): General, then one per mode of the Record sheet. The console and the
 * desktop app use the same names.
 */
enum class SettingsGroup(val label: String, val summary: String) {
    General("General", "This phone, look, lock, privacy, notifications, help"),
    VoiceNotes("Voice notes & files", "Sharing voice notes, the voice keyboard"),
    Calls("Calls & meetings", "Call apps, after a call, captions, microphone"),
    Game("Game translate", "Games, languages, the box, monthly budget"),
    Watch("Watch translate", "Apps, languages, subtitles")
}

/** One setting (or a card of related settings) on a group screen; [keywords] are what search also matches. */
data class SettingsEntry(val id: String, val group: SettingsGroup, val title: String, val keywords: String)

object SettingsCatalogue {
    val entries: List<SettingsEntry> = listOf(
        SettingsEntry("appearance", SettingsGroup.General, "Appearance", "theme dark light system colours wallpaper dynamic font typeface"),
        SettingsEntry("this-phone", SettingsGroup.General, "This phone", "account server unpair name certificate pinned setup guide"),
        SettingsEntry("transcription", SettingsGroup.General, "Transcription", "language english urdu arabic roman view vocabulary terms"),
        SettingsEntry("client", SettingsGroup.General, "Client keyterms", "client names jargon accuracy"),
        SettingsEntry("notifications", SettingsGroup.General, "Notifications", "quiet hours bell activity alerts"),
        SettingsEntry(
            "app-lock",
            SettingsGroup.General,
            "App lock",
            "lock fingerprint face screen lock recents screenshots hide content security"
        ),
        SettingsEntry("consent", SettingsGroup.General, "Privacy & consent", "consent law participants understand"),
        SettingsEntry("devices", SettingsGroup.General, "Devices", "revoke paired activity audit security"),
        SettingsEntry("storage", SettingsGroup.General, "Storage and retention", "storage disk space retention deleted bin days"),
        SettingsEntry(
            "help",
            SettingsGroup.General,
            "Help & diagnostics",
            "audio probe players capture test diagnostics copy support helper"
        ),
        SettingsEntry("about", SettingsGroup.General, "About", "version server export import backup settings file json"),
        SettingsEntry("after-share", SettingsGroup.VoiceNotes, "After sharing", "share whatsapp voice note file open stay notification"),
        SettingsEntry(
            "dictation",
            SettingsGroup.VoiceNotes,
            "Dictation",
            "voice keyboard push to talk hold clean up sounds replacements snippets history"
        ),
        SettingsEntry("auto-apps", SettingsGroup.Calls, "Auto-record apps", "whatsapp zoom teams meet calls apps"),
        SettingsEntry(
            "after-call",
            SettingsGroup.Calls,
            "After a call",
            "keep discard wait decision timeout 60 seconds ask before saving"
        ),
        SettingsEntry(
            "microphone",
            SettingsGroup.Calls,
            "Microphone",
            "source echo cancelled raw test recording permissions overlay battery helper silent far side caller names notification access"
        ),
        SettingsEntry("captions", SettingsGroup.Calls, "Captions", "live bubble overlay floating"),
        SettingsEntry("caption-style", SettingsGroup.Calls, "Caption style", "captions size lines position opacity colour compact hide"),
        SettingsEntry("game", SettingsGroup.Game, "Games and languages", "pubg codm call of duty voice chat arabic english auto start"),
        SettingsEntry("game-box", SettingsGroup.Game, "Box style", "box size lines opacity background original fade grip"),
        SettingsEntry("game-more", SettingsGroup.Game, "Privacy and budget", "budget cost dollars month profanity save session text debug"),
        SettingsEntry("watch", SettingsGroup.Watch, "Apps and languages", "youtube netflix chrome vlc films videos urdu subtitles"),
        SettingsEntry("watch-style", SettingsGroup.Watch, "Subtitle style", "subtitles size lines box offset original srt save")
    )

    fun inGroup(group: SettingsGroup): List<SettingsEntry> = entries.filter { it.group == group }

    /** The entries whose name, group or keywords contain every word of [query]; nothing for a blank query. */
    fun search(query: String): List<SettingsEntry> {
        val words = query.lowercase().split(' ', '\t').filter { it.isNotBlank() }
        if (words.isEmpty()) return emptyList()
        return entries.filter { entry ->
            val haystack = "${entry.title} ${entry.keywords} ${entry.group.label}".lowercase()
            words.all { haystack.contains(it) }
        }
    }
}

/**
 * "Reset this panel" (plan B5): the panel's choices go back to their defaults. What the person made or picked stays:
 * pairing, auto-record on or off, replacements and snippets, added call apps, the picked games and video apps.
 */
object SettingsReset {
    fun reset(group: SettingsGroup, s: AppSettings): AppSettings {
        val d = AppSettings()
        return when (group) {
            SettingsGroup.General -> s.copy(
                themeMode = d.themeMode,
                dynamicColour = d.dynamicColour,
                phoneFont = d.phoneFont,
                appLockEnabled = d.appLockEnabled,
                lockAfterMs = d.lockAfterMs,
                secureScreen = d.secureScreen,
                activityNotifications = d.activityNotifications,
                quietHours = d.quietHours,
                quietFrom = d.quietFrom,
                quietTo = d.quietTo,
                transcriptionLanguage = d.transcriptionLanguage
            )

            SettingsGroup.VoiceNotes -> s.copy(
                afterShare = d.afterShare,
                dictationMode = d.dictationMode,
                dictationCleanup = d.dictationCleanup,
                dictationSounds = d.dictationSounds
            )

            SettingsGroup.Calls -> s.copy(
                autoRecordApps = d.autoRecordApps,
                decisionTimeout = d.decisionTimeout,
                decisionTimeoutChosen = d.decisionTimeoutChosen,
                captionsEnabled = d.captionsEnabled,
                captionsDuringCalls = d.captionsDuringCalls,
                captionStyle = CaptionStyle(),
                pillEdge = d.pillEdge,
                pillY = d.pillY,
                pillDot = d.pillDot,
                recordingKbps = d.recordingKbps,
                phoneCalls = d.phoneCalls,
                micSource = d.micSource,
                inputDevice = d.inputDevice,
                farSideWarning = d.farSideWarning
            )

            SettingsGroup.Game -> s.copy(
                liveTranslate = s.liveTranslate.copy(
                    game = GamePrefs(games = s.liveTranslate.game.games),
                    monthlyBudgetUsd = LiveTranslatePrefs().monthlyBudgetUsd,
                    showGrip = LiveTranslatePrefs().showGrip,
                    debugHud = LiveTranslatePrefs().debugHud
                )
            )

            SettingsGroup.Watch -> s.copy(liveTranslate = s.liveTranslate.copy(watch = WatchPrefs(apps = s.liveTranslate.watch.apps)))
        }
    }
}
