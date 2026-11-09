package com.echoledger.mobile.core

import com.echoledger.mobile.privilege.Capabilities
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Phase 1C A1–A3, A6: what the Ready check says, which fix it offers, and what raises the critical alert. */
class ReadinessTest {
    private fun caps(helper: Boolean = true, sdk: Int = 35, mic: Boolean = true, notifications: Boolean = true, inBuild: Boolean = true) =
        Capabilities(sdk, helper, mic, notifications, overlayPermission = true, helperInBuild = inBuild)

    private fun facts(
        caps: Capabilities = caps(),
        autoRecord: Boolean = true,
        shizukuInstalled: Boolean = true,
        shizukuRunning: Boolean = true,
        shizukuAllowed: Boolean = true,
        seen: Boolean = true,
        stale: Boolean = false,
        arm: String? = "armed",
        signedIn: Boolean = true,
        freeMb: Long = 10_000,
        battery: Boolean = true,
        oneSided: String? = null,
        callerNames: Boolean = true
    ) = ReadyFacts(
        caps,
        autoRecord,
        shizukuInstalled,
        shizukuRunning,
        shizukuAllowed,
        seen,
        stale,
        arm,
        signedIn,
        freeMb,
        battery,
        oneSided,
        callerNames
    )

    @Test
    fun `everything in place is Ready, with nothing to fix`() {
        val r = Readiness.evaluate(facts())
        assertEquals(ReadyChip.READY, r.chip)
        assertEquals("Calls: Ready", r.chip.text)
        assertEquals("Ready to record calls", r.headline)
        assertTrue(r.problems.isEmpty())
    }

    @Test
    fun `after a restart Shizuku is down so the helper stopped, Open Shizuku, critical`() {
        val r = Readiness.evaluate(facts(caps(helper = false), shizukuRunning = false, shizukuAllowed = false))
        val p = r.problems.single()
        assertEquals("helper_after_restart", p.key)
        assertEquals("The helper stopped when the phone restarted", p.title)
        assertEquals(ReadyFix.OPEN_SHIZUKU, p.fix)
        assertTrue(p.critical)
        assertEquals(ReadyChip.NOT_READY, r.chip)
        assertEquals("Not ready — Fix", r.chip.text)
        assertFalse("never 'one-time setup' for a phone that was set up", p.detail.contains("one-time"))
    }

    @Test
    fun `a helper killed while Shizuku stays up starts again with one tap`() {
        val p = Readiness.evaluate(facts(caps(helper = false))).problems.single()
        assertEquals("helper_stopped", p.key)
        assertEquals(ReadyFix.START_HELPER, p.fix)
        assertTrue(p.critical)
    }

    @Test
    fun `a phone never set up is offered the setup, and nothing alerts`() {
        val r = Readiness.evaluate(
            facts(
                caps(helper = false),
                autoRecord = false,
                shizukuInstalled = false,
                shizukuRunning = false,
                shizukuAllowed = false,
                seen = false
            )
        )
        val p = r.problems.first()
        assertEquals("helper_not_set_up", p.key)
        assertEquals(ReadyFix.SET_UP, p.fix)
        assertTrue(r.critical.isEmpty())
    }

    @Test
    fun `without Shizuku a restart needs the computer command again`() {
        val p = Readiness.evaluate(facts(caps(helper = false), shizukuInstalled = false, shizukuRunning = false, shizukuAllowed = false))
            .problems.single()
        assertEquals("helper_after_restart", p.key)
        assertEquals(ReadyFix.SET_UP, p.fix)
    }

    @Test
    fun `Shizuku that no longer allows EchoLedger asks for it`() {
        val p = Readiness.evaluate(facts(caps(helper = false), shizukuAllowed = false)).problems.single()
        assertEquals(ReadyFix.ALLOW_SHIZUKU, p.fix)
    }

    @Test
    fun `a microphone taken away is critical while auto-record is on`() {
        val p = Readiness.evaluate(facts(caps(mic = false))).problems.single { it.key == "microphone" }
        assertTrue(p.critical)
        assertEquals(ReadyFix.MICROPHONE, p.fix)
        assertFalse(Readiness.evaluate(facts(caps(mic = false), autoRecord = false)).problems.single { it.key == "microphone" }.critical)
    }

    @Test
    fun `a nearly full phone blocks, with its free space`() {
        val p = Readiness.evaluate(facts(freeMb = 120)).problems.single()
        assertEquals("storage", p.key)
        assertTrue(p.detail.startsWith("120 MB free"))
        assertTrue(p.blocking)
    }

    @Test
    fun `a helper that answered the arm with an error must be restarted`() {
        val p = Readiness.evaluate(facts(arm = "error: no voice mix")).problems.single()
        assertEquals("helper_not_armed", p.key)
        assertEquals(ReadyFix.RESTART_HELPER, p.fix)
        assertTrue(p.detail.contains("no voice mix"))
    }

    @Test
    fun `warnings leave the chip Ready`() {
        val r = Readiness.evaluate(
            facts(caps(notifications = false), battery = false, signedIn = false, stale = true, oneSided = "The line was silent.")
        )
        assertEquals(ReadyChip.READY, r.chip)
        assertEquals(
            setOf("notifications", "battery", "sign_in", "helper_old", "one_sided"),
            r.problems.map { it.key }.toSet()
        )
        assertTrue(r.critical.isEmpty())
    }

    @Test
    fun `auto-record off shows Calls Off with a Turn on`() {
        val r = Readiness.evaluate(facts(autoRecord = false))
        assertEquals(ReadyChip.OFF, r.chip)
        assertEquals(ReadyFix.TURN_ON, r.problems.single().fix)
    }

    @Test
    fun `a phone that cannot record calls shows no chip`() {
        assertEquals(ReadyChip.NONE, Readiness.evaluate(facts(caps(sdk = 30))).chip)
        assertEquals(ReadyChip.NONE, Readiness.evaluate(facts(caps(inBuild = false))).chip)
    }

    @Test
    fun `Android 12 and 13 say your side only`() {
        val p = Readiness.evaluate(
            facts(
                caps(helper = false, sdk = 32),
                shizukuInstalled = false,
                shizukuRunning = false,
                shizukuAllowed = false,
                seen = false,
                autoRecord = false
            )
        )
            .problems.first()
        assertTrue(p.detail.contains("only your voice"))
    }

    @Test
    fun `the after-call check finds only your voice and the backup start waits 5 s`() {
        assertNull(AfterCallCheck.oneSided(2, -20.0, -30.0, null))
        assertNull("one track by design", AfterCallCheck.oneSided(1, -20.0, -120.0, null))
        assertNull("no sound at all is the no-audio case", AfterCallCheck.oneSided(2, -120.0, -120.0, null))
        val why = AfterCallCheck.oneSided(2, -20.0, -120.0, "WhatsApp gave no far-side sound.")!!
        assertTrue(why.startsWith("WhatsApp gave no far-side sound. Run the Ready check"))
        assertTrue(AfterCallCheck.oneSided(2, -20.0, -90.0, null)!!.startsWith("The other side gave no sound."))

        assertFalse(AfterCallCheck.backupDue(1_000, 5_999, recording = false, helperReady = true, alreadyTried = false))
        assertTrue(AfterCallCheck.backupDue(1_000, 6_000, recording = false, helperReady = true, alreadyTried = false))
        assertFalse(AfterCallCheck.backupDue(1_000, 9_000, recording = true, helperReady = true, alreadyTried = false))
        assertFalse(AfterCallCheck.backupDue(1_000, 9_000, recording = false, helperReady = false, alreadyTried = false))
        assertFalse(AfterCallCheck.backupDue(1_000, 9_000, recording = false, helperReady = true, alreadyTried = true))
    }

    @Test
    fun `without notification access calls stay ready but the check says names are missing (v1_6_1)`() {
        val r = Readiness.evaluate(facts(callerNames = false))
        val p = r.problems.single { it.key == "caller_names" }
        assertEquals(ReadyFix.CALLER_NAMES, p.fix)
        assertEquals(false, p.blocking)
        assertEquals(ReadyChip.READY, r.chip)
        assertEquals(null, Readiness.evaluate(facts()).problems.firstOrNull { it.key == "caller_names" })
    }
}
