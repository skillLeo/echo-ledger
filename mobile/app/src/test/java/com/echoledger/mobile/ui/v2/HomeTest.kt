package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.core.ReadyChip
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.home.ChecklistItem
import com.echoledger.mobile.ui.v2.home.HomeActions
import com.echoledger.mobile.ui.v2.home.HomeModel
import com.echoledger.mobile.ui.v2.home.HomeState
import com.echoledger.mobile.ui.v2.home.HomeStatus
import com.echoledger.mobile.ui.v2.home.NeedsAction
import com.echoledger.mobile.ui.v2.home.RecentState
import com.echoledger.mobile.ui.v2.home.V2HomeContent
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import java.time.ZonedDateTime
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/** Home v2: what it shows from the phone's data, its states, its actions, and how it looks. */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class HomeTest {
    @get:Rule
    val compose = createComposeRule()

    private val now = ZonedDateTime.parse("2026-11-17T12:00:00+05:00[Asia/Karachi]")
    private val outDir = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone")

    private val omar = HomeFixtures.waiting("r1", "Omar Khan", 252_000)

    @Test
    fun theStatusStripSaysWhatCallsAreDoingNow() {
        assertEquals(HomeStatus.Ready, HomeModel.status(HomeFixtures.facts()))
        assertEquals(HomeStatus.NeedsSetup, HomeModel.status(HomeFixtures.facts(chip = ReadyChip.NOT_READY)))
        assertEquals(HomeStatus.CallsOff, HomeModel.status(HomeFixtures.facts(chip = ReadyChip.OFF)))
        // A recording in progress wins over everything.
        val recording = HomeModel.status(
            HomeFixtures.facts(chip = ReadyChip.NOT_READY, live = LiveRecording(active = true, elapsedMs = 134_000))
        )
        assertEquals(HomeStatus.Recording(134_000, false), recording)
    }

    @Test
    fun theChecklistStaysUntilEverythingIsDone() {
        assertTrue(HomeModel.checklist(HomeFixtures.facts()).isEmpty())
        val fresh = HomeModel.checklist(HomeFixtures.facts(captureReady = false, notifications = false, library = false, language = null))
        assertEquals(
            listOf(
                ChecklistItem.Capture to false,
                ChecklistItem.Permissions to false,
                ChecklistItem.FirstRecording to false,
                ChecklistItem.Language to false
            ),
            fresh
        )
        // A recording still on the phone counts as the first one.
        val one = HomeModel.checklist(HomeFixtures.facts(library = false, pending = listOf(omar), language = null))
        assertEquals(true, one.toMap()[ChecklistItem.FirstRecording])
    }

    @Test
    fun needsActionComesFromThePhonesQueueAndTheLibrary() {
        val failed = HomeFixtures.waiting("r2", "Bilal Ahmed", 61_000, state = "failed")
        val needs = HomeModel.needsAction(HomeFixtures.facts(pending = listOf(failed, omar)))
        assertEquals(NeedsAction.WaitingDecision(omar), needs[0])
        assertEquals(NeedsAction.FailedUploads(listOf("r2")), needs[1])
        assertEquals(NeedsAction.Untranscribed(1), needs[2])
        assertEquals(NeedsAction.FailedTranscripts(1), needs[3])
        assertTrue(HomeModel.needsAction(HomeFixtures.facts(library = false)).isEmpty())
    }

    @Test
    fun theRecentPartHasItsLoadingErrorAndEmptyStates() {
        assertEquals(RecentState.Loading, HomeModel.recentState(HomeFixtures.facts(library = false, syncing = true, hasSynced = false)))
        assertEquals(RecentState.Error, HomeModel.recentState(HomeFixtures.facts(library = false, offline = true)))
        assertEquals(RecentState.Empty, HomeModel.recentState(HomeFixtures.facts(library = false)))
        assertEquals(RecentState.Ready, HomeModel.recentState(HomeFixtures.facts()))
        assertEquals(HomeModel.RECENT, HomeModel.state(HomeFixtures.facts()).recent.size)
    }

    private class Taps {
        val log = mutableListOf<String>()
        val actions = HomeActions(
            onSetup = { log += "setup" },
            onAllow = { log += "allow" },
            onRecord = { log += "record" },
            onChooseLanguage = { log += "language" },
            onOpenRecording = { log += "open $it" },
            onOpenUntranscribed = { log += "untranscribed" },
            onSeeAll = { log += "all" },
            onPlan = { log += "plan" },
            onAccount = { log += "account" }
        )
    }

    @Composable
    private fun Home(state: HomeState, taps: Taps, kept: MutableList<LocalRecording>, discarded: MutableList<LocalRecording>) {
        androidx.compose.runtime.CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
            V2HomeContent(state, "AS", taps.actions, {
                kept += it
            }, { discarded += it }, { taps.log += "retry" }, { taps.log += "reload" }, now = now)
        }
    }

    @Test
    fun itsButtonsDoWhatTheySay() {
        val taps = Taps()
        val kept = mutableListOf<LocalRecording>()
        val discarded = mutableListOf<LocalRecording>()
        val failed = HomeFixtures.waiting("r2", "Bilal Ahmed", 61_000, state = "failed")
        val state = HomeModel.state(HomeFixtures.facts(chip = ReadyChip.NOT_READY, captureReady = false, pending = listOf(omar, failed)))
        compose.setContent { EchoV2Theme(darkTheme = false, language = EchoLanguage.English) { Home(state, taps, kept, discarded) } }
        compose.onNodeWithText("Fix").performClick()
        compose.onNodeWithText("Set up").performClick()
        compose.onNodeWithText("Keep").performScrollTo().performClick()
        compose.onNodeWithText("Discard").performScrollTo().performClick()
        compose.onNodeWithText("Retry").performScrollTo().performClick()
        compose.onNodeWithText("1 recording not transcribed").performScrollTo().performClick()
        assertEquals(listOf("setup", "setup", "retry", "untranscribed"), taps.log)
        assertEquals(listOf(omar), kept)
        assertEquals(listOf(omar), discarded)
    }

    @Test
    fun anEmptyLibraryOffersToRecord() {
        val taps = Taps()
        val state = HomeModel.state(HomeFixtures.facts(library = false))
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) { Home(state, taps, mutableListOf(), mutableListOf()) }
        }
        compose.onNodeWithText("Nothing needs you right now").assertExists()
        compose.onNodeWithText("No recordings yet").performScrollTo()
        // The checklist's first-recording step and the empty state both offer Record.
        compose.onAllNodesWithText("Record").assertCountEquals(2)
        compose.onAllNodesWithText("Record")[1].performClick()
        assertEquals(listOf("record"), taps.log)
    }

    @Test
    fun screenshots() {
        var language by mutableStateOf(EchoLanguage.English)
        var dark by mutableStateOf(false)
        var which by mutableStateOf("home")
        compose.setContent {
            EchoV2Theme(darkTheme = dark, language = language) {
                val ur = language == EchoLanguage.Urdu
                val facts = when (which) {
                    "home" -> HomeFixtures.facts(
                        urdu = ur,
                        captureReady = false,
                        chip = ReadyChip.NOT_READY,
                        pending = listOf(HomeFixtures.waiting("r1", if (ur) "عمر خان" else "Omar Khan", 252_000)),
                        language = null
                    )

                    "home-recording" -> HomeFixtures.facts(urdu = ur, live = LiveRecording(active = true, elapsedMs = 134_000))

                    "home-empty" -> HomeFixtures.facts(urdu = ur, library = false)

                    "home-loading" -> HomeFixtures.facts(urdu = ur, library = false, syncing = true, hasSynced = false)

                    else -> HomeFixtures.facts(urdu = ur, library = false, offline = true)
                }
                Home(HomeModel.state(facts), Taps(), mutableListOf(), mutableListOf())
            }
        }
        val shots = listOf(
            "home" to "app",
            "home-recording" to "app",
            "home-empty" to "states",
            "home-loading" to "states",
            "home-error" to "states"
        )
        for ((name, folder) in shots) {
            for (l in EchoLanguage.entries) {
                for (d in listOf(false, true)) {
                    which = name
                    language = l
                    dark = d
                    compose.waitForIdle()
                    val file = "$name-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}.png"
                    compose.onRoot().captureRoboImage(File(File(outDir, folder), file))
                }
            }
        }
    }
}
