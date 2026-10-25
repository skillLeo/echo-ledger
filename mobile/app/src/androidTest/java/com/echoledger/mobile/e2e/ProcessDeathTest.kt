/*
 * End-to-end test: the app on an emulator or device against a disposable EchoLedger test backend. Every test here is
 * skipped unless the run names the backend with these instrumentation arguments (with Gradle:
 * -Pandroid.testInstrumentationRunnerArguments.<name>=<value>):
 *   backend.server   the test server the app pairs with, for example http://10.0.2.2:8000 (the development machine as
 *                    seen from the Android emulator)
 *   backend.flaky    the same server behind a proxy whose failure mode the tests switch
 *   backend.control  the backend's control endpoint (pairing codes, seeding, statistics; see support/Backend.kt)
 *   phase            stall or verify, set by the outside script described below
 * It also needs speech-40s.wav (40 s of speech, 16-bit mono PCM WAV at 22,050 Hz) pushed to /data/local/tmp/e2e with adb.
 */
package com.echoledger.mobile.e2e

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.echoledger.mobile.e2e.support.AppState
import com.echoledger.mobile.e2e.support.Backend
import com.echoledger.mobile.e2e.support.ScriptDriven
import com.echoledger.mobile.e2e.support.Sharing
import com.echoledger.mobile.e2e.support.Ui
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Assume.assumeTrue
import org.junit.FixMethodOrder
import org.junit.Test
import org.junit.runner.RunWith
import org.junit.runners.MethodSorters

/**
 * An upload that outlives the app's process. An instrumentation test runs inside the app's own process, so the process
 * cannot be ended from within a test. This class is therefore run in two instrumentation invocations with the process
 * ended from outside in between:
 *
 *     am instrument -w -e processDeath true -e phase stall  -e class ...ProcessDeathTest com.echoledger.mobile.test/...
 *     (the process is killed from the host; the backend is switched back to normal)
 *     am instrument -w -e processDeath true -e phase verify -e class ...ProcessDeathTest com.echoledger.mobile.test/...
 *
 * The default connected-test run leaves this class out (@ScriptDriven); without a phase argument both tests skip themselves.
 */
@ScriptDriven
@RunWith(AndroidJUnit4::class)
@FixMethodOrder(MethodSorters.NAME_ASCENDING)
class ProcessDeathTest {
    private val phase: String? get() = InstrumentationRegistry.getArguments().getString("phase")

    @Test
    fun phase1_startAnUploadAndLeaveItHalfSent() {
        assumeTrue("run through the process-death script (phase=stall)", phase == "stall")
        Backend.assumeConfigured()
        Backend.setFlakyMode("ok")
        Backend.resetStats()
        Backend.wipeSessions()
        AppState.reset()
        AppState.pairAs(Backend.registerDevice(viaFlaky = true, name = "Process death phone"))
        Backend.setFlakyMode("slow:2500")
        Ui.launchApp()
        Ui.awaitText("Library")
        Ui.device.pressHome()
        Sharing.shareThroughShareSheet(listOf(Sharing.importFixture(Sharing.LONG_NOTE)))
        Sharing.awaitImported(1)
        Ui.awaitCondition("two chunks to arrive", Ui.LONG, 500) { Backend.stats().chunkPosts >= 2 }
        assertTrue("the upload is under way", AppState.recordings().single().state == "uploading")
    }

    @Test
    fun phase2_theUploadResumesAndCompletesAfterTheProcessWasKilled() {
        assumeTrue("run through the process-death script (phase=verify)", phase == "verify")
        Backend.assumeConfigured()
        Ui.awaitCondition("the upload to finish after the restart", 180_000, 3_000) {
            Backend.liveSessions().any { it.finalStatus == "completed" }
        }
        assertEquals("one session, not one per attempt", 1, Backend.liveSessions().size)
        val chunks = Backend.stats().chunkPosts
        assertTrue("resumed instead of starting over: $chunks chunk uploads for 7 chunks", chunks in 7..9)
        Ui.awaitCondition("the local copy to be deleted", Ui.NORMAL) { AppState.recordings().isEmpty() }
        AppState.reset()
        Sharing.cleanUp()
    }
}
