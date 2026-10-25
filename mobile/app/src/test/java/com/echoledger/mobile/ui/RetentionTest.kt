package com.echoledger.mobile.ui

import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.data.BinItemDto
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.StorageDto
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import java.time.Instant
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Keep, the 48-hour undo bin and the storage meter on the phone (B17). */
class RetentionTest : ScreenTest() {
    @Test
    fun `sizes and erase times read plainly`() {
        assertEquals("512 B", fileSize(512))
        assertEquals("1.5 KB", fileSize(1536))
        assertEquals("3.0 GB", fileSize(3L * 1024 * 1024 * 1024))
        val now = Instant.parse("2026-10-28T10:00:00Z")
        assertEquals("erased in 5 hours", eraseIn("2026-10-28T15:30:00+00:00", now))
        assertEquals("erased in 1 hour", eraseIn("2026-10-28T11:10:00+00:00", now))
        assertEquals("erased soon", eraseIn("2026-10-28T10:20:00+00:00", now))
        assertEquals("erased soon", eraseIn(null, now))
    }

    @Test
    fun `keep, the bin, restore, erase and storage go to the server`() {
        runBlocking { app.pairLocally(server) }
        val id = ServerFixtures.SESSION_ID
        server.on("PATCH", "/api/sessions/$id", FakeServer.json(200, """{"data":{}}"""))
        server.on(
            "GET",
            "/api/bin",
            FakeServer.json(
                200,
                """{"data":[{"id":"$id","title":"Acme kickoff","deleted_at":"2026-10-28T09:00:00+00:00","erase_at":"2026-10-30T09:00:00+00:00"}]}"""
            )
        )
        server.on("POST", "/api/sessions/$id/restore", FakeServer.json(200, """{"data":{}}"""))
        server.on("DELETE", "/api/bin/x", FakeServer.json(404, """{"code":"session_not_found","message":"Session not found."}"""))
        server.on(
            "GET",
            "/api/storage",
            FakeServer.json(
                200,
                """{"recordings_bytes":2048,"recordings_count":2,"disk_free_bytes":null,"disk_total_bytes":null,"retention_days":14}"""
            )
        )

        assertTrue(runBlocking { vm.organise(id, keep = true) })
        assertEquals("""{"keep":true}""", server.requestsTo("PATCH", "/api/sessions/$id").single().bodyText())
        assertEquals("Acme kickoff", runBlocking { vm.bin() }.single().title)
        assertTrue(runBlocking { vm.restore(id) })
        assertFalse(runBlocking { vm.eraseNow("x") })
        assertEquals("Session not found.", vm.message.value)
        val storage = runBlocking { vm.storage() }!!
        assertEquals(14, storage.retentionDays)
        assertNull(storage.diskTotalBytes)
    }

    @Test
    fun `recently deleted restores and erases after a second tap`() {
        val items = mutableListOf(
            BinItemDto("s1", "Acme kickoff", eraseAt = Instant.now().plusSeconds(5 * 3600 + 60).toString()),
            BinItemDto("s2", "Old call", NamedRef("c1", "Acme"), eraseAt = Instant.now().plusSeconds(30 * 3600).toString())
        )
        val restored = mutableListOf<String>()
        val erased = mutableListOf<String>()
        show {
            BinSection(load = { items.toList() }, restore = {
                restored += it
                items.removeAll { b -> b.id == it }
                true
            }, erase = {
                erased += it
                items.removeAll { b -> b.id == it }
                true
            }, onClose = {})
        }
        pumpUntil("the bin to load") { runCatching { compose.onNodeWithText("Acme kickoff").assertExists() }.isSuccess }
        compose.onNodeWithText("erased in 5 hours").assertExists()
        compose.onAllNodesWithTextFirst("Restore").performClick()
        pumpUntil("the first to be restored") { restored == listOf("s1") }
        compose.onNodeWithText("Delete now").performClick()
        assertTrue(erased.isEmpty())
        compose.onNodeWithText("Erase now").performClick()
        pumpUntil("the second to be erased") { erased == listOf("s2") }
        pumpUntil("the bin to be empty") {
            runCatching { compose.onNodeWithText("Nothing deleted in the last 48 hours.").assertExists() }.isSuccess
        }
    }

    @Test
    fun `storage shows the meter and the retention`() {
        show { StorageSection(load = { StorageDto(3L * 1024 * 1024 * 1024, 42, 25L * 1024 * 1024 * 1024, 100L * 1024 * 1024 * 1024, 14) }) }
        pumpUntil("storage to load") {
            runCatching { compose.onNodeWithText("Recordings on the server: 3.0 GB in 42 files.").assertExists() }.isSuccess
        }
        compose.onNodeWithContentDescription("Server disk used").assertExists()
        compose.onNodeWithText("Server disk 75% used · 25.0 GB free").assertExists()
    }

    private fun androidx.compose.ui.test.junit4.ComposeContentTestRule.onAllNodesWithTextFirst(text: String) =
        onAllNodes(androidx.compose.ui.test.hasText(text))[0]
}
