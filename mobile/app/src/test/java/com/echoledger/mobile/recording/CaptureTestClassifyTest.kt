package com.echoledger.mobile.recording

import com.echoledger.mobile.recording.CaptureTest.Check
import com.echoledger.mobile.recording.CaptureTest.Measured
import com.echoledger.mobile.recording.CaptureTest.Verdict
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class CaptureTestClassifyTest {
    private val working = Measured(
        helperConnected = true,
        whatsappBusiness = true,
        started = true,
        meBytes = 320_000,
        clientBytes = 320_000,
        mePeak = 0.2f,
        clientPeak = 0.1f,
        inCall = true,
        fileBytes = 40_000
    )

    private fun stage(result: CaptureTest.Result, check: Check) = result.stages.single { it.check == check }

    @Test
    fun `a stopped helper is the first and only thing reported, with how to start it`() {
        val r = CaptureTest.classify(Measured(helperConnected = false))
        assertEquals(Verdict.NOT_READY, r.verdict)
        assertEquals(listOf(Check.HELPER), r.stages.map { it.check })
        assertTrue(r.fix!!.contains("Shizuku"))
    }

    @Test
    fun `no sound at all says calls would be saved empty and names other recorders`() {
        val r = CaptureTest.classify(
            working.copy(
                meBytes = 0,
                clientBytes = 0,
                mePeak = 0f,
                clientPeak = 0f,
                fileBytes = 0,
                helperProblem = "mic source 7: did not start"
            )
        )
        assertEquals(Verdict.NOT_READY, r.verdict)
        assertTrue(r.headline.contains("empty"))
        assertTrue(r.fix!!.contains("CallVault"))
        assertTrue(r.fix!!.contains("mic source 7: did not start"))
        assertEquals(false, stage(r, Check.MY_VOICE).ok)
    }

    @Test
    fun `a refused start is not ready even when the app list is fine`() {
        assertEquals(Verdict.NOT_READY, CaptureTest.classify(working.copy(started = false)).verdict)
    }

    @Test
    fun `silent frames from a muted microphone are partly ready`() {
        val r = CaptureTest.classify(working.copy(mePeak = 0f))
        assertEquals(Verdict.PARTLY, r.verdict)
        assertEquals("Sound arrived but it was silent.", stage(r, Check.MY_VOICE).detail)
    }

    @Test
    fun `outside a call the other side is not judged`() {
        val r = CaptureTest.classify(working.copy(inCall = false, clientPeak = 0f))
        assertEquals(Verdict.READY, r.verdict)
        assertNull(stage(r, Check.OTHER_SIDE).ok)
        assertTrue(r.headline.contains("during a WhatsApp call"))
    }

    @Test
    fun `during a call a silent other side is reported`() {
        val r = CaptureTest.classify(working.copy(clientPeak = 0f))
        assertEquals(Verdict.PARTLY, r.verdict)
        assertTrue(r.headline.contains("other side"))
    }

    @Test
    fun `an old helper still running is flagged even when sound works`() {
        val r = CaptureTest.classify(working.copy(helperStale = true))
        assertEquals(Verdict.PARTLY, r.verdict)
        assertEquals(false, stage(r, Check.HELPER).ok)
    }

    @Test
    fun `everything working is ready with both sides heard`() {
        val r = CaptureTest.classify(working)
        assertEquals(Verdict.READY, r.verdict)
        assertNull(r.fix)
        assertTrue(r.stages.all { it.ok == true })
        assertEquals("WhatsApp Business found.", stage(r, Check.APP).detail)
    }
}
