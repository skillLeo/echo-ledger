package com.echoledger.mobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** Phase 1C B1–B3: 11 kinds, one channel each, their switches, and quiet hours that never hold back a critical alert. */
class NotifyCategoryTest {
    @Test
    fun `eleven kinds with their own channels, and only readiness is critical`() {
        assertEquals(11, NotifyCategory.entries.size)
        assertEquals(11, NotifyCategory.entries.map { it.channelId }.toSet().size)
        assertEquals(listOf(NotifyCategory.READINESS), NotifyCategory.entries.filter { it.critical })
        assertEquals(
            listOf(
                "Recording", "Transcription", "Enhancement", "Uploads", "Call capture & readiness", "Sharing",
                "Account & security", "Usage & limits", "Live Translate", "App updates", "Other"
            ),
            NotifyCategory.entries.map { it.title }
        )
    }

    @Test
    fun `server activity goes to its kind, unknown kinds to Other`() {
        assertEquals(NotifyCategory.TRANSCRIPTION, NotifyCategory.forActivity("transcript_ready"))
        assertEquals(NotifyCategory.TRANSCRIPTION, NotifyCategory.forActivity("transcript_failed"))
        assertEquals(NotifyCategory.UPLOADS, NotifyCategory.forActivity("upload_failed"))
        assertEquals(NotifyCategory.ACCOUNT, NotifyCategory.forActivity("device_paired"))
        assertEquals(NotifyCategory.USAGE, NotifyCategory.forActivity("budget_reached"))
        assertEquals(NotifyCategory.SHARING, NotifyCategory.forActivity("shared_with_you"))
        assertEquals(NotifyCategory.OTHER, NotifyCategory.forActivity("something_new"))
        assertEquals(NotifyCategory.OTHER, NotifyCategory.forActivity(null))
    }

    @Test
    fun `quiet hours hold back everything but critical, and a switch turns a kind off`() {
        val night = 23 * 60
        fun allowed(c: NotifyCategory, off: List<String> = emptyList(), at: Int = night) =
            NotifyCategory.allowed(c, off, quietHours = true, quietFrom = "22:00", quietTo = "07:00", now = at)

        assertFalse(allowed(NotifyCategory.TRANSCRIPTION))
        assertTrue(allowed(NotifyCategory.READINESS))
        assertTrue(allowed(NotifyCategory.TRANSCRIPTION, at = 12 * 60))
        assertFalse(allowed(NotifyCategory.SHARING, off = listOf("sharing"), at = 12 * 60))
        assertFalse("switched off even if critical", allowed(NotifyCategory.READINESS, off = listOf("readiness")))
    }
}
