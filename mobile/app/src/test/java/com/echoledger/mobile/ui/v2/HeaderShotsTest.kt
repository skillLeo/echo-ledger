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
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollToNode
import androidx.compose.ui.unit.Density
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
 * v2.1 UX-14 on the narrowest phone (360 dp), light and dark, English and Urdu, at the phone's text size and the
 * largest: the Library with the setup card closed and calls not recording. After (after-*.png): the 64 dp title row
 * and the 48 dp chips row, and "Calls won't record · Fix" as one line in the list. Before (before-*.png, drawn by this
 * same test on 36b8a1d5): the title row with ⋮, the Calls chip and the search field above the chips. Written to
 * docs/design/screens/phone/library-header when recording, otherwise only drawn.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h780dp-xhdpi")
class HeaderShotsTest : ScreenTest() {
    private val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots"), "phone/library-header")
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
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        val today = java.time.OffsetDateTime.now().withHour(9).withMinute(0).withSecond(0).withNano(0)
        fun at(daysAgo: Long) = today.minusDays(daysAgo).toString()
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(
                        ServerFixtures.libraryItem("01JA", title = "Bilal Traders", sourceApp = "whatsapp", startedAt = at(0)),
                        ServerFixtures.libraryItem("01JB", title = "Weekly sync", sourceApp = "zoom", startedAt = at(1)),
                        ServerFixtures.libraryItem("01JC", title = "Shopping list", sourceApp = "voice_note", startedAt = at(2)),
                        ServerFixtures.libraryItem("01JD", title = "Rehman Textiles", sourceApp = "phone_call", startedAt = at(3)),
                        ServerFixtures.libraryItem("01JE", title = "Karachi Cargo", sourceApp = "google_meet", startedAt = at(9))
                    )
                )
            )
        )
        server.on("GET", "/api/activity", FakeServer.json(200, """{"data": [], "unread_count": 0}"""))
        runBlocking {
            app.graph.library.refresh()
            app.graph.settings.update { it.copy(setupCardHidden = true) }
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

    /**
     * UX-25 (after only): back from a recording far down, its row lightly tinted, and Back to top at the bottom end.
     * Many rows, so the list is more than a screen down.
     */
    @Test
    fun theLibraryComeBackTo() {
        if (PREFIX != "after") return
        val today = java.time.OffsetDateTime.now().withHour(9).withMinute(0).withSecond(0).withNano(0)
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    (1..40).map {
                        ServerFixtures.libraryItem(
                            "01K%03d".format(it),
                            title = "Caller %03d".format(it),
                            startedAt = today.minusDays(it.toLong()).toString()
                        )
                    }
                )
            )
        )
        runBlocking { app.graph.library.refresh() }
        assertEquals(
            listOf("light-en", "dark-en", "light-ur", "dark-ur", "light-en-200", "light-ur-200"),
            shoot("after-come-back") {
                compose.onAllNodes(androidx.compose.ui.test.hasScrollToIndexAction()).onFirst()
                    .performScrollToNode(androidx.compose.ui.test.hasText("Caller 030"))
                compose.onAllNodesWithText("Caller 030").onFirst().performClick()
                // The recording page's ← in the page's language.
                val back = V2Testing.resources(language).getString(com.echoledger.mobile.R.string.v2_back)
                compose.waitUntil(5_000) { compose.onAllNodesWithContentDescription(back).fetchSemanticsNodes().isNotEmpty() }
                compose.onAllNodesWithContentDescription(back).onFirst().performClick()
                compose.waitUntil(5_000) { compose.onAllNodesWithText("Caller 030").fetchSemanticsNodes().isNotEmpty() }
            }
        )
    }

    @Test
    fun theLibrary() = assertEquals(
        listOf("light-en", "dark-en", "light-ur", "dark-ur", "light-en-200", "light-ur-200"),
        shoot(PREFIX + "-library")
    )

    private companion object {
        const val PREFIX = "after"
    }
}
