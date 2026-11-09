package com.echoledger.mobile.core

import com.echoledger.mobile.BuildConfig

/**
 * Settings (since v1.7, docs/DESIGN_SYSTEM.md "Settings"): the root's sections and pages, and every individual setting
 * on them for search. One source for the root, its pages and search, the same names as the desktop app and the console.
 * Pure; unit-tested (SettingsIndexTest).
 */
object SettingsIndex {
    /** A page the root opens: its rows are the [entries] the settings screen already draws (SettingsCatalogue ids). */
    data class Page(val id: String, val section: String, val title: String, val entries: List<String>, val full: Boolean = false)

    /** One setting on a page, what search matches and where a result goes ([entry] is highlighted on arrival). */
    data class Item(val id: String, val page: String, val entry: String, val title: String, val keywords: String)

    data class Hit(val item: Item, val page: Page, val score: Int)

    const val RECORDING = "Recording"
    const val TRANSCRIPTION = "Transcription"
    const val LIVE_TRANSLATE = "Live Translate"
    const val APP = "App"
    const val ACCOUNT = "Account"
    const val SUPPORT = "Support"

    /** Every page in root order; [full] pages need the website build's capture helper. */
    val pages: List<Page> = listOf(
        Page("calls", RECORDING, "Calls & meetings", listOf("auto-apps", "after-call"), full = true),
        Page("mic", RECORDING, "Microphone & audio", listOf("microphone")),
        Page("captions", RECORDING, "Captions", listOf("captions", "caption-style")),
        Page("voice", RECORDING, "Voice notes & dictation", listOf("after-share", "dictation")),
        Page("language", TRANSCRIPTION, "Language", listOf("transcription")),
        Page("words", TRANSCRIPTION, "Custom words", listOf("client")),
        Page("key", TRANSCRIPTION, "Transcription key", listOf("transcription-key")),
        Page("games", LIVE_TRANSLATE, "Games", listOf("game", "game-box", "game-more")),
        Page("videos", LIVE_TRANSLATE, "Videos", listOf("watch", "watch-style")),
        Page("appearance", APP, "Appearance", listOf("appearance")),
        Page("notifications", APP, "Notifications", listOf("notifications")),
        Page("lock", APP, "App lock", listOf("app-lock")),
        Page("storage", APP, "Storage", listOf("storage")),
        Page("plan", ACCOUNT, "Plan & usage", listOf("plan")),
        Page("account", ACCOUNT, "Sign-in & security", emptyList()),
        Page("devices", ACCOUNT, "Devices", listOf("devices")),
        Page("privacy", ACCOUNT, "Privacy & data", listOf("consent")),
        Page("help", SUPPORT, "Help", listOf("help")),
        Page("about", SUPPORT, "About", listOf("about", "this-phone"))
    ).filter { BuildConfig.CAPTURE_HELPER || !it.full }

    val sections: List<String> = listOf(RECORDING, TRANSCRIPTION, LIVE_TRANSLATE, APP, ACCOUNT, SUPPORT)

    fun page(id: String?): Page? = pages.firstOrNull { it.id == id }

    /** The page that draws [entry] (a SettingsCatalogue id). */
    fun pageOf(entry: String): Page? = pages.firstOrNull { entry in it.entries }

    private fun item(id: String, page: String, entry: String, title: String, keywords: String = "") = Item(id, page, entry, title, keywords)

    /** Every setting people can look for, with the words they might type (English and common Roman Urdu). */
    val items: List<Item> = listOf(
        item(
            "calls.auto",
            "calls",
            "auto-apps",
            "Auto-record apps",
            "whatsapp zoom teams meet telegram signal skype phone call record automatically khud record"
        ),
        item("calls.after", "calls", "after-call", "After a call", "keep discard ask before saving decision timeout wait undecided"),
        item("mic.source", "mic", "microphone", "Microphone source", "echo cancelled raw voice communication input"),
        item("mic.test", "mic", "microphone", "Test recording", "check try sample far side other person silent"),
        item("mic.permissions", "mic", "microphone", "Permissions and battery", "overlay battery optimisation background helper shizuku"),
        item("mic.callers", "mic", "microphone", "Caller names", "notification access contact name who called title"),
        item("captions.on", "captions", "captions", "Live captions", "bubble overlay floating subtitles during call"),
        item("captions.style", "captions", "caption-style", "Caption style", "size lines position opacity colour color compact hide"),
        item("voice.share", "voice", "after-share", "After sharing a voice note", "whatsapp voice note share open stay notification awaz"),
        item(
            "voice.dictation",
            "voice",
            "dictation",
            "Dictation",
            "voice keyboard push to talk hold tap clean up sounds replacements snippets history bol kar likho"
        ),
        item(
            "language.new",
            "language",
            "transcription",
            "Language of new recordings",
            "urdu roman urdu english arabic auto language zaban"
        ),
        item("language.vocabulary", "language", "transcription", "Add to vocabulary", "names terms jargon spelling words"),
        item("words.project", "words", "client", "Custom words", "project keyterms client names jargon accuracy"),
        item("key.groq", "key", "transcription-key", "Transcription key", "groq api key free own key transcribe"),
        item("games.list", "games", "game", "Games and languages", "pubg codm call of duty voice chat arabic english auto start translate"),
        item("games.box", "games", "game-box", "Box style", "box size lines opacity background original fade grip"),
        item("games.budget", "games", "game-more", "Monthly budget and privacy", "budget cost dollars month profanity save session text"),
        item("videos.apps", "videos", "watch", "Apps and languages", "youtube netflix chrome vlc films videos urdu subtitles translate"),
        item("videos.style", "videos", "watch-style", "Subtitle style", "subtitles size lines box offset original srt save"),
        item("appearance.theme", "appearance", "appearance", "Theme", "dark light system night mode black white andhera"),
        item("appearance.wallpaper", "appearance", "appearance", "Wallpaper colours", "dynamic colour color material you accent"),
        item("appearance.font", "appearance", "appearance", "Font", "typeface text size phone font"),
        item("notifications.activity", "notifications", "notifications", "Activity notifications", "alerts bell transcript ready notify"),
        item("notifications.quiet", "notifications", "notifications", "Quiet hours", "do not disturb night silent from to"),
        item("lock.app", "lock", "app-lock", "App lock", "fingerprint face unlock screen lock pin security biometric"),
        item("lock.hide", "lock", "app-lock", "Hide in recent apps", "screenshots recents secure privacy widget titles home screen"),
        item("storage.space", "storage", "storage", "Space used", "storage disk space full free up delete"),
        item("storage.kept", "storage", "storage", "How long recordings are kept", "retention deleted bin days"),
        item("plan.usage", "plan", "plan", "Plan & usage", "plan free pro ultra minutes limits devices storage usage"),
        item("plan.upgrade", "plan", "plan", "Upgrade", "upgrade pro ultra buy subscribe subscription price premium paid plan khareedna"),
        item(
            "plan.pay",
            "plan",
            "plan",
            "Payment and receipt",
            "pay payment bank transfer iban account number receipt screenshot proof transaction jazzcash easypaisa raast"
        ),
        item("plan.promo", "plan", "plan", "Promo code", "promo coupon discount code offer"),
        item("account.signin", "account", "", "Sign-in & security", "password email two step sign in login account security"),
        item("account.everywhere", "account", "", "Sign out everywhere", "log out all devices logout"),
        item("account.export", "account", "", "Export my data", "download data backup zip copy"),
        item("account.delete", "account", "", "Delete my account", "remove close account erase"),
        item("devices.list", "devices", "devices", "Devices", "phones computers signed in revoke remove rename"),
        item("privacy.consent", "privacy", "consent", "Recording consent", "consent law tell people participants permission privacy"),
        item("help.check", "help", "help", "Device check", "test diagnostics audio probe capture test support problem not working"),
        item("about.version", "about", "about", "Version and updates", "version update check app"),
        item("about.transfer", "about", "about", "Export and import settings", "backup restore settings file json move phone"),
        item("about.phone", "about", "this-phone", "This phone", "name server certificate setup guide technical")
    ).filter { i -> pages.any { it.id == i.page } }

    /** What a word may also mean in these settings ("night" finds Theme, "logout" finds Sign out). */
    private val synonyms: Map<String, List<String>> = mapOf(
        "night" to listOf("dark"),
        "logout" to listOf("sign out"),
        "log" to listOf("sign"),
        "signout" to listOf("sign out"),
        "subscription" to listOf("upgrade"),
        "premium" to listOf("upgrade"),
        "billing" to listOf("payment"),
        "price" to listOf("upgrade"),
        "proof" to listOf("receipt"),
        "biometric" to listOf("fingerprint"),
        "colour" to listOf("color"),
        "color" to listOf("colour"),
        "2fa" to listOf("two step"),
        "otp" to listOf("two step")
    )

    private fun words(text: String) = text.lowercase().split(Regex("[^a-z0-9&]+")).filter { it.isNotBlank() }

    private fun close(a: String, b: String): Boolean {
        if (kotlin.math.abs(a.length - b.length) > 1 || a.length < 5) return false
        // At most one letter different (an edit distance of 1), for "fingreprint"-sized slips only.
        var i = 0
        var j = 0
        var edits = 0
        while (i < a.length && j < b.length) {
            if (a[i] == b[j]) {
                i++
                j++
                continue
            }
            if (++edits > 1) return false
            when {
                a.length > b.length -> i++

                a.length < b.length -> j++

                else -> {
                    i++
                    j++
                }
            }
        }
        return edits + (a.length - i) + (b.length - j) <= 1
    }

    /** How well one query word fits [title] and [keywords]: 0 not at all. */
    private fun wordScore(q: String, title: List<String>, keywords: List<String>, page: List<String>): Int = when {
        title.any { it.startsWith(q) } -> if (title.firstOrNull()?.startsWith(q) == true) 40 else 30
        page.any { it.startsWith(q) } -> 20
        keywords.any { it == q } -> 18
        keywords.any { it.startsWith(q) } -> 15
        q.length >= 5 && (title + keywords).any { close(q, it) } -> 8
        else -> 0
    }

    /**
     * The settings matching every word of [query] (a word, its synonym, or a one-letter slip), best first: title
     * matches before keyword matches. Nothing for a blank query.
     */
    fun search(query: String, available: (Item) -> Boolean = { true }): List<Hit> {
        val q = words(query)
        if (q.isEmpty()) return emptyList()
        return items.filter(available).mapNotNull { item ->
            val page = page(item.page) ?: return@mapNotNull null
            val title = words(item.title)
            val keywords = words(item.keywords)
            val pageWords = words(page.title)
            var total = 0
            for (word in q) {
                val direct = wordScore(word, title, keywords, pageWords)
                val viaSynonym =
                    synonyms[word].orEmpty().maxOfOrNull { syn -> words(syn).minOf { wordScore(it, title, keywords, pageWords) } } ?: 0
                val best = maxOf(direct, viaSynonym)
                if (best == 0) return@mapNotNull null
                total += best
            }
            Hit(item, page, total)
        }.sortedWith(compareByDescending<Hit> { it.score }.thenBy { pages.indexOf(it.page) })
    }

    /** Where a result is: "App › Appearance" for Theme; a page's own row says only its section ("App"). */
    fun path(hit: Hit): String = if (hit.item.title == hit.page.title) hit.page.section else "${hit.page.section} › ${hit.page.title}"
}
