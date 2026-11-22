package com.echoledger.mobile.notify

import android.content.Context
import android.content.res.Configuration
import android.content.res.Resources
import com.echoledger.mobile.EchoLedgerApp
import java.util.Locale

/**
 * The words of what Android draws for the app (its notifications and channels, the home-screen widget) in the app
 * language chosen in Settings › Appearance (`system`, `en`, `ur`), as the v2 screens are. Before the settings have been
 * read once (a process just started) it is the phone's language.
 */
object AppWords {
    /** The resources of [choice] (`en`, `ur`; anything else is the phone's). */
    fun resources(context: Context, choice: String?): Resources {
        val locale = when (choice) {
            "ur" -> Locale.forLanguageTag("ur-PK")
            "en" -> Locale.UK
            else -> return context.resources
        }
        if (context.resources.configuration.locales[0]?.language == locale.language) return context.resources
        val config = Configuration(context.resources.configuration).apply { setLocale(locale) }
        return context.createConfigurationContext(config).resources
    }

    /**
     * The resources of the person's app language, from the app's hot copy of the settings (read as the app starts);
     * the phone's language until then. It never reads the store itself: it is asked from the main thread.
     */
    fun resources(context: Context): Resources {
        val graph = (context.applicationContext as? EchoLedgerApp)?.graph ?: return context.resources
        return resources(context, graph.liveSettings.value?.appLanguage)
    }
}
