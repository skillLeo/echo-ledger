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
    /** One configuration context per language, made again only when the phone's own configuration changes. */
    private class Made(val base: Configuration, val res: Resources)

    private val made = java.util.concurrent.ConcurrentHashMap<String, Made>()

    /** The resources of [choice] (`en`, `ur`; anything else is the phone's). */
    fun resources(context: Context, choice: String?): Resources {
        val locale = when (choice) {
            "ur" -> Locale.forLanguageTag("ur-PK")
            "en" -> Locale.UK
            else -> return context.resources
        }
        val base = context.resources.configuration
        if (base.locales[0]?.language == locale.language) return context.resources
        made[choice]?.takeIf { it.base == base }?.let { return it.res }
        val config = Configuration(base).apply { setLocale(locale) }
        return context.createConfigurationContext(config).resources.also { made[choice] = Made(Configuration(base), it) }
    }

    /**
     * The resources of the person's app language, from the app's hot copy of the settings (read as the app starts);
     * the phone's language until then. It never reads the store itself: it is asked from the main thread.
     */
    fun resources(context: Context): Resources {
        val graph = (context.applicationContext as? EchoLedgerApp)?.graph ?: return context.resources
        return resources(context, graph.liveSettings.value?.let(com.echoledger.mobile.ui.v2.shell.AppLook::choice))
    }
}
