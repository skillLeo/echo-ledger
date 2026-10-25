package com.echoledger.mobile.ui

import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.core.Organise
import com.echoledger.mobile.core.SmartView
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import java.time.ZoneId
import java.time.ZonedDateTime
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Library organisation on the phone (B15): smart views, labels, folders, pins and bulk actions. */
class LibraryOrganiseTest : ScreenTest() {
    private val zone = ZoneId.of("Europe/London")

    // Wednesday 28 October 2026, 15:00 in London.
    private val now = ZonedDateTime.of(2026, 10, 28, 15, 0, 0, 0, zone)

    private fun item(
        id: String,
        startedAt: String = "2026-10-01T09:00:00+00:00",
        status: String = "completed",
        pinned: Boolean = false,
        labels: List<String> = emptyList(),
        markers: Int = 0,
        client: NamedRef? = null,
        finalStatus: String? = "completed",
        deleted: Boolean = false
    ) = LibraryItem(
        id = id,
        deleted = deleted,
        client = client,
        title = id,
        startedAt = startedAt,
        status = status,
        transcript = TranscriptSummary(finalStatus = finalStatus),
        updatedAt = "2026-10-28T10:00:00+00:00",
        markerCount = markers,
        pinned = pinned,
        labels = labels
    )

    // ---- pure -------------------------------------------------------------------------------------------------------

    @Test
    fun `each recording lands in the right smart views`() {
        val items = listOf(
            item("today", "2026-10-28T09:00:00+00:00"),
            item("monday", "2026-10-26T08:00:00+00:00", pinned = true),
            item("sunday", "2026-10-25T22:00:00+00:00", markers = 1),
            item("failed", status = "failed", finalStatus = null),
            item("tfailed", finalStatus = "failed"),
            item("waiting", "2026-10-28T14:00:00+00:00", status = "recording"),
            item("gone", deleted = true, pinned = true)
        )
        fun ids(view: SmartView) = items.filter { Organise.inView(it, view, now) }.map { it.id }

        assertEquals(listOf("today", "waiting"), ids(SmartView.TODAY))
        assertEquals(listOf("today", "monday", "waiting"), ids(SmartView.THIS_WEEK))
        assertEquals(listOf("sunday"), ids(SmartView.BOOKMARKED))
        assertEquals(listOf("failed", "tfailed"), ids(SmartView.FAILED))
        assertEquals(listOf("waiting"), ids(SmartView.WAITING))
        assertEquals(listOf("monday"), ids(SmartView.PINNED))
        assertEquals(6, Organise.counts(items, now)[SmartView.ALL])
    }

    @Test
    fun `labels are listed once, cleaned like the server and capped`() {
        val items =
            listOf(
                item("a", labels = listOf("Invoice", "Q4")),
                item("b", labels = listOf("invoice")),
                item("c", deleted = true, labels = listOf("Gone"))
            )
        assertEquals(listOf("Invoice", "Q4"), Organise.labels(items))
        assertTrue(Organise.hasLabel(items[1], "INVOICE"))
        assertEquals("Follow up", Organise.cleanLabel("  Follow \u0007 up "))
        assertNull(Organise.cleanLabel("x".repeat(41)))
        assertNull(Organise.withLabel(listOf("Invoice"), " invoice "))
        assertNull(Organise.withLabel((1..20).map { "l$it" }, "more"))
        assertEquals(listOf("Invoice", "Q4"), Organise.withLabel(listOf("Invoice"), "Q4"))
    }

    @Test
    fun `folders come from recordings and the server, pinned first`() {
        val acme = NamedRef("c1", "Acme Holdings")
        val items = listOf(item("a", client = acme), item("b"))
        assertTrue(Organise.inFolder(items[0], "c1") && !Organise.inFolder(items[1], "c1"))
        assertTrue(Organise.inFolder(items[1], "") && Organise.inFolder(items[0], null))
        val folders = Organise.folders(
            items,
            listOf(ClientDto("c2", "Zeta", pinned = true), ClientDto("c1", "Acme Holdings", avatar = "🦊"))
        )
        assertEquals(listOf("Zeta", "Acme Holdings"), folders.map { it.name })
        assertEquals("🦊", Organise.badge("Acme Holdings", "🦊"))
        assertEquals("AH", Organise.badge("Acme Holdings", null))
        assertEquals("NO", Organise.badge("northwind", " "))
    }

    // ---- API --------------------------------------------------------------------------------------------------------

    @Test
    fun `organising a recording patches only what changed, then syncs`() {
        runBlocking { app.pairLocally(server) }
        val id = ServerFixtures.SESSION_ID
        server.on("PATCH", "/api/sessions/$id", FakeServer.json(200, """{"data":{}}"""))

        assertTrue(runBlocking { vm.organise(id, pinned = true, labels = listOf("Invoice")) })
        assertTrue(runBlocking { vm.organise(id, clearClient = true) })

        val bodies = server.requestsTo("PATCH", "/api/sessions/$id").map { it.bodyText() }
        assertEquals("""{"pinned":true,"labels":["Invoice"]}""", bodies[0])
        assertEquals("""{"client_id":null}""", bodies[1])
    }

    @Test
    fun `bulk actions send the ids and say how many changed`() {
        runBlocking { app.pairLocally(server) }
        server.on("POST", "/api/sessions/bulk", FakeServer.json(200, """{"changed":2}"""))

        assertEquals(2, runBlocking { vm.bulk(listOf("a", "b"), "label", label = "Q4") })

        val body = server.requestsTo("POST", "/api/sessions/bulk").single().bodyText()
        assertEquals("""{"ids":["a","b"],"action":"label","client_id":null,"label":"Q4"}""", body)
        assertEquals("2 recordings updated", vm.message.value)
    }

    @Test
    fun `a failed bulk action keeps the server message`() {
        runBlocking { app.pairLocally(server) }
        server.on("POST", "/api/sessions/bulk", FakeServer.json(404, """{"message":"Client not found.","code":"client_not_found"}"""))

        assertNull(runBlocking { vm.bulk(listOf("a"), "move", clientId = "01JCLIENT00000000000000000") })
        assertEquals("Client not found.", vm.message.value)
    }

    @Test
    fun `the selection exports as one zip in the private cache and folders can be created`() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "POST",
            "/api/sessions/export",
            mockwebserver3.MockResponse.Builder().code(
                200
            ).addHeader("Content-Disposition", "attachment; filename=\"EchoLedger transcripts 2026-10-28.zip\"").body("PK").build()
        )
        server.on("POST", "/api/clients", FakeServer.json(201, """{"data":{"id":"c9","name":"Globex","avatar":"GL"}}"""))

        val file = runBlocking { vm.exportSelected(listOf("a"), "docx") }!!
        assertEquals("EchoLedger transcripts 2026-10-28.zip", file.name)
        assertEquals("exports", file.parentFile!!.name)
        assertTrue(server.requestsTo("POST", "/api/sessions/export").single().bodyText().contains("\"format\":\"docx\""))

        assertEquals("GL", runBlocking { vm.createClient(" Globex ") }!!.avatar)
        assertTrue(vm.clients.value.any { it.id == "c9" })
        assertTrue(server.requestsTo("POST", "/api/clients").single().bodyText().contains("\"name\":\"Globex\""))
    }

    // ---- screens ----------------------------------------------------------------------------------------------------

    @Test
    fun `smart view chips show counts and pick a view`() {
        var picked: SmartView? = null
        show { SmartViewChips(mapOf(SmartView.PINNED to 3, SmartView.FAILED to 0), SmartView.ALL) { picked = it } }
        compose.onNodeWithText("Pinned 3").performClick()
        compose.onNodeWithText("Failed").assertExists()
        assertEquals(SmartView.PINNED, picked)
    }

    @Test
    fun `the organise section pins, moves and edits labels`() {
        val events = mutableListOf<String>()
        val current = item("a", pinned = false, labels = listOf("Invoice"), client = NamedRef("c1", "Acme Holdings", avatar = "AH"))
        show {
            OrganiseSection(
                current,
                listOf(ClientDto("c1", "Acme Holdings", avatar = "AH"), ClientDto("c2", "Northwind")),
                listOf("Invoice", "Q4"),
                onPin = { events += "pin $it" },
                onMove = { events += "move $it" },
                onCreateClient = { events += "create $it" },
                onLabels = { events += "labels $it" }
            )
        }
        compose.onNodeWithContentDescription("Pin").performClick()
        compose.onNodeWithText("NO · Northwind").performClick()
        compose.onNodeWithText("No client").performClick()
        compose.onNodeWithContentDescription("Remove label Invoice").performClick()
        compose.onNodeWithText("+ Q4").performClick()
        compose.onNodeWithText("Add a label").performTextInput(" Follow up ")
        compose.onNodeWithText("Add").performClick()
        compose.onNodeWithText("New client…").performClick()
        compose.onNodeWithText("Client name").performTextInput("Globex")
        compose.onNodeWithText("Create").performClick()

        assertEquals(
            listOf("pin true", "move c2", "move null", "labels []", "labels [Invoice, Q4]", "labels [Invoice, Follow up]", "create Globex"),
            events
        )
    }

    @Test
    fun `the bulk panel runs each action and asks before deleting`() {
        val events = mutableListOf<String>()
        show {
            BulkPanel(
                count = 2,
                folders = listOf(ClientDto("c1", "Acme Holdings")),
                onMove = { events += "move $it" },
                onCreateClient = { events += "create $it" },
                onLabel = { l, add -> events += "label $l $add" },
                onPin = { events += "pin $it" },
                onExport = { events += "export $it" },
                onDelete = { events += "delete" },
                onDone = { events += "done" }
            )
        }
        compose.onNodeWithText("2 selected").assertExists()
        compose.onNodeWithText("AH · Acme Holdings").performClick()
        compose.onNodeWithText("Label").performTextInput("Q4")
        compose.onNodeWithText("Add label").performClick()
        compose.onNodeWithText("Remove").performClick()
        compose.onNodeWithText("Unpin").performClick()
        compose.onNodeWithText("Text").performClick()
        compose.onNodeWithText("Export as zip").performClick()
        compose.onNodeWithText("Delete").performClick()
        assertFalse(events.contains("delete"))
        compose.onNodeWithText("Delete 2 (restorable for 48 hours)").performClick()
        compose.onNodeWithText("Done").performClick()

        assertEquals(listOf("move c1", "label Q4 true", "label Q4 false", "pin false", "export txt", "delete", "done"), events)
    }
}
