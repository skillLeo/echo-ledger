package com.echoledger.mobile.e2e

import androidx.test.ext.junit.runners.AndroidJUnit4
import com.echoledger.mobile.e2e.support.AppState
import com.echoledger.mobile.e2e.support.Backend
import com.echoledger.mobile.e2e.support.E2eTest
import com.echoledger.mobile.e2e.support.Library
import com.echoledger.mobile.e2e.support.RecordPage
import com.echoledger.mobile.e2e.support.SeedData
import com.echoledger.mobile.e2e.support.Sharing
import com.echoledger.mobile.e2e.support.Ui
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

/** What the phone does when the server revokes it. docs/ARCHITECTURE.md: stop syncing, keep queued recordings, ask to pair again. */
@RunWith(AndroidJUnit4::class)
class ServerSideChangesTest : E2eTest() {
    @Test
    fun aRevokedPhoneIsToldItWasUnpairedWhenItSyncs() {
        SeedData.ensure()
        launchPaired()
        Library.awaitLoaded()
        Backend.revokeAllDevices()
        Library.sync()
        Ui.awaitText("This phone was unpaired on the server. Pair it again.", Ui.LONG)
        assertTrue("the cached library stays readable", Ui.has(SeedData.SILENT) || Ui.has("Pair this phone"))
    }

    @Test
    fun aRevokedPhoneAsksToPairAgainInsteadOfStayingInTheLibrary() {
        SeedData.ensure()
        launchPaired()
        Library.awaitLoaded()
        Backend.revokeAllDevices()
        Library.sync()
        Ui.awaitText("This phone was unpaired on the server. Pair it again.", Ui.LONG)
        Ui.awaitText("Pair this phone", Ui.NORMAL)
    }

    @Test
    fun aRecordingWaitingForARevokedPhoneIsKeptAndUploadsAfterPairingAgain() {
        assertTrue("audio fixtures are not pushed to ${Sharing.FIXTURE_DIR}", Sharing.fixturesPresent())
        Backend.wipeSessions()
        launchPaired()
        Ui.device.pressHome()
        Backend.revokeAllDevices()
        Sharing.shareThroughShareSheet(listOf(Sharing.importFixture(Sharing.SHORT_NOTE)))
        Sharing.awaitImported(1)
        Ui.awaitCondition("the upload attempt to be refused", Ui.NORMAL) { AppState.recordings().singleOrNull()?.lastError != null }
        val kept = AppState.recordings().single()
        assertNotNull("refusal is recorded on the recording", kept.lastError)
        assertTrue("the audio is not discarded", kept.state != "uploaded")
        assertTrue(Backend.liveSessions().isEmpty())

        val newDevice = Backend.registerDevice(name = "Re-paired phone")
        AppState.pairAs(newDevice)
        Ui.launchApp()
        Ui.awaitText("Library")
        Ui.awaitCondition("the kept recording to upload after pairing again", 120_000, 3_000) {
            Backend.liveSessions().any { it.finalStatus == "completed" }
        }
        assertEquals(1, Backend.liveSessions().size)
        Ui.openTab("Record")
        Ui.awaitTextGone(RecordPage.UPLOADING, Ui.LONG)
    }
}
