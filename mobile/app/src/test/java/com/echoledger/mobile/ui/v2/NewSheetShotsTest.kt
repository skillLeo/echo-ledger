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
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isHeading
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
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
 * v2.1 UX-15 on the narrowest phone (360 dp), light and dark, English and Urdu, at the phone's text size and the
 * largest. After (after-*.png): the "New" sheet a long press on Record opens (actions only: Record now, the big one,
 * Voice note, Import audio, Start Live Assist, Dictation, Translate a game or video); the Live tab with Auto-record
 * calls and Game and Watch translate's state; and the "Tap to record · Hold for more" hint. Before (before-*.png, drawn
 * by this same test on 380b70f0): the Record sheet with its switch and statuses, and the Live tab without them.
 * Written to docs/design/screens/phone/new-sheet when recording, otherwise only drawn.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h780dp-xhdpi")
class NewSheetShotsTest : ScreenTest() {
    private val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots"), "phone/new-sheet")
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
        mapOf("ui_v2.shell" to true, "ui_v2.library" to true, "ui_v2.live" to true, "ui_v2.ask" to true, "live_assist" to true)
    )

    @Before
    fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(200, ServerFixtures.libraryPage(listOf(ServerFixtures.libraryItem("01JA", title = "Bilal Traders"))))
        )
        server.on("GET", "/api/activity", FakeServer.json(200, """{"data": [], "unread_count": 0}"""))
        runBlocking {
            app.graph.library.refresh()
            // The Record hint already seen (each variant is drawn as the app opened again); theRecordHint shows it.
            app.graph.settings.update { it.copy(setupCardHidden = true, recordHintDone = true, plan = it.plan.copy(liveTranslate = true)) }
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

    private val all = listOf("light-en", "dark-en", "light-ur", "dark-ur", "light-en-200", "light-ur-200")

    @Test
    fun theSheet() = assertEquals(
        all,
        shoot("$PREFIX-sheet") {
            val longPress = SemanticsMatcher.keyIsDefined(SemanticsActions.OnLongClick)
            compose.onNode(hasContentDescription(words(R.string.v2_nav_record)) and longPress).performTouchInput { longClick() }
            compose.waitUntil(5_000) { compose.onAllNodes(hasText(words(R.string.v2_rs_title))).fetchSemanticsNodes().isNotEmpty() }
        }
    )

    @Test
    fun theLiveTab() = assertEquals(
        all,
        shoot("$PREFIX-live-tab") {
            compose.onAllNodes(hasText(words(R.string.v2_live)) and !isHeading()).onFirst().performClick()
            val title = hasText(words(R.string.v2_livetab_title)) and isHeading()
            compose.waitUntil(5_000) { compose.onAllNodes(title).fetchSemanticsNodes().isNotEmpty() }
        }
    )

    /** After only: the hint over Record from the second opening of the app. */
    @Test
    fun theRecordHint() {
        if (PREFIX != "after") return
        runBlocking { app.graph.settings.update { it.copy(appOpens = 1) } }
        assertEquals(
            all,
            shoot("$PREFIX-hint") {
                // Each variant is the app opened again, with the hint not yet shown three times.
                runBlocking {
                    app.graph.settings.update { it.copy(appOpens = it.appOpens + 1, recordHintShows = 0, recordHintDone = false) }
                }
                val hint = androidx.compose.ui.test.SemanticsMatcher.expectValue(
                    com.echoledger.mobile.ui.v2.shell.RecordHintText,
                    words(R.string.v2_rs_hint)
                )
                compose.waitUntil(5_000) { compose.onAllNodes(hint).fetchSemanticsNodes().isNotEmpty() }
            }
        )
    }

    private companion object {
        const val PREFIX = "after"
    }
}
