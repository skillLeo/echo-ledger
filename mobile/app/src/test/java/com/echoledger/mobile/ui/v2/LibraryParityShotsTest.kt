package com.echoledger.mobile.ui.v2

import android.content.res.Configuration
import androidx.compose.foundation.layout.Box
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import com.echoledger.mobile.R
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ReadinessFixtures
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.ui.LibraryScreen
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.util.Locale
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * Library, classic | v2 side by side (docs/PLAN_V2_PARITY.md section 8): the list, selection mode, selection's ⋮ menu,
 * a filter chip's sheet and Sort and group, light and dark, English and Urdu (classic's half stays English).
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi")
class LibraryParityShotsTest : ScreenTest() {
    @Before
    fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(
                        ServerFixtures.libraryItem("01JA", title = "Bilal Traders", preview = "New rate for 40 cartons of basmati"),
                        ServerFixtures.libraryItem(
                            "01JB",
                            title = "Rehman and Sons Textiles",
                            sourceApp = "phone_call",
                            finalStatus = null
                        ),
                        ServerFixtures.libraryItem("01JC", title = "Karachi Cargo", sourceApp = "google_meet", client = null)
                    )
                )
            )
        )
        runBlocking { app.graph.library.refresh() }
    }

    /** A word in English and in Urdu, so a step finds its control on either side and in either language. */
    private fun words(id: Int): List<String> {
        val ur = app.createConfigurationContext(
            Configuration(app.resources.configuration).apply {
                setLocale(Locale.forLanguageTag("ur-PK"))
            }
        )
        return listOf(app.getString(id), ur.getString(id))
    }

    /** Taps the first control named by one of [names] (its words or its screen-reader name); none is fine. */
    private fun tap(vararg names: String) {
        for (name in names) {
            val match = (hasText(name, substring = true) or hasContentDescription(name)) and hasClickAction()
            val nodes = compose.onAllNodes(match)
            if (nodes.fetchSemanticsNodes().isNotEmpty()) {
                nodes.onFirst().performClick()
                compose.waitForIdle()
                return
            }
        }
    }

    private fun select() {
        compose.waitUntil(5_000) { compose.onAllNodesWithText("Bilal Traders", substring = true).fetchSemanticsNodes().isNotEmpty() }
        compose.onAllNodesWithText("Bilal Traders", substring = true).onFirst().performTouchInput { longClick() }
        compose.waitForIdle()
    }

    private fun shoot(name: String, step: () -> Unit): List<String> = ParityShots(compose).shoot(
        name,
        classic = { LibraryScreen(vm, readiness = ReadinessFixtures.READY, onReady = {}, onOpen = {}) },
        v2 = { dark, language ->
            EchoV2Theme(darkTheme = dark, language = language) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    Box {
                        V2LibraryScreen(vm, initials = "AS", onOpen = {}, onTrash = {}, onAccount = {})
                        EchoToastHost(toasts)
                    }
                }
            }
        },
        each = step
    )

    private val all = listOf("light-en", "light-ur", "dark-en", "dark-ur")

    @Test
    fun list() = assertEquals(
        all,
        shoot("library") {
            compose.waitUntil(5_000) { compose.onAllNodesWithText("Bilal Traders", substring = true).fetchSemanticsNodes().isNotEmpty() }
        }
    )

    @Test
    fun selection() = assertEquals(all, shoot("library-selection") { select() })

    @Test
    fun selectionMenu() = assertEquals(
        all,
        shoot("library-selection-menu") {
            select()
            tap(*words(R.string.v2_lib_more).toTypedArray(), "More")
        }
    )

    @Test
    fun filters() = assertEquals(all, shoot("library-filters") { tap(*words(R.string.v2_lib_source).toTypedArray(), "Source") })

    @Test
    fun sortAndGroup() = assertEquals(
        all,
        shoot("library-sort-group") { tap(*words(R.string.v2_lib_sort_group).toTypedArray(), "View: group, sort and density") }
    )
}
