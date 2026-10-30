package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasScrollToIndexAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollToNode
import androidx.fragment.app.FragmentActivity
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.simulateBiometrics
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.robolectric.android.controller.ActivityController
import org.robolectric.annotation.Config

/**
 * Turning the phone must not throw the user back to the library (A6.M9), and in landscape the library list must stay
 * reachable (A6.M11).
 */
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi")
class RotationTest : AppTest() {
    @get:Rule
    val compose = createEmptyComposeRule()

    @Before
    fun servePairedLibrary() {
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(200, ServerFixtures.libraryPage(listOf(ServerFixtures.libraryItem("01JA", title = "Weekly sync"))))
        )
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        server.on("GET", "/api/sessions/.+", FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null)))
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
        runBlocking {
            app.pairLocally(server)
            app.graph.library.refresh()
        }
    }

    private fun launch(waitFor: String = "Weekly sync"): ActivityController<MainActivity> {
        val controller = buildActivity(MainActivity::class.java).create()
        controller.get().simulateBiometrics(true)
        controller.start().postCreate(null).resume().visible()
        pumpUntil("the library") {
            compose.waitForIdle()
            compose.onAllNodesWithText(waitFor).fetchSemanticsNodes().isNotEmpty()
        }
        return controller
    }

    private fun rotate(controller: ActivityController<MainActivity>): FragmentActivity {
        controller.recreate()
        compose.waitForIdle()
        return controller.get()
    }

    @Test
    fun `A6 M9 the Record tab is still open after a rotation`() {
        val controller = launch()
        compose.onNodeWithContentDescription("Record").performClick()
        compose.waitForIdle()
        compose.onNodeWithText("Voice notes & files").performClick()
        pumpUntil("the Record tab") {
            compose.waitForIdle()
            compose.onAllNodesWithText("Not recording").fetchSemanticsNodes().isNotEmpty()
        }

        rotate(controller)

        pumpUntil("the Record tab after the rotation") {
            compose.waitForIdle()
            compose.onAllNodesWithText("Not recording").fetchSemanticsNodes().isNotEmpty()
        }
        assertEquals(0, compose.onAllNodesWithText("Weekly sync").fetchSemanticsNodes().size)
    }

    @Test
    fun `A6 M9 an open recording is still open after a rotation`() {
        val controller = launch()
        compose.onNodeWithText("Weekly sync").performClick()
        pumpUntil("the recording") {
            compose.waitForIdle()
            compose.onAllNodesWithContentDescription("Record").fetchSemanticsNodes().isEmpty()
        }

        rotate(controller)

        pumpUntil("the recording after the rotation") {
            compose.waitForIdle()
            compose.onAllNodesWithText("Weekly sync").fetchSemanticsNodes().isNotEmpty()
        }
        // The tabs stay hidden: this is the recording page, not the library.
        assertEquals(0, compose.onAllNodesWithContentDescription("Record").fetchSemanticsNodes().size)
    }

    @Test
    @Config(sdk = [34], qualifiers = "w891dp-h411dp-land-mdpi")
    fun `A6 M11 in landscape the first recording is reachable under the header`() {
        launch(waitFor = "Search recordings and transcripts")

        // The whole page scrolls, so the list is one scroll away and then fully on screen.
        compose.onNode(hasScrollToIndexAction()).performScrollToNode(hasText("Weekly sync"))
        compose.onNodeWithText("Weekly sync").assertIsDisplayed()
    }
}
