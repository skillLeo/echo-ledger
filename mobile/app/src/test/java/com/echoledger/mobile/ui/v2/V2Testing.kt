package com.echoledger.mobile.ui.v2

import android.content.res.Configuration
import android.content.res.Resources
import androidx.test.core.app.ApplicationProvider
import com.echoledger.mobile.ui.v2.shell.V2Text
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.locale

/** The app's resources in one language, for tests that check words. */
object V2Testing {
    /**
     * Forgets the folders FileProvider remembered: it keeps the first test's cache folder for the whole JVM, and each
     * Robolectric test has its own, so a test that shares an exported file calls this first.
     */
    fun freshFileProvider() {
        val cache = androidx.core.content.FileProvider::class.java.getDeclaredField("sCache")
        cache.isAccessible = true
        (cache.get(null) as MutableMap<*, *>).clear()
    }

    fun resources(language: EchoLanguage): Resources {
        val context = ApplicationProvider.getApplicationContext<android.content.Context>()
        val config = Configuration(context.resources.configuration).apply { setLocale(language.locale) }
        return context.createConfigurationContext(config).resources
    }

    fun text(language: EchoLanguage): V2Text = V2Text.from(resources(language))
}
