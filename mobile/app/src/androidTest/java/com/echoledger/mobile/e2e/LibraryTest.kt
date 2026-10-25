/*
 * End-to-end tests: the app on an emulator or device against a disposable EchoLedger test backend. Every test here is
 * skipped unless the run names the backend with these instrumentation arguments (with Gradle:
 * -Pandroid.testInstrumentationRunnerArguments.<name>=<value>):
 *   backend.server   the test server the app pairs with, for example http://10.0.2.2:8000 (the development machine as
 *                    seen from the Android emulator)
 *   backend.flaky    the same server behind a proxy whose failure mode the tests switch
 *   backend.control  the backend's control endpoint (pairing codes, seeding, statistics; see support/Backend.kt)
 */
package com.echoledger.mobile.e2e

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.uiautomator.By
import com.echoledger.mobile.e2e.support.Backend
import com.echoledger.mobile.e2e.support.E2eTest
import com.echoledger.mobile.e2e.support.Library
import com.echoledger.mobile.e2e.support.SeedData
import com.echoledger.mobile.e2e.support.Shell
import com.echoledger.mobile.e2e.support.Ui
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/** The library of an empty server: what a new user sees first. */
@RunWith(AndroidJUnit4::class)
class EmptyLibraryTest : E2eTest() {
    @Before
    fun emptyServer() {
        Backend.wipeSessions()
    }

    @Test
    fun anEmptyLibraryExplainsWhatWillAppearAndOffersTheFilters() {
        launchPaired()
        Ui.awaitText("No recordings yet")
        Ui.snapshot("library-empty")
        assertTrue(Ui.has("Calls, meetings and voice notes you capture will appear here."))
        assertTrue(Ui.has("Recordings from your phone, computer and the web"))
        listOf("All", "Phone", "Desktop", "Web").forEach { assertTrue("filter chip $it", Ui.has(it)) }
        Ui.awaitDescription("Sync")
        Ui.awaitText(Library.SEARCH_HINT)
        listOf("Library", "Record", "Settings").forEach { Ui.awaitDescription(it) }
    }

    @Test
    fun anEmptySearchAndAnActiveFilterSayNothingMatches() {
        launchPaired()
        Ui.awaitText("No recordings yet")
        Library.search("anything")
        Ui.awaitText("Nothing matches")
        assertTrue(Ui.has("Try a different search or filter."))
        Library.clearSearch()
        Ui.awaitText("No recordings yet")
        Ui.clickText("Phone")
        Ui.awaitText("Nothing matches")
    }

    @Test
    fun theAppOffersCallSetupBecauseTheCaptureHelperIsNotRunning() {
        launchPaired()
        Ui.awaitText("WhatsApp calls are not being recorded")
        Ui.clickText("Set up call recording →")
        Ui.awaitText("Setup")
        Ui.awaitTextContaining("Call capture")
        Ui.clickDescription("Back")
        Ui.awaitText("Library")
    }
}

/** The library with sessions made on another device. */
@RunWith(AndroidJUnit4::class)
class LibraryTest : E2eTest() {
    @Before
    fun seeded() {
        SeedData.ensure()
    }

    @Test
    fun sessionsFromAnotherDeviceAreListedWithIconAndTextBadges() {
        launchPaired()
        Library.awaitLoaded()
        assertTrue(Ui.has(SeedData.GOOD_TWO))
        assertTrue("device badge text", Ui.device.findObjects(By.text("Desktop")).size >= 3)
        assertTrue("app badges", Ui.has("Google Meet") && Ui.has("WhatsApp Business"))
        assertTrue("ready status", Ui.has("Ready"))
        Ui.snapshot("library-list")
        assertTrue("preview of the transcript", Ui.hasContaining("This is a test of the EchoLedger transcription system."))
    }

    @Test
    fun rowsShowTheStartTimeInThePhonesLocalTimeWithAmPm() {
        launchPaired()
        Library.awaitLoaded()
        val pattern = Regex("""([A-Z][a-z]{2}) (\d{1,2}) · (\d{1,2}:\d{2} [AP]M) · 0:08""")
        val subtitle = Ui.device.findObjects(By.textContains(" · ")).map { it.text }.firstOrNull { pattern.containsMatchIn(it) }
            ?: throw AssertionError("no row subtitle like \"Oct 24 · 9:05 PM · 0:08\"\n${Ui.hierarchySummary()}")
        val match = pattern.find(subtitle)!!
        val shown = LocalDateTime.parse(
            "${match.groupValues[1]} ${match.groupValues[2]} ${LocalDateTime.now().year} ${match.groupValues[3]}",
            DateTimeFormatter.ofPattern("MMM d yyyy h:mm a", Locale.getDefault())
        )
        val minutesApart = Math.abs(java.time.Duration.between(shown, LocalDateTime.now()).toMinutes())
        assertTrue("row says $shown but the phone clock says ${LocalDateTime.now()}", minutesApart <= 30)
    }

    @Test
    fun statusDotsDistinguishReadyFailedAndProcessing() {
        launchPaired()
        Ui.awaitText(SeedData.FAILED, Ui.LONG)
        assertTrue(Ui.has("Failed"))
        assertTrue(Ui.has("Ready"))
    }

    @Test
    fun deviceFiltersNarrowTheList() {
        launchPaired()
        Library.awaitLoaded()
        Ui.clickText("Phone")
        Ui.awaitText("Nothing matches")
        Ui.clickText("Web")
        Ui.awaitText("Nothing matches")
        Ui.clickText("Desktop")
        Ui.awaitText(SeedData.SILENT)
        Ui.clickText("All")
        Ui.awaitText(SeedData.FAILED)
    }

    @Test
    fun searchMatchesTheTranscriptPreviewTheTitleAndTheAppName() {
        launchPaired()
        Library.awaitLoaded()
        Library.search("three thirty")
        Ui.awaitText(SeedData.GOOD_ONE)
        Library.clearSearch()
        Library.search("seeded zoom 2")
        Ui.awaitText(SeedData.GOOD_TWO)
        assertTrue("other sessions are filtered out", !Ui.has(SeedData.GOOD_ONE))
        Library.clearSearch()
        Library.search("whatsapp business")
        Ui.awaitText(SeedData.SILENT)
        Library.clearSearch()
        Library.search("zzzz-no-such-text")
        Ui.awaitText("Nothing matches")
        Library.clearSearch()
        Ui.awaitText(SeedData.SILENT)
    }

    @Test
    fun syncPicksUpASessionCreatedOnAnotherDeviceWhileTheLibraryIsOpen() {
        Backend.dropSessionsTitled("Seeded other 1")
        launchPaired()
        Library.awaitLoaded()
        val title = Backend.seedDesktopSessions(1, "other").single()
        Library.search(title)
        Ui.awaitText("Nothing matches")
        Library.clearSearch()
        Library.sync()
        Library.search(title)
        Library.row(title, Ui.LONG)
    }

    @Test
    fun startingOfflineShowsTheLastSyncedLibraryAndANotice() {
        launchPaired()
        Library.awaitLoaded()
        Ui.device.pressHome()
        Shell.setNetwork(false)
        Ui.launchApp()
        Ui.awaitText("Offline — showing the last synced library", Ui.LONG)
        Ui.snapshot("library-offline")
        assertTrue("cached library is still shown", Ui.has(SeedData.SILENT))
        Shell.setNetwork(true)
        Library.sync()
        Ui.awaitText(SeedData.SILENT)
        assertEquals(false, Ui.has("No recordings yet"))
    }
}
