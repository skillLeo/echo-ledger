package com.echoledger.mobile.core

/** The groups of the Settings tab, named and ordered like the desktop app and the console (B19). */
enum class SettingsGroup(val label: String, val summary: String) {
    General("General", "Appearance, language, client, notifications"),
    Recording("Recording", "After a call, auto-record, microphone"),
    Captions("Captions", "Live captions and how they look"),
    Dictation("Dictation", "Voice keyboard, words and snippets"),
    Privacy("Privacy & security", "App lock"),
    Devices("Devices", "Paired devices, this phone"),
    Storage("Storage", "Server storage and retention"),
    About("About", "Version, export and import settings")
}

/** One setting (or a card of related settings) on a group screen; [keywords] are what search also matches. */
data class SettingsEntry(val id: String, val group: SettingsGroup, val title: String, val keywords: String)

object SettingsCatalogue {
    val entries: List<SettingsEntry> = listOf(
        SettingsEntry("appearance", SettingsGroup.General, "Appearance", "theme dark light system colours wallpaper dynamic"),
        SettingsEntry("transcription", SettingsGroup.General, "Transcription", "language english urdu hindi vocabulary terms"),
        SettingsEntry("client", SettingsGroup.General, "Client keyterms", "client names jargon accuracy"),
        SettingsEntry("notifications", SettingsGroup.General, "Notifications", "quiet hours bell activity alerts"),
        SettingsEntry("consent", SettingsGroup.General, "Privacy & consent", "consent law participants understand"),
        SettingsEntry(
            "after-call",
            SettingsGroup.Recording,
            "After a call",
            "keep discard wait decision timeout 60 seconds ask before saving"
        ),
        SettingsEntry("auto-apps", SettingsGroup.Recording, "Auto-record apps", "whatsapp zoom teams meet calls apps"),
        SettingsEntry(
            "microphone",
            SettingsGroup.Recording,
            "Microphone",
            "source echo cancelled raw test recording permissions overlay battery helper silent far side caller names notification access"
        ),
        SettingsEntry("captions", SettingsGroup.Captions, "Captions", "live bubble overlay floating"),
        SettingsEntry("caption-style", SettingsGroup.Captions, "Caption style", "size lines position opacity colour compact hide"),
        SettingsEntry(
            "dictation",
            SettingsGroup.Dictation,
            "Dictation",
            "voice keyboard push to talk hold clean up sounds replacements snippets history"
        ),
        SettingsEntry(
            "app-lock",
            SettingsGroup.Privacy,
            "App lock",
            "lock fingerprint face screen lock recents screenshots hide content security"
        ),
        SettingsEntry("devices", SettingsGroup.Devices, "Devices", "revoke paired activity audit security"),
        SettingsEntry("this-phone", SettingsGroup.Devices, "This phone", "unpair name server certificate pinned setup guide"),
        SettingsEntry("storage", SettingsGroup.Storage, "Storage and retention", "storage disk space retention deleted bin days"),
        SettingsEntry("about", SettingsGroup.About, "About", "version server export import backup settings file json")
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
