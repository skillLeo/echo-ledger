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
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasScrollToIndexAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isHeading
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollToNode
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.R
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.github.takahirom.roborazzi.captureScreenRoboImage
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * v2.1 UX-16 on the narrowest phone (360 dp), light and dark, English and Urdu, at the phone's text size and the
 * largest. After (after-*.png): the Library's 72 dp rows (a 40 dp avatar of initials, a voice note's mic or a meeting's
 * camera, with the source app's 16 dp badge; line 2 with one mark and the length) and Settings (each group's title as
 * text only, its rows in one rounded group). Before (before-*.png, drawn by this same test on a8296eda). Written to
 * docs/design/screens/phone/settings-rows when recording, otherwise only drawn.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h780dp-xhdpi")
class SettingsRowsShotsTest : ScreenTest() {
    private val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots"), "phone/settings-rows")
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

    private val v2 = UiFlags(
        mapOf("ui_v2.shell" to true, "ui_v2.library" to true, "ui_v2.live" to true, "ui_v2.ask" to true, "ui_v2.settings" to true)
    )

    @Before
    fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        val today = java.time.OffsetDateTime.now().withHour(9).withMinute(0).withSecond(0).withNano(0)
        fun at(daysAgo: Long) = today.minusDays(daysAgo).toString()
        fun marked(row: String, extra: String) = row.replace("\"updated_at\"", "$extra,\"updated_at\"")
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(
                        marked(
                            ServerFixtures.libraryItem("01JA", title = "Bilal Traders", sourceApp = "whatsapp", startedAt = at(0)),
                            "\"important\":true,\"labels\":[\"Clients\"]"
                        ),
                        ServerFixtures.libraryItem("01JB", title = "Weekly sync", sourceApp = "zoom", startedAt = at(0)),
                        ServerFixtures.libraryItem("01JC", title = "Shopping list", sourceApp = "voice_note", startedAt = at(1)),
                        ServerFixtures.libraryItem("01JD", title = "+92 300 1234567", sourceApp = "phone_call", startedAt = at(1)),
                        marked(
                            ServerFixtures.libraryItem("01JE", title = "Rehman Textiles", sourceApp = "whatsapp", startedAt = at(2)),
                            "\"labels\":[\"Clients\",\"Family\"]"
                        ),
                        ServerFixtures.libraryItem("01JF", title = "Karachi Cargo", sourceApp = "google_meet", startedAt = at(9))
                    )
                )
            )
        )
        server.on("GET", "/api/activity", FakeServer.json(200, """{"data": [], "unread_count": 0}"""))
        runBlocking {
            app.graph.library.refresh()
            app.graph.settings.update { it.copy(setupCardHidden = true, recordHintDone = true) }
        }
    }

    private val variants = listOf(
        Triple(false, EchoLanguage.English, 1f),
        Triple(true, EchoLanguage.English, 1f),
        Triple(false, EchoLanguage.Urdu, 1f),
        Triple(true, EchoLanguage.Urdu, 1f),
        Triple(false, EchoLanguage.English, 2f),
        Triple(false, EchoLanguage.Urdu, 2f)
    )

    private val all = listOf("light-en", "dark-en", "light-ur", "dark-ur", "light-en-200", "light-ur-200")

    private fun words(id: Int) = V2Testing.resources(language).getString(id)

    private fun shoot(name: String, step: () -> Unit = {}): List<String> {
        show {
            val base = LocalContext.current
            val config = LocalConfiguration.current
            val scaled = remember(scale) { base.createConfigurationContext(Configuration(config).apply { fontScale = scale }) }
            CompositionLocalProvider(LocalDensity provides Density(scaled), LocalConfiguration provides scaled.resources.configuration) {
                key(round) { EchoV2Shell(vm, v2, darkTheme = dark, classic = classic, onSwitchToClassic = {}, language = language) }
            }
        }
        val drawn = mutableListOf<String>()
        for ((d, l, s) in variants) {
            dark = d
            language = l
            speak(l)
            scale = s
            round++
            compose.waitForIdle()
            compose.waitUntil(5_000) { compose.onAllNodes(isHeading()).fetchSemanticsNodes().isNotEmpty() }
            step()
            compose.waitForIdle()
            val variant = "${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}${if (s > 1f) "-200" else ""}"
            if (record) captureScreenRoboImage(File(out, "$name-$variant.png").path)
            drawn += variant
        }
        return drawn
    }

    /** The avatar's menu › Settings (the full Library's avatar opens its menu). */
    private fun openSettings() {
        compose.onNode(hasContentDescription(words(R.string.v2_account_and_settings))).performClick()
        compose.waitForIdle()
        compose.onAllNodes(hasText(words(R.string.v2_settings))).onFirst().performClick()
        compose.waitUntil(5_000) { compose.onAllNodes(hasText(words(R.string.v2_set_group_recording))).fetchSemanticsNodes().isNotEmpty() }
    }

    @Test
    fun theLibraryRows() = assertEquals(all, shoot("$PREFIX-library-rows"))

    @Test
    fun theSettings() = assertEquals(all, shoot("$PREFIX-settings") { openSettings() })

    @Test
    fun theSettingsFurtherDown() = assertEquals(
        all,
        shoot("$PREFIX-settings-down") {
            openSettings()
            compose.onAllNodes(hasScrollToIndexAction()).onFirst().performScrollToNode(hasText(words(R.string.v2_set_group_privacy)))
        }
    )

    private companion object {
        const val PREFIX = "after"
    }
}
