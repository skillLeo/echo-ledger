package com.echoledger.mobile.core

import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.ConfigWorkspaceDto
import com.echoledger.mobile.data.PlanDto
import com.echoledger.mobile.data.ProjectScreensResponse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** The workspace plan and the role, from the server's config (since v1.5, docs/API.md "Plans and features"). */
class PlanStateTest {
    @Test
    fun `an older server says nothing, so everything stays on`() {
        val state = PlanState.from(null, null)
        assertTrue(state.liveTranslate)
        assertTrue(state.aiNotes)
        assertFalse(state.viewer)
        assertNull(state.summary())
        assertNull(state.membership())
    }

    @Test
    fun `the plan's features, limits and the role come from the config`() {
        val plan = ApiJson.decodeFromString(
            PlanDto.serializer(),
            """{"key":"free","name":"Free","features":{"live_translate":false,"screen_moments":true,"ai_notes":false,"connector":true},
               "limits":{"monthly_minutes":300,"storage_bytes":5368709120,"retention_days":30,"seats":1},"used":{"minutes":120,"storage_bytes":4}}"""
        )
        val workspace = ApiJson.decodeFromString(ConfigWorkspaceDto.serializer(), """{"id":1,"name":"Acme team","role":"viewer"}""")
        val state = PlanState.from(plan, workspace)
        assertFalse(state.liveTranslate)
        assertFalse(state.aiNotes)
        assertTrue(state.viewer)
        assertEquals("Free · 120 of 300 min this month", state.summary())
        assertEquals("Acme team · Viewer", state.membership())
        assertEquals(
            "You are a viewer in Acme team: you can open what is shared with you, not record.",
            PlanState.viewerText(state.workspace)
        )
    }

    @Test
    fun `a feature the server leaves out counts as on, and a plan without a minutes limit shows only its name`() {
        val plan = ApiJson.decodeFromString(PlanDto.serializer(), """{"name":"Personal","features":{"live_translate":true}}""")
        val state = PlanState.from(plan, ConfigWorkspaceDto(name = "Personal", role = "owner"))
        assertTrue(state.aiNotes)
        assertEquals("Personal", state.summary())
        assertEquals("Personal · Owner", state.membership())
        assertEquals("You are a viewer in this workspace: you can open what is shared with you, not record.", PlanState.viewerText(null))
    }

    @Test
    fun `a project's screens page reads with the recording each screen belongs to`() {
        val page = ApiJson.decodeFromString(
            ProjectScreensResponse.serializer(),
            """{"data":[{"id":"sc1","index":7,"at_ms":872000,"width":1920,"height":1080,"trigger":"change","ocr_text":null,
               "description":"The invoice page","image_url":"https://x/i","thumb_url":"https://x/t","session_id":"s1",
               "session_title":"Call with Omar","session_started_at":"2026-11-03T10:00:00Z"}],"next_cursor":"c2"}"""
        )
        assertEquals("c2", page.nextCursor)
        val shown = page.data.single()
        assertEquals("s1", shown.sessionId)
        assertEquals(7, shown.screen().index)
        assertEquals("Screen 7 · 14:32", ScreenMoments.label(shown.screen()))
    }
}
