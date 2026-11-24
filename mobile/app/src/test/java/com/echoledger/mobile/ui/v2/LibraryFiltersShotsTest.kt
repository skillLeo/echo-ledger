package com.echoledger.mobile.ui.v2

import android.content.res.Configuration
import androidx.compose.foundation.layout.Box
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
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.R
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureScreenRoboImage
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * Library's filters and views on the narrowest phone (360 dp), in English and Urdu (right to left), light and dark, at
 * the phone's text size and the largest: the list with [Filters n] and the tabs, the title's menu (Views, My views,
 * Labels, Trash), the Filters sheet with its counts, the pills once filters are on, and ⋮ › Sort & display (after-*.png).
 * v1.1's Library before them (before-*.png: its chip rows and their sheets) was drawn from 91e5a875 by this test's
 * first version. Written to docs/design/screens/phone/library-filters when recording, otherwise only drawn.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h780dp-xhdpi")
class LibraryFiltersShotsTest : ScreenTest() {
    private val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots"), "phone/library-filters")
    private val record = System.getProperty("roborazzi.test.record") == "true"

    private var scale by mutableFloatStateOf(1f)
    private var dark by mutableStateOf(false)
    private var language by mutableStateOf(EchoLanguage.English)

    private fun row(id: String, title: String, app: String, client: String?, extra: String, startedAt: String) =
        ServerFixtures.libraryItem(id, title = title, sourceApp = app, client = client, startedAt = startedAt)
            .replace("\"updated_at\"", "$extra,\"updated_at\"")

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
                        row("01JA", "Bilal Traders", "whatsapp", "Acme Holdings", "\"labels\":[\"Clients\"],\"opened\":false", at(0)),
                        row("01JB", "Weekly sync", "zoom", null, "\"labels\":[],\"opened\":true,\"important\":true", at(1)),
                        row("01JC", "Shopping list", "voice_note", null, "\"labels\":[],\"opened\":true", at(2)),
                        row("01JD", "Rehman Textiles", "phone_call", "Acme Holdings", "\"labels\":[\"Invoice\"],\"opened\":true", at(4)),
                        row("01JE", "Karachi Cargo", "google_meet", null, "\"labels\":[\"Clients\"],\"opened\":true,\"pinned\":true", at(9))
                    )
                )
            )
        )
        server.on(
            "GET",
            "/api/lists",
            FakeServer.json(
                200,
                """{"data":[{"key":"clients","name":"Clients","color":"#e15f56","position":0,"count":2},
                    {"key":"invoice","name":"Invoice","color":"#2d9a63","position":1,"count":1}]}"""
            )
        )
        server.on("GET", "/api/search.*", FakeServer.json(200, """{"data":[]}"""))
        server.on(
            "GET",
            "/api/views",
            FakeServer.json(
                200,
                """{"data":[{"id":"01JV000000000000000000000A","name":"WhatsApp this week","position":0,
                    "filters":{"date":{"op":"is","values":["last_7_days"]},"source":{"op":"is","values":["whatsapp"]}},
                    "search":null,"sort":"newest","group":"date","density":"comfortable"}],"views_version":1,"limit":30}"""
            )
        )
        server.on("GET", "/api/account", FakeServer.json(200, """{"email":"asad@bilaltraders.pk","workspace":{"id":7}}"""))
        runBlocking { app.graph.library.refresh() }
        vm.refreshQuietly()
        com.echoledger.mobile.testsupport.pumpUntil("my views") { vm.savedViews.value.isNotEmpty() }
    }

    /** The control named by [id] in either language (its words or its screen-reader name), tapped when it shows. */
    private fun tap(id: Int) {
        val names = EchoLanguage.entries.map { V2Testing.resources(it).getString(id) }
        val match = names.map { hasText(it, substring = true) or hasContentDescription(it) }.reduce { a, b -> a or b } and hasClickAction()
        val nodes = compose.onAllNodes(match)
        if (nodes.fetchSemanticsNodes().isNotEmpty()) nodes.onFirst().performClick()
        compose.waitForIdle()
    }

    private fun rows() = compose.waitUntil(5_000) {
        compose.onAllNodesWithText("Bilal Traders", substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    private val variants = listOf(
        Triple(false, EchoLanguage.English, 1f),
        Triple(true, EchoLanguage.English, 1f),
        Triple(false, EchoLanguage.Urdu, 1f),
        Triple(true, EchoLanguage.Urdu, 1f),
        Triple(false, EchoLanguage.English, 2f),
        Triple(false, EchoLanguage.Urdu, 2f)
    )

    /** Which variant is drawn: a new one starts the screen afresh (its sheets closed). */
    private var round by mutableIntStateOf(0)

    /** Draws the Library in every variant, [step] after each (to open a sheet); saves `<name>-<variant>.png`. */
    private fun shoot(name: String, step: () -> Unit = {}): List<String> {
        compose.setContent {
            val base = LocalContext.current
            val config = LocalConfiguration.current
            val scaled = remember(scale) { base.createConfigurationContext(Configuration(config).apply { fontScale = scale }) }
            CompositionLocalProvider(LocalDensity provides Density(scaled), LocalConfiguration provides scaled.resources.configuration) {
                key(round) {
                    EchoV2Theme(darkTheme = dark, language = language) {
                        val toasts = rememberEchoToastState()
                        CompositionLocalProvider(LocalEchoToasts provides toasts) {
                            Box {
                                V2LibraryScreen(vm, initials = "AS", onOpen = {}, onTrash = {}, onAccount = {})
                                EchoToastHost(toasts)
                            }
                        }
                    }
                }
            }
        }
        val drawn = mutableListOf<String>()
        for ((d, l, s) in variants) {
            dark = d
            language = l
            scale = s
            round++
            compose.waitForIdle()
            rows()
            step()
            compose.waitForIdle()
            val variant = "${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}${if (s > 1f) "-200" else ""}"
            if (record) captureScreenRoboImage(File(out, "$name-$variant.png").path)
            drawn += variant
        }
        return drawn
    }

    private val all = listOf("light-en", "dark-en", "light-ur", "dark-ur", "light-en-200", "light-ur-200")

    /** Taps the first control whose words (or screen-reader name) are [names], in either language. */
    private fun tapWords(vararg names: String) {
        val match = names.map { hasText(it, substring = true) or hasContentDescription(it, substring = true) }.reduce { a, b -> a or b } and
            hasClickAction()
        compose.onAllNodes(match).onFirst().performClick()
        compose.waitForIdle()
    }

    private fun both(id: Int) = EchoLanguage.entries.map { V2Testing.resources(it).getString(id) }.toTypedArray()

    @Test
    fun afterLibrary() = assertEquals(all, shoot("after-library"))

    @Test
    fun afterTheTitlesMenu() = assertEquals(all, shoot("after-menu") { tapWords(*both(R.string.v2_lf_all_recordings)) })

    @Test
    fun afterTheFiltersSheet() = assertEquals(all, shoot("after-filters") { tapWords(*both(R.string.v2_lf_filters)) })

    @Test
    fun afterThePills() = assertEquals(
        all,
        shoot("after-pills") {
            tapWords(*both(R.string.v2_lf_all_recordings))
            tapWords("WhatsApp this week")
        }
    )

    @Test
    fun afterSortAndDisplay() = assertEquals(
        all,
        shoot("after-sort-display") {
            // The chips row's last chip since v2.1 (UX-14): scrolled to, then tapped.
            val chip = both(R.string.v2_lf_sort_display).map { hasText(it) }.reduce { a, b -> a or b } and hasClickAction()
            compose.onAllNodes(chip).onFirst().performScrollTo().performClick()
            compose.waitForIdle()
        }
    )
}
