package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.ViewModelProvider
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.simulateBiometrics
import java.time.Duration
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.robolectric.android.controller.ActivityController
import org.robolectric.annotation.Config
import org.robolectric.shadows.ShadowSystemClock

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

    private fun launchMain(biometricsAvailable: Boolean = true): FragmentActivity = launchController(biometricsAvailable).get()

    private fun launchController(biometricsAvailable: Boolean = true): ActivityController<MainActivity> {
        val controller = buildActivity(MainActivity::class.java).create()
        controller.get().simulateBiometrics(biometricsAvailable)
        controller.start().postCreate(null).resume().visible()
        return controller
    }

    private fun lockScreenShown() = compose.onAllNodesWithText("EchoLedger is locked").fetchSemanticsNodes().isNotEmpty()

    private fun libraryShown() = compose.onAllNodesWithText("Confidential merger call").fetchSemanticsNodes().isNotEmpty()

    /**
     * An open app with the lock switched on: it starts with the lock off and the lock is then turned on, which never
     * locks by itself (switching it on in Settings already took one successful unlock).
     */
    private fun unlockedWithLockOn(lockAfterMs: Long): ActivityController<MainActivity> {
        prepare(appLock = false)
        val controller = launchController()
        pumpUntil("the library") {
            compose.waitForIdle()
            libraryShown()
        }
        runBlocking { app.graph.settings.update { it.copy(appLockEnabled = true, lockAfterMs = lockAfterMs) } }
        pumpUntil("the setting to reach the screen") {
            compose.waitForIdle()
            ViewModelProvider(controller.get())[MainViewModel::class.java].settings.value.appLockEnabled
        }
        assertFalse("turning the lock on does not cover the open app", lockScreenShown())
        return controller
    }

    private fun leaveAndComeBack(controller: ActivityController<MainActivity>, away: Duration) {
        controller.pause().stop()
        ShadowSystemClock.advanceBy(away)
        controller.restart().start().resume()
        compose.waitForIdle()
    }

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
        compose.onAllNodesWithContentDescription("You").assertCountEquals(0)
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
        compose.onAllNodesWithContentDescription("You").assertCountEquals(1)
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
    fun `an enabled lock on a phone without a screen lock says why the app opened`() {
        prepare(appLock = true)
        launchMain(biometricsAvailable = false)

        pumpUntil("the message") {
            compose.waitForIdle()
            compose.onAllNodesWithText("App lock is off because this phone has no screen lock.").fetchSemanticsNodes().isNotEmpty()
        }
    }

    // ---- leaving the app ---------------------------------------------------------------------------------------------------------

    @Test
    fun `with Immediately the lock engages again when the app comes back from the background`() {
        val controller = unlockedWithLockOn(LockAfter.IMMEDIATELY)

        leaveAndComeBack(controller, Duration.ofSeconds(1))

        pumpUntil("the lock screen") {
            compose.waitForIdle()
            lockScreenShown()
        }
        assertFalse(libraryShown())
    }

    @Test
    fun `with After 1 minute a short trip keeps the app open and a longer one locks it`() {
        val controller = unlockedWithLockOn(LockAfter.ONE_MINUTE)

        leaveAndComeBack(controller, Duration.ofSeconds(30))
        assertFalse(lockScreenShown())
        assertTrue(libraryShown())

        leaveAndComeBack(controller, Duration.ofSeconds(61))
        pumpUntil("the lock screen") {
            compose.waitForIdle()
            lockScreenShown()
        }
    }

    @Test
    fun `with the lock off leaving the app never locks it`() {
        prepare(appLock = false)
        val controller = launchController()
        pumpUntil("the library") {
            compose.waitForIdle()
            libraryShown()
        }

        leaveAndComeBack(controller, Duration.ofMinutes(10))

        assertFalse(lockScreenShown())
        assertTrue(libraryShown())
    }

    @Test
    fun `rotating the phone keeps an unlocked app open`() {
        val controller = unlockedWithLockOn(LockAfter.IMMEDIATELY)

        controller.recreate()
        compose.waitForIdle()

        pumpUntil("the library after the rotation") {
            compose.waitForIdle()
            libraryShown()
        }
        assertFalse(lockScreenShown())
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
