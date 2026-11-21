package com.echoledger.mobile.ui.v2.settings

import androidx.compose.ui.graphics.vector.ImageVector
import com.echoledger.mobile.R
import com.echoledger.mobile.core.SettingsIndex
import com.echoledger.mobile.ui.v2.components.EchoIcons

/** A goal of Settings (docs/v2/IA.md 5.1–5.9): the same groups on every platform, each with its icon and one line. */
enum class SettingsGoal(val title: Int, val description: Int, val icon: ImageVector) {
    Recording(R.string.v2_set_group_recording, R.string.v2_set_desc_recording, EchoIcons.Mic),
    Transcription(R.string.v2_set_group_transcription, R.string.v2_set_desc_transcription, EchoIcons.Transcribe),
    Live(R.string.v2_set_group_live, R.string.v2_set_desc_live, EchoIcons.Live),
    Privacy(R.string.v2_set_group_privacy, R.string.v2_set_desc_privacy, EchoIcons.Privacy),
    Notifications(R.string.v2_set_group_notifications, R.string.v2_set_desc_notifications, EchoIcons.Bell),
    Appearance(R.string.v2_set_group_appearance, R.string.v2_set_desc_appearance, EchoIcons.Palette),
    Account(R.string.v2_set_group_account, R.string.v2_set_desc_account, EchoIcons.Account),
    Setup(R.string.v2_set_group_setup, R.string.v2_set_desc_setup, EchoIcons.Health),
    About(R.string.v2_set_group_about, R.string.v2_set_desc_about, EchoIcons.Info)
}

/**
 * Settings v2's tree (behind `ui_v2.settings`): the goals in IA order, each with its pages. A page is the one home of
 * its settings: every page of [SettingsIndex] belongs to exactly one goal, and every setting to one page (the index's).
 * "setup" is v2's Setup & health; the others are the index's pages.
 */
object SettingsTree {
    const val SETUP = "setup"

    val goals: List<Pair<SettingsGoal, List<String>>> = listOf(
        SettingsGoal.Recording to listOf("calls", "mic", "voice"),
        SettingsGoal.Transcription to listOf("language", "words", "key"),
        SettingsGoal.Live to listOf("captions", "games", "videos"),
        SettingsGoal.Privacy to listOf("lock", "privacy", "storage"),
        SettingsGoal.Notifications to listOf("notifications"),
        SettingsGoal.Appearance to listOf("appearance"),
        SettingsGoal.Account to listOf("plan", "account", "devices"),
        SettingsGoal.Setup to listOf(SETUP),
        SettingsGoal.About to listOf("help", "about")
    )

    /** Pages drawn with the v2 components; the rest open their classic page with the same settings and actions. */
    val nativePages = setOf("appearance", "notifications", SETUP, "calls", "language", "words", "key", "lock", "privacy", "storage")

    /** Settings v2 leaves out nothing of classic's (wallpaper colours came back, docs/PLAN_V2_PARITY.md section 5). */
    val hiddenItems = emptySet<String>()

    /** Each page's icon on its row (as classic had one per page). */
    val pageIcons: Map<String, ImageVector> = mapOf(
        "calls" to EchoIcons.PhoneCall,
        "mic" to EchoIcons.Mic,
        "voice" to EchoIcons.VoiceNote,
        "language" to EchoIcons.Language,
        "words" to EchoIcons.Words,
        "key" to EchoIcons.Key,
        "captions" to EchoIcons.Captions,
        "games" to EchoIcons.Games,
        "videos" to EchoIcons.Videos,
        "lock" to EchoIcons.Lock,
        "privacy" to EchoIcons.Privacy,
        "storage" to EchoIcons.Storage,
        "notifications" to EchoIcons.Bell,
        "appearance" to EchoIcons.Palette,
        "plan" to EchoIcons.Plan,
        "account" to EchoIcons.Account,
        "devices" to EchoIcons.Devices,
        SETUP to EchoIcons.Health,
        "help" to EchoIcons.Help,
        "about" to EchoIcons.Info
    )

    /** v2's own settings, on its own pages. */
    val extraItems: List<SettingsIndex.Item> = listOf(
        SettingsIndex.Item("setup.helper", SETUP, "", "Capture helper", "shizuku adb wireless debugging pairing helper capture both sides"),
        SettingsIndex.Item(
            "setup.checks",
            SETUP,
            "",
            "Health checks",
            "health check permissions microphone notifications battery overlay ready"
        ),
        SettingsIndex.Item("appearance.textsize", "appearance", "", "Text size", "font size bigger smaller large text zoom read"),
        SettingsIndex.Item("appearance.language", "appearance", "", "App language", "language urdu english zaban"),
        SettingsIndex.Item("appearance.swipes", "appearance", "", "Swipe actions", "swipe gesture library pin delete row slide")
    )

    fun goalOf(page: String): SettingsGoal? = goals.firstOrNull { page in it.second }?.first

    val pageTitles: Map<String, Int> = mapOf(
        "calls" to R.string.v2_set_page_calls,
        "mic" to R.string.v2_set_page_mic,
        "captions" to R.string.v2_set_page_captions,
        "voice" to R.string.v2_set_page_voice,
        "language" to R.string.v2_set_page_language,
        "words" to R.string.v2_set_page_words,
        "key" to R.string.v2_set_page_key,
        "games" to R.string.v2_set_page_games,
        "videos" to R.string.v2_set_page_videos,
        "appearance" to R.string.v2_set_page_appearance,
        "notifications" to R.string.v2_set_page_notifications,
        "lock" to R.string.v2_set_page_lock,
        "storage" to R.string.v2_set_page_storage,
        "plan" to R.string.v2_set_page_plan,
        "account" to R.string.v2_set_page_account,
        "devices" to R.string.v2_set_page_devices,
        "privacy" to R.string.v2_set_page_privacy,
        "help" to R.string.v2_set_page_help,
        "about" to R.string.v2_set_page_about,
        "setup" to R.string.v2_set_page_setup
    )

    val itemTitles: Map<String, Int> = mapOf(
        "calls.autorecord" to R.string.v2_set_item_calls_autorecord,
        "calls.auto" to R.string.v2_set_item_calls_auto,
        "calls.after" to R.string.v2_set_item_calls_after,
        "mic.source" to R.string.v2_set_item_mic_source,
        "mic.test" to R.string.v2_set_item_mic_test,
        "mic.permissions" to R.string.v2_set_item_mic_permissions,
        "mic.callers" to R.string.v2_set_item_mic_callers,
        "captions.on" to R.string.v2_set_item_captions_on,
        "captions.style" to R.string.v2_set_item_captions_style,
        "voice.share" to R.string.v2_set_item_voice_share,
        "voice.dictation" to R.string.v2_set_item_voice_dictation,
        "language.new" to R.string.v2_set_item_language_new,
        "language.vocabulary" to R.string.v2_set_item_language_vocabulary,
        "words.project" to R.string.v2_set_item_words_project,
        "key.groq" to R.string.v2_set_item_key_groq,
        "games.list" to R.string.v2_set_item_games_list,
        "games.box" to R.string.v2_set_item_games_box,
        "games.budget" to R.string.v2_set_item_games_budget,
        "videos.apps" to R.string.v2_set_item_videos_apps,
        "videos.style" to R.string.v2_set_item_videos_style,
        "appearance.theme" to R.string.v2_set_item_appearance_theme,
        "appearance.wallpaper" to R.string.v2_set_item_appearance_wallpaper,
        "appearance.font" to R.string.v2_set_item_appearance_font,
        "appearance.textsize" to R.string.v2_set_item_appearance_textsize,
        "appearance.language" to R.string.v2_set_item_appearance_language,
        "appearance.swipes" to R.string.v2_set_swipes,
        "notifications.activity" to R.string.v2_set_item_notifications_activity,
        "notifications.quiet" to R.string.v2_set_item_notifications_quiet,
        "lock.app" to R.string.v2_set_item_lock_app,
        "lock.hide" to R.string.v2_set_item_lock_hide,
        "privacy.share" to R.string.v2_set_item_privacy_share,
        "storage.space" to R.string.v2_set_item_storage_space,
        "storage.kept" to R.string.v2_set_item_storage_kept,
        "plan.usage" to R.string.v2_set_item_plan_usage,
        "plan.upgrade" to R.string.v2_set_item_plan_upgrade,
        "plan.pay" to R.string.v2_set_item_plan_pay,
        "plan.promo" to R.string.v2_set_item_plan_promo,
        "account.signin" to R.string.v2_set_item_account_signin,
        "account.everywhere" to R.string.v2_set_item_account_everywhere,
        "account.export" to R.string.v2_set_item_account_export,
        "account.delete" to R.string.v2_set_item_account_delete,
        "devices.list" to R.string.v2_set_item_devices_list,
        "privacy.consent" to R.string.v2_set_item_privacy_consent,
        "help.check" to R.string.v2_set_item_help_check,
        "about.version" to R.string.v2_set_item_about_version,
        "about.transfer" to R.string.v2_set_item_about_transfer,
        "about.phone" to R.string.v2_set_item_about_phone,
        "setup.helper" to R.string.v2_set_item_setup_helper,
        "setup.checks" to R.string.v2_set_item_setup_checks
    )
}
