package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
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
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.robolectric.annotation.Config

/**
 * The biometric app lock around the real MainActivity: when the cover appears, when it must not trap the user,
 * and what stays reachable behind it.
 */
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi")
class AppLockTest : AppTest() {
    @get:Rule
    val compose = createEmptyComposeRule()

    @Before
    fun serveLibrary() {
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(200, ServerFixtures.libraryPage(listOf(ServerFixtures.libraryItem("01JA", title = "Confidential merger call"))))
        )
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        server.on("GET", "/api/sessions/.+", FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null)))
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
    }

    private fun prepare(appLock: Boolean, paired: Boolean = true) {
        runBlocking {
            if (paired) {
                app.pairLocally(server)
                app.graph.library.refresh()
            }
            app.graph.settings.update { it.copy(appLockEnabled = appLock) }
        }
    }

    private fun launchMain(biometricsAvailable: Boolean = true): FragmentActivity {
        val controller = buildActivity(MainActivity::class.java).create()
        val activity = controller.get()
        activity.simulateBiometrics(biometricsAvailable)
        controller.start().postCreate(null).resume().visible()
        return activity
    }

    private fun lockScreenShown() = compose.onAllNodesWithText("EchoLedger is locked").fetchSemanticsNodes().isNotEmpty()

    // ---- when the cover appears ----------------------------------------------------------------------------------------------

    @Test
    fun `an enabled app lock covers the app until the user unlocks`() {
        prepare(appLock = true)
        launchMain()

        pumpUntil("the lock screen") {
            compose.waitForIdle()
            lockScreenShown()
        }

        compose.onNodeWithText("EchoLedger is locked").assertIsDisplayed()
        compose.onNodeWithText("Unlock with your fingerprint, face or device PIN.").assertIsDisplayed()
        compose.onNodeWithText("Unlock").assertIsDisplayed()
        compose.onAllNodesWithContentDescription("Record").assertCountEquals(0)
        compose.onAllNodesWithContentDescription("Settings").assertCountEquals(0)
    }

    @Test
    fun `without the setting the app opens straight to the library with its tabs`() {
        prepare(appLock = false)
        launchMain()

        pumpUntil("the library") {
            compose.waitForIdle()
            compose.onAllNodesWithText("Confidential merger call").fetchSemanticsNodes().isNotEmpty()
        }

        assertFalse(lockScreenShown())
        compose.onAllNodesWithContentDescription("Record").assertCountEquals(1)
        compose.onAllNodesWithContentDescription("Settings").assertCountEquals(1)
    }

    @Test
    fun `an enabled lock never traps the user when the phone has nothing to unlock with`() {
        prepare(appLock = true)
        launchMain(biometricsAvailable = false)

        pumpUntil("the library") {
            compose.waitForIdle()
            compose.onAllNodesWithText("Confidential merger call").fetchSemanticsNodes().isNotEmpty()
        }

        assertFalse(lockScreenShown())
        compose.onAllNodesWithContentDescription("Record").assertCountEquals(1)
    }

    @Test
    fun `turning the setting off while locked removes the cover`() {
        prepare(appLock = true)
        launchMain()
        pumpUntil("the lock screen") {
            compose.waitForIdle()
            lockScreenShown()
        }

        runBlocking { app.graph.settings.update { it.copy(appLockEnabled = false) } }

        pumpUntil("the cover to go away") {
            compose.waitForIdle()
            !lockScreenShown()
        }
    }

    @Test
    fun `the lock also covers the pairing screen of an unpaired phone`() {
        prepare(appLock = true, paired = false)
        launchMain()

        pumpUntil("the lock screen") {
            compose.waitForIdle()
            lockScreenShown()
        }
    }

    // ---- what remains reachable behind the cover -------------------------------------------------------------------------------

    @Test
    fun `recording titles are not exposed to accessibility services while the app is locked`() {
        prepare(appLock = true)
        launchMain()
        pumpUntil("the lock screen") {
            compose.waitForIdle()
            lockScreenShown()
        }
        compose.waitForIdle()

        compose.onAllNodesWithText("Confidential merger call").assertCountEquals(0)
    }

    @Test
    fun `touching a recording behind the cover does not open it`() {
        prepare(appLock = true)
        launchMain()
        pumpUntil("the lock screen") {
            compose.waitForIdle()
            lockScreenShown()
        }
        compose.waitForIdle()

        runCatching { compose.onNodeWithText("Confidential merger call").performClick() }
        compose.waitForIdle()

        assertTrue("no transcript or session may be fetched for a locked app", server.requestsTo("GET", "/api/sessions/.+").isEmpty())
        assertEquals(0, server.requestsTo("GET", "/api/transcripts/.+").size)
    }
}
