package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.onRoot
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.FeatureDto
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.KeyPage
import com.echoledger.mobile.ui.v2.settings.LanguagePage
import com.echoledger.mobile.ui.v2.settings.WordsPage
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/** Language, Custom words and Transcription key in the v2 look, light and dark, English and Urdu, at the S25's size. */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi")
class TranscriptionPagesShotsTest : ScreenTest() {
    private val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone/app")
    private var language by mutableStateOf(EchoLanguage.English)
    private var dark by mutableStateOf(false)
    private var which by mutableStateOf("language")

    @Test
    fun screenshots() {
        // Two projects for Custom words.
        kotlinx.coroutines.runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        vm.refresh()
        pumpUntil("projects") { vm.clients.value.size == 2 }
        vm.updateSettings { it.copy(plan = PlanState(features = mapOf("platform_transcription" to FeatureDto(enabled = false)))) }
        pumpUntil("free plan") { vm.settings.value.plan.needsOwnKey }
        show {
            val settings by vm.settings.collectAsState()
            EchoV2Theme(darkTheme = dark, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    when (which) {
                        "language" -> LanguagePage(vm, settings, null, {})
                        "words" -> WordsPage(vm, settings, null, {})
                        else -> KeyPage(vm, settings.plan, null, {})
                    }
                }
            }
        }
        for (name in listOf("language", "words", "key")) {
            for ((l, d) in listOf(EchoLanguage.English to false, EchoLanguage.Urdu to false, EchoLanguage.English to true)) {
                which = name
                language = l
                dark = d
                compose.waitForIdle()
                compose.onRoot().captureRoboImage(
                    File(out, "settings-$name-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}.png")
                )
            }
        }
    }
}
