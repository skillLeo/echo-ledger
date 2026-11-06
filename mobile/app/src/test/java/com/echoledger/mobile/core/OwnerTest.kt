package com.echoledger.mobile.core

import com.echoledger.mobile.data.LocalRecording
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Whose waiting recordings these are (since v1.5, the desktop app's rule): they upload only for the account that made them. */
class OwnerTest {
    private val ana = Owner.of("https://ledger.example.com/", " Ana@Example.com ")
    private val omar = Owner.of("https://ledger.example.com", "omar@example.com")
    private val serverOnly = Owner.of("https://ledger.example.com", null)

    private fun rec(id: String, owner: Owner? = null) = LocalRecording(
        id = id, sourceApp = "voice_note", startedAt = "2026-11-06T10:00:00Z", endedAt = "2026-11-06T10:01:00Z", durationMs = 60_000,
        mime = "audio/ogg", codec = "opus", channels = 1, sampleRate = 48_000, plainSize = 1, sha256 = "x", state = "queued", owner = owner
    )

    @Test
    fun `an account is its server and its email, written one way`() {
        assertEquals(Owner("https://ledger.example.com", "ana@example.com"), ana)
        assertEquals("ana@example.com", ana.label())
        assertEquals("ledger.example.com", serverOnly.label())
    }

    @Test
    fun `a recording uploads only for the account that made it, on its server`() {
        assertTrue(Owner.mayUpload(null, ana))
        assertTrue(Owner.mayUpload(ana, Owner.of("https://LEDGER.example.com", "ana@example.com")))
        assertFalse(Owner.mayUpload(ana, omar))
        assertFalse(Owner.mayUpload(ana, Owner.of("https://other.example.com", "ana@example.com")))
        // A known account never uploads while the one signed in is unknown; a server-only one goes with its server.
        assertFalse(Owner.mayUpload(ana, serverOnly))
        assertTrue(Owner.mayUpload(serverOnly, ana))
        assertFalse(Owner.mayUpload(ana, null))
        assertNull(Owner.heldFor(null, omar))
        assertEquals(ana, Owner.heldFor(ana, omar))
    }

    @Test
    fun `signing in as someone else stamps the recordings made before with the account they were made under`() {
        val recordings = listOf(rec("a"), rec("b"), rec("c", owner = omar))
        val stamped = Owner.stamp(recordings, previous = ana, next = omar)
        assertEquals(listOf("a", "b"), stamped.map { it.id })
        assertTrue(stamped.all { it.owner == ana })
        // The same account again, a first sign-in, or an install from before v1.5 on the same server: nothing changes.
        assertTrue(Owner.stamp(recordings, previous = ana, next = Owner.of("https://ledger.example.com", "ANA@example.com")).isEmpty())
        assertTrue(Owner.stamp(recordings, previous = null, next = omar).isEmpty())
        assertTrue(Owner.stamp(recordings, previous = serverOnly, next = omar).isEmpty())
    }

    @Test
    fun `the Record tab says who they wait for`() {
        assertEquals(
            "2 recordings wait for ana@example.com: sign in as that account to send them. They stay on this phone until then.",
            Owner.waitingLine(2, ana)
        )
        assertTrue(Owner.waitingLine(1, ana).startsWith("1 recording waits"))
        assertEquals("Discard these 2 recordings?", Owner.discardTitle(2))
        assertEquals("Discard this recording?", Owner.discardTitle(1))
        assertTrue(Owner.discardLine(ana).contains("ana@example.com"))
    }
}
