package com.echoledger.mobile.ui.v2

import android.content.res.Configuration
import androidx.compose.material3.Text
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.isHeading
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.calls.PostCallPrompt
import com.echoledger.mobile.overlay.DecisionPopup
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.github.takahirom.roborazzi.captureScreenRoboImage
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import org.robolectric.shadows.ShadowSettings

/**
 * v2.1 FIX-03 on the narrowest phone (360 dp), in English and Urdu, light and dark, at the phone's text size and the
 * largest: the app open with Library, right after Transcribe on the after-call card. After the fix (after-*.png) the
 * card is gone and "Transcribing… · Undo" is the app's snackbar just above the bottom bar. Before it (before-*.png,
 * drawn by this same test on 3466db00) the line floated over the app as a white card that could not be swiped away.
 * Written to docs/design/screens/phone/call-lines when recording, otherwise only drawn.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h780dp-xhdpi")
class CallLinesShotsTest : ScreenTest() {
    private val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots"), "phone/call-lines")
    private val record = System.getProperty("roborazzi.test.record") == "true"

    private var scale by mutableFloatStateOf(1f)
    private var dark by mutableStateOf(false)
    private var language by mutableStateOf(EchoLanguage.English)
    private var round by mutableIntStateOf(0)

    private val classic = ClassicScreens(
        home = { Text("") },
        library = { Text("") },
        live = { Text("") },
        ask = { Text("") },
        settings = { Text("") },
        upgrade = {}
    )

    private val v2 = UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true, "ui_v2.live" to true, "ui_v2.ask" to true))

    @Before
    fun seed() {
        ShadowSettings.setCanDrawOverlays(true)
        android.provider.Settings.Global.putFloat(app.contentResolver, android.provider.Settings.Global.ANIMATOR_DURATION_SCALE, 0f)
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        val today = java.time.OffsetDateTime.now().withHour(9).withMinute(0).withSecond(0).withNano(0)
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(
                        ServerFixtures.libraryItem("01JA", title = "Bilal Traders", sourceApp = "whatsapp", startedAt = today.toString()),
                        ServerFixtures.libraryItem(
                            "01JB",
                            title = "Weekly sync",
                            sourceApp = "zoom",
                            startedAt = today.minusDays(1).toString()
                        ),
                        ServerFixtures.libraryItem(
                            "01JC",
                            title = "Rehman Textiles",
                            sourceApp = "phone_call",
                            startedAt = today.minusDays(3).toString()
                        )
                    )
                )
            )
        )
        server.on("GET", "/api/activity", FakeServer.json(200, """{"data": [], "unread_count": 0}"""))
        runBlocking { app.graph.library.refresh() }
    }

    @After
    fun closeCard() {
        DecisionPopup.resetForTest()
        DecisionPopup.drainForTest()
        shadowOf(android.os.Looper.getMainLooper()).idle()
        DecisionPopup.resetForTest()
    }

    private val variants = listOf(
        Triple(false, EchoLanguage.English, 1f),
        Triple(true, EchoLanguage.English, 1f),
        Triple(false, EchoLanguage.Urdu, 1f),
        Triple(true, EchoLanguage.Urdu, 1f),
        Triple(false, EchoLanguage.English, 2f),
        Triple(false, EchoLanguage.Urdu, 2f)
    )

    /** The app open on Library in every variant, then Transcribe on the after-call card; saves `<name>-<variant>.png`. */
    private fun shoot(name: String): List<String> {
        show {
            val base = LocalContext.current
            val config = LocalConfiguration.current
            val scaled = remember(scale) { base.createConfigurationContext(Configuration(config).apply { fontScale = scale }) }
            CompositionLocalProvider(LocalDensity provides Density(scaled), LocalConfiguration provides scaled.resources.configuration) {
                key(round) { EchoV2Shell(vm, v2, darkTheme = dark, classic = classic, onSwitchToClassic = {}, language = language) }
            }
        }
        val drawn = mutableListOf<String>()
        variants.forEachIndexed { i, (d, l, s) ->
            DecisionPopup.resetForTest()
            dark = d
            language = l
            speak(l)
            scale = s
            // The card over the app follows the app's language and the phone's text size, as the shell does.
            app.resources.configuration.fontScale = s
            runBlocking { app.graph.settings.update { it.copy(appLanguage = if (l == EchoLanguage.Urdu) "ur" else "en") } }
            round++
            compose.waitForIdle()
            // The Library's page is drawn (its rows may be below the setup card at the largest text).
            compose.waitUntil(5_000) { compose.onAllNodes(isHeading()).fetchSemanticsNodes().isNotEmpty() }
            val rec = app.storeRecording(id = "call-$i", state = "pending_decision", sourceApp = "whatsapp", durationMs = 158_000)
            PostCallPrompt.show(app, rec, if (d) "dark" else "light")
            assertEquals(DecisionPopup.Mode.ASK, DecisionPopup.showingMode)
            DecisionPopup.transcribe(app)
            compose.waitForIdle()
            shadowOf(android.os.Looper.getMainLooper()).idleFor(java.time.Duration.ofMillis(300))
            val variant = "${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}${if (s > 1f) "-200" else ""}"
            if (record) captureScreenRoboImage(File(out, "$name-$variant.png").path)
            drawn += variant
        }
        app.resources.configuration.fontScale = 1f
        return drawn
    }

    private val all = listOf("light-en", "dark-en", "light-ur", "dark-ur", "light-en-200", "light-ur-200")

    @Test
    fun afterTranscribe() = assertEquals(all, shoot(PREFIX + "-transcribe"))

    private companion object {
        const val PREFIX = "after"
    }
}
