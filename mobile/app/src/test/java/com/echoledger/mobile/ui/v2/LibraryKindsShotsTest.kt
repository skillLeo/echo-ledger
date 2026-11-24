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
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.KIND_ROW_TAG
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
 * The Library's kind tabs (contract 2.0.0-q) on the narrowest phone (360 dp), light and dark, English and Urdu, at the
 * phone's text size and the largest, with two imported files and one of an app the phone does not know among the
 * calls, meetings and voice notes: after the fix the tabs end with Other, scrolled into view, and add up to All
 * (after-*.png); before it (before-*.png, drawn by this same test on 0f944644) those three were in All only. Written to
 * docs/design/screens/phone/library-kinds when recording, otherwise only drawn.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h780dp-xhdpi")
class LibraryKindsShotsTest : ScreenTest() {
    private val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots"), "phone/library-kinds")
    private val record = System.getProperty("roborazzi.test.record") == "true"

    private var scale by mutableFloatStateOf(1f)
    private var dark by mutableStateOf(false)
    private var language by mutableStateOf(EchoLanguage.English)
    private var round by mutableIntStateOf(0)

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
                        ServerFixtures.libraryItem("01JD", title = "Board meeting.m4a", sourceApp = "imported", startedAt = at(3)),
                        ServerFixtures.libraryItem("01JE", title = "Supplier call.mp3", sourceApp = "imported", startedAt = at(4)),
                        ServerFixtures.libraryItem("01JF", title = "Rehman Textiles", sourceApp = "phone_call", startedAt = at(5)),
                        ServerFixtures.libraryItem("01JG", title = "Site visit", sourceApp = "other", startedAt = at(6))
                    )
                )
            )
        )
        server.on("GET", "/api/lists", FakeServer.json(200, """{"data":[]}"""))
        server.on("GET", "/api/views", FakeServer.json(200, """{"data":[],"views_version":0,"limit":30}"""))
        server.on("GET", "/api/account", FakeServer.json(200, """{"email":"asad@bilaltraders.pk","workspace":{"id":7}}"""))
        runBlocking { app.graph.library.refresh() }
    }

    private val variants = listOf(
        Triple(false, EchoLanguage.English, 1f),
        Triple(true, EchoLanguage.English, 1f),
        Triple(false, EchoLanguage.Urdu, 1f),
        Triple(true, EchoLanguage.Urdu, 1f),
        Triple(false, EchoLanguage.English, 2f),
        Triple(false, EchoLanguage.Urdu, 2f)
    )

    private fun shoot(name: String): List<String> {
        show {
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
            compose.waitUntil(5_000) {
                compose.onAllNodes(hasAnyAncestor(hasTestTag(KIND_ROW_TAG))).fetchSemanticsNodes().isNotEmpty()
            }
            // The end of the tabs in view (Other after the fix).
            val tabs = compose.onAllNodes(hasAnyAncestor(hasTestTag(KIND_ROW_TAG)) and hasClickAction())
            val count = tabs.fetchSemanticsNodes().size
            if (count > 0) tabs[count - 1].performScrollTo()
            compose.waitForIdle()
            val variant = "${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}${if (s > 1f) "-200" else ""}"
            if (record) captureScreenRoboImage(File(out, "$name-$variant.png").path)
            drawn += variant
        }
        return drawn
    }

    @Test
    fun afterTheKinds() = assertEquals(
        listOf("light-en", "dark-en", "light-ur", "dark-ur", "light-en-200", "light-ur-200"),
        shoot(PREFIX + "-tabs")
    )

    private companion object {
        const val PREFIX = "after"
    }
}
