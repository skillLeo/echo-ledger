package com.echoledger.mobile.ui.v2

import android.content.res.Configuration
import android.content.res.Resources
import androidx.test.core.app.ApplicationProvider
import com.echoledger.mobile.ui.v2.shell.V2Text
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.locale

/** The app's resources in one language, for tests that check words. */
object V2Testing {
    fun resources(language: EchoLanguage): Resources {
        val context = ApplicationProvider.getApplicationContext<android.content.Context>()
        val config = Configuration(context.resources.configuration).apply { setLocale(language.locale) }
        return context.createConfigurationContext(config).resources
    }

    fun text(language: EchoLanguage): V2Text = V2Text.from(resources(language))
}
