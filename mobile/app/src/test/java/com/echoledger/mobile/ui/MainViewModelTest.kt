package com.echoledger.mobile.ui

import android.os.Build
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.core.PairingPayload
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.widget.WidgetStateStore
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import mockwebserver3.SocketEffect
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** The state holder behind every screen, driven against the real stores and a scripted server. */
class MainViewModelTest : AppTest() {
    private lateinit var vm: MainViewModel

    @Before
    fun createViewModel() {
        vm = MainViewModel(app)
    }

    private val token = "7|eld_vmtoken"

    private fun serveRegistration(pins: List<String> = listOf(ServerFixtures.PIN_A, ServerFixtures.PIN_B), deviceName: String = "Pixel 9") {
        server.on("POST", "/api/devices/register", FakeServer.json(201, ServerFixtures.registerResponse(token, pins, deviceName)))
    }

    private fun serveLibrary(vararg items: String, time: String = "2026-10-24T09:05:00+00:00") {
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(items.toList(), null, time)))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
    }

    private fun awaitMessage(): String {
        pumpUntil("a message") { vm.message.value != null }
        return vm.message.value!!
    }

    private fun awaitNotBusy() = pumpUntil("pairing to finish") { !vm.busy.value }

    private fun settings() = runBlocking { app.graph.settings.current() }

    private fun storedToken() = runBlocking { app.graph.settings.token() }

    private fun pairLocally() = runBlocking { app.pairLocally(server) }

    private fun syncNow() {
        val job = vm.refresh()
        pumpUntil("sync to finish") { job.isCompleted }
    }

    private fun workStates(recordingId: String): List<WorkInfo.State> =
        WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-$recordingId").get(10, TimeUnit.SECONDS).map { it.state }

    private fun libraryItem(id: String) = LibraryItem(id = id, updatedAt = "2026-10-24T09:00:00+00:00")

    // ---- pairing -----------------------------------------------------------------------------------------------------

    @Test
    fun `pairing with a one-time code registers the phone stores the token and syncs the library`() {
        serveRegistration()
        serveLibrary(ServerFixtures.libraryItem("01JA"), ServerFixtures.libraryItem("01JB", sourceDevice = "desktop"))

        vm.pair(server.baseUrl, "7KQ2-M9XD", "Sam's Pixel")
        val message = awaitMessage()
        pumpUntil("the library to load") { vm.library.value.size == 2 && vm.clients.value.size == 2 }

        assertTrue(message, message.startsWith("Paired with"))
        val registration = server.requestsTo("POST", "/api/devices/register").single()
        val body = Json.parseToJsonElement(registration.bodyText()).jsonObject
        assertEquals("7KQ2-M9XD", body["pairing_code"]!!.jsonPrimitive.content)
        assertEquals("Sam's Pixel", body["name"]!!.jsonPrimitive.content)
        assertEquals("android", body["platform"]!!.jsonPrimitive.content)
        assertEquals(BuildConfig.VERSION_NAME, body["app_version"]!!.jsonPrimitive.content)
        assertEquals(Build.VERSION.RELEASE, body["os_version"]!!.jsonPrimitive.content)
        assertNull("registration is unauthenticated", registration.headers["Authorization"])

        assertEquals(token, storedToken())
        val saved = settings()
        assertEquals(server.baseUrl, saved.serverUrl)
        assertEquals(ServerFixtures.DEVICE_ID, saved.deviceId)
        assertEquals("Pixel 9", saved.deviceName)
        assertEquals(listOf(ServerFixtures.PIN_A, ServerFixtures.PIN_B), saved.pins)
        assertTrue(saved.paired)
        assertEquals(
            "the library request uses the new token",
            "Bearer $token",
            server.requestsTo("GET", "/api/library").first().headers["Authorization"]
        )
        assertFalse(vm.busy.value)
    }

    @Test
    fun `a blank phone name defaults to the manufacturer and model`() {
        serveRegistration()
        serveLibrary()

        vm.pair(server.baseUrl, "ABCD-1234", "   ")
        awaitMessage()

        val name = Json.parseToJsonElement(
            server.requestsTo("POST", "/api/devices/register").single().bodyText()
        ).jsonObject["name"]!!.jsonPrimitive.content
        assertEquals("${Build.MANUFACTURER} ${Build.MODEL}".trim(), name)
        assertTrue(name.isNotBlank())
    }

    @Test
    fun `address and code are trimmed and a trailing slash on the address is dropped`() {
        serveRegistration()
        serveLibrary()

        vm.pair("  ${server.baseUrl}/  ", "  7KQ2-M9XD \n", "")
        awaitMessage()

        assertEquals(1, server.requestsTo("POST", "/api/devices/register").size)
        assertEquals(
            "7KQ2-M9XD",
            Json.parseToJsonElement(server.requests.first().bodyText()).jsonObject["pairing_code"]!!.jsonPrimitive.content
        )
        assertEquals(server.baseUrl, settings().serverUrl)
    }

    @Test
    fun `a wrong or used code shows the server reason and leaves the phone unpaired`() {
        server.on(
            "POST",
            "/api/devices/register",
            FakeServer.json(422, ServerFixtures.validationError("This pairing code is invalid, expired, or already used."))
        )

        vm.pair(server.baseUrl, "ZZZZ-ZZZZ", "")
        val message = awaitMessage()
        awaitNotBusy()

        assertEquals("This pairing code is invalid, expired, or already used.", message)
        assertFalse(settings().paired)
        assertNull(storedToken())
        assertTrue(vm.library.value.isEmpty())
    }

    @Test
    fun `a server that is not reachable gives a message and leaves the phone unpaired`() {
        val closed = FakeServer()
        val address = closed.baseUrl
        closed.close()

        vm.pair(address, "ABCD-1234", "")
        val message = awaitMessage()
        awaitNotBusy()

        assertTrue(message.isNotBlank())
        assertFalse(settings().paired)
        assertNull(storedToken())
    }

    @Test
    fun `a rate limited registration reports the server answer`() {
        server.on(
            "POST",
            "/api/devices/register",
            MockResponse.Builder().code(
                429
            ).addHeader(
                "Retry-After",
                "60"
            ).addHeader("Content-Type", "application/json").body("""{"message":"Too Many Attempts."}""").build()
        )

        vm.pair(server.baseUrl, "ABCD-1234", "")

        assertEquals("Too Many Attempts.", awaitMessage())
        assertFalse(settings().paired)
    }

    @Test
    fun `plain http to a remote host is refused before anything is sent`() {
        vm.pair("http://ledger.example.com", "ABCD-1234", "")

        assertEquals("The server address must use https://", awaitMessage())
        awaitNotBusy()
        assertFalse(settings().paired)
    }

    @Test
    fun `the untouched https placeholder is refused with a clear message`() {
        vm.pair("https://", "ABCD-1234", "")

        assertEquals("The server address must use https://", awaitMessage())
        awaitNotBusy()
    }

    @Test
    fun `an address typed without a scheme is refused with a clear message`() {
        vm.pair("ledger.example.com", "ABCD-1234", "")

        assertEquals("The server address must use https://", awaitMessage())
    }

    @Test
    fun `a pasted pairing payload pairs with the address and pins it carries`() {
        serveRegistration(pins = emptyList())
        serveLibrary()
        val payload = """{"v":1,"url":"${server.baseUrl}","code":"7KQ2M9XD","pins":["${ServerFixtures.PIN_A}"]}"""

        vm.pair("https://ignored.example.com", payload, "")
        awaitMessage()

        assertEquals(
            "7KQ2M9XD",
            Json.parseToJsonElement(
                server.requestsTo("POST", "/api/devices/register").single().bodyText()
            ).jsonObject["pairing_code"]!!.jsonPrimitive.content
        )
        val saved = settings()
        assertEquals("the typed address is ignored in favour of the payload", server.baseUrl, saved.serverUrl)
        assertEquals("pins from the QR are kept when the server sends none", listOf(ServerFixtures.PIN_A), saved.pins)
    }

    @Test
    fun `pins delivered by the server replace the ones from the QR`() {
        serveRegistration(pins = listOf(ServerFixtures.PIN_B))
        serveLibrary()

        vm.pairWith(PairingPayload(server.baseUrl, "7KQ2M9XD", listOf(ServerFixtures.PIN_A)), "")
        awaitMessage()

        assertEquals(listOf(ServerFixtures.PIN_B), settings().pins)
    }

    @Test
    fun `a pasted text that is not a pairing payload is rejected without a request`() {
        vm.pair(server.baseUrl, """{"hello":"world"}""", "")

        assertEquals(MainViewModel.NOT_A_PAIRING_QR, awaitMessage())
        assertTrue(server.requests.isEmpty())
        assertFalse(settings().paired)
    }

    @Test
    fun `busy is set while the registration is in flight and cleared afterwards`() {
        server.on(
            "POST",
            "/api/devices/register",
            MockResponse.Builder().code(
                201
            ).addHeader(
                "Content-Type",
                "application/json"
            ).body(ServerFixtures.registerResponse(token)).headersDelay(600, TimeUnit.MILLISECONDS).build()
        )
        serveLibrary()

        vm.pair(server.baseUrl, "ABCD-1234", "")

        assertTrue("busy must be visible immediately", vm.busy.value)
        awaitMessage()
        assertFalse(vm.busy.value)
    }

    // ---- library sync ----------------------------------------------------------------------------------------------------

    @Test
    fun `refresh loads every page merges tombstones and fetches the clients`() {
        pairLocally()
        server.on("GET", "/api/library") { request ->
            if (request.url.queryParameter("cursor") == null) {
                FakeServer.json(
                    200,
                    ServerFixtures.libraryPage(
                        listOf(ServerFixtures.libraryItem("01JA"), ServerFixtures.libraryItem("01JB")),
                        "next",
                        "2026-10-24T09:05:00+00:00"
                    )
                )
            } else {
                FakeServer.json(
                    200,
                    ServerFixtures.libraryPage(
                        listOf(ServerFixtures.libraryItem("01JC"), ServerFixtures.tombstone("01JA")),
                        null,
                        "2026-10-24T09:06:00+00:00"
                    )
                )
            }
        }
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))

        val job = vm.refresh()
        pumpUntil("refresh to finish") { job.isCompleted }

        assertEquals(setOf("01JB", "01JC"), vm.library.value.map { it.id }.toSet())
        assertEquals(listOf("Acme Holdings", "Zed Logistics"), vm.clients.value.map { it.name })
        assertNull(vm.message.value)
    }

    @Test
    fun `going offline keeps the last synced library and says so`() {
        pairLocally()
        serveLibrary(ServerFixtures.libraryItem("01JA"))
        syncNow()
        server.on("GET", "/api/library", MockResponse.Builder().onRequestStart(SocketEffect.CloseSocket()).build())

        val job = vm.refresh()
        pumpUntil("refresh to finish") { job.isCompleted }

        assertEquals("Offline — showing the last synced library", vm.message.value)
        assertEquals(listOf("01JA"), vm.library.value.map { it.id })
    }

    @Test
    fun `a revoked device is told to pair again`() {
        pairLocally()
        server.on("GET", "/api/library", FakeServer.json(401, """{"message":"Unauthenticated."}"""))

        val job = vm.refresh()
        pumpUntil("refresh to finish") { job.isCompleted }

        assertEquals("This phone was unpaired on the server. Pair it again.", vm.message.value)
    }

    @Test
    fun `refresh on an unpaired phone does nothing and sends no request`() {
        val job = vm.refresh()
        pumpUntil("refresh to finish") { job.isCompleted }

        assertTrue(server.requests.isEmpty())
        assertNull(vm.message.value)
        assertTrue(vm.clients.value.isEmpty())
    }

    @Test
    fun `refresh updates the home screen widget state`() {
        pairLocally()
        serveLibrary(ServerFixtures.libraryItem("01JA", title = "Weekly sync", preview = "We agreed on Friday"))

        val job = vm.refresh()
        pumpUntil("refresh to finish") { job.isCompleted }

        val widget = WidgetStateStore.load(app)
        assertTrue(widget.paired)
        assertEquals("Weekly sync", widget.lastTitle)
        assertEquals("We agreed on Friday", widget.lastPreview)
    }

    // ---- local recordings --------------------------------------------------------------------------------------------------

    @Test
    fun `reloadLocal lists unfinished recordings and hides uploaded ones`() {
        app.storeRecording(id = "q", state = "queued")
        app.storeRecording(id = "f", state = "failed")
        app.storeRecording(id = "p", state = "pending_decision")
        app.storeRecording(id = "u", state = "uploaded")

        vm.reloadLocal()
        pumpUntil("recordings to load") { vm.pending.value.isNotEmpty() }

        assertEquals(setOf("q", "f", "p"), vm.pending.value.map { it.id }.toSet())
    }

    @Test
    fun `retryUpload requeues a refused recording clears its error and schedules the upload`() {
        app.storeRecording(id = "r1", state = "failed", lastError = "The artifacts.0.sha256 field format is invalid.")

        val job = vm.retryUpload("r1")
        pumpUntil("retry") { job.isCompleted }

        val rec = app.graph.recordings.get("r1")!!
        assertEquals("queued", rec.state)
        assertNull(rec.lastError)
        assertEquals(listOf(WorkInfo.State.ENQUEUED), workStates("r1"))
        assertEquals("r1", vm.pending.value.single().id)
        assertEquals("queued", vm.pending.value.single().state)
    }

    @Test
    fun `retrying twice replaces the waiting upload instead of stacking another`() {
        app.storeRecording(id = "r1", state = "failed")

        repeat(2) {
            val job = vm.retryUpload("r1")
            pumpUntil("retry") { job.isCompleted }
        }

        val states = workStates("r1")
        assertEquals(1, states.count { it == WorkInfo.State.ENQUEUED })
        assertTrue(states.all { it == WorkInfo.State.ENQUEUED || it == WorkInfo.State.CANCELLED })
    }

    @Test
    fun `retryPending restarts only recordings that are queued`() {
        app.storeRecording(id = "queued", state = "queued", lastError = "timeout")
        app.storeRecording(id = "failed", state = "failed")
        app.storeRecording(id = "decision", state = "pending_decision")
        app.storeRecording(id = "uploaded", state = "uploaded")

        val job = vm.retryPending()
        pumpUntil("retryPending") { job.isCompleted }

        assertEquals(listOf(WorkInfo.State.ENQUEUED), workStates("queued"))
        assertTrue(workStates("failed").isEmpty())
        assertTrue(workStates("decision").isEmpty())
        assertTrue(workStates("uploaded").isEmpty())
        assertEquals(
            "a retry keeps the recording's error text until the next attempt",
            "timeout",
            app.graph.recordings.get("queued")!!.lastError
        )
    }

    @Test
    fun `keeping a recording queues it for upload and discarding deletes it`() {
        app.storeRecording(id = "keep", state = "pending_decision")
        app.storeRecording(id = "drop", state = "pending_decision")

        val keepJob = vm.decide("keep", keep = true)
        pumpUntil("keep") { keepJob.isCompleted }
        val dropJob = vm.decide("drop", keep = false)
        pumpUntil("discard") { dropJob.isCompleted }

        assertEquals("queued", app.graph.recordings.get("keep")!!.state)
        assertEquals(listOf(WorkInfo.State.ENQUEUED), workStates("keep"))
        assertNull(app.graph.recordings.get("drop"))
        assertFalse(app.graph.recordings.encryptedFile("drop").exists())
        assertTrue(workStates("drop").isEmpty())
        assertEquals(listOf("keep"), vm.pending.value.map { it.id })
    }

    // ---- reading a session ----------------------------------------------------------------------------------------------------

    @Test
    fun `playbackUrl returns the signed url from the session detail`() {
        pairLocally()
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(
                200,
                ServerFixtures.sessionDetail(playbackUrl = "https://ledger.example.com/api/recordings/r/audio?signature=abc")
            )
        )

        assertEquals(
            "https://ledger.example.com/api/recordings/r/audio?signature=abc",
            runBlocking {
                vm.playbackUrl(ServerFixtures.SESSION_ID)
            }
        )
    }

    @Test
    fun `playbackUrl is null when the recording is not ready or the request fails`() {
        pairLocally()
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        assertNull(runBlocking { vm.playbackUrl(ServerFixtures.SESSION_ID) })

        server.on("GET", "/api/sessions/${ServerFixtures.SESSION_ID}", FakeServer.json(404, """{"message":"No query results."}"""))
        assertNull(runBlocking { vm.playbackUrl(ServerFixtures.SESSION_ID) })

        server.on("GET", "/api/sessions/${ServerFixtures.SESSION_ID}", FakeServer.json(500, "{}"))
        assertNull(runBlocking { vm.playbackUrl(ServerFixtures.SESSION_ID) })

        server.on("GET", "/api/sessions/${ServerFixtures.SESSION_ID}", MockResponse.Builder().code(200).body("<html>portal</html>").build())
        assertNull(runBlocking { vm.playbackUrl(ServerFixtures.SESSION_ID) })
    }

    @Test
    fun `playbackUrl and transcript are null on an unpaired phone`() {
        assertNull(runBlocking { vm.playbackUrl("any") })
        assertNull(runBlocking { vm.transcript("any") })
        assertTrue(server.requests.isEmpty())
    }

    @Test
    fun `transcript returns the segments or null on failure`() {
        pairLocally()
        server.on("GET", "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}", FakeServer.json(200, ServerFixtures.transcript()))
        val transcript = runBlocking { vm.transcript(ServerFixtures.TRANSCRIPT_ID) }
        assertEquals(2, transcript!!.segments.size)

        server.on("GET", "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}", FakeServer.json(404, "{}"))
        assertNull(runBlocking { vm.transcript(ServerFixtures.TRANSCRIPT_ID) })
    }

    // ---- delete, unpair, settings -----------------------------------------------------------------------------------------------

    @Test
    fun `deleting a session asks the server and then refreshes the library`() {
        pairLocally()
        serveLibrary(ServerFixtures.libraryItem("01JA"), ServerFixtures.libraryItem("01JB"))
        syncNow()
        server.on("DELETE", "/api/sessions/01JA", FakeServer.empty(204))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(200, ServerFixtures.libraryPage(listOf(ServerFixtures.tombstone("01JA")), null, "2026-10-24T09:30:00+00:00"))
        )

        val job = vm.delete(libraryItem("01JA"))
        pumpUntil("delete") { job.isCompleted && vm.library.value.size == 1 }

        assertEquals(1, server.requestsTo("DELETE", "/api/sessions/01JA").size)
        assertEquals(listOf("01JB"), vm.library.value.map { it.id })
    }

    @Test
    fun `a failed delete reports the server reason and keeps the item`() {
        pairLocally()
        serveLibrary(ServerFixtures.libraryItem("01JA"))
        syncNow()
        server.on("DELETE", "/api/sessions/01JA", FakeServer.json(403, """{"message":"Invalid ability provided."}"""))

        val job = vm.delete(libraryItem("01JA"))
        pumpUntil("delete") { job.isCompleted }

        assertEquals("Invalid ability provided.", vm.message.value)
        assertEquals(listOf("01JA"), vm.library.value.map { it.id })
    }

    @Test
    fun `unpairing forgets the token the device the cached library and updates the widget`() {
        serveRegistration()
        serveLibrary(ServerFixtures.libraryItem("01JA"))
        vm.pair(server.baseUrl, "ABCD-1234", "")
        pumpUntil("paired") { vm.library.value.isNotEmpty() }
        runBlocking { app.graph.settings.update { it.copy(captionsEnabled = false, appLockEnabled = true) } }

        val job = vm.unpair()
        pumpUntil("unpair") { job.isCompleted }

        val saved = settings()
        assertFalse(saved.paired)
        assertNull(saved.deviceId)
        assertNull(storedToken())
        assertTrue(vm.library.value.isEmpty())
        assertFalse(java.io.File(app.noBackupFilesDir, "library.bin").exists())
        assertFalse("preferences survive unpairing", saved.captionsEnabled)
        assertTrue(saved.appLockEnabled)
        assertFalse(WidgetStateStore.load(app).paired)
        assertNull("no client may be built for an unpaired phone", runBlocking { app.graph.api() })
    }

    @Test
    fun `unpairing forces the next pairing to use a fresh API client`() {
        pairLocally()
        val before = runBlocking { app.graph.api() }
        assertNotNull(before)

        val job = vm.unpair()
        pumpUntil("unpair") { job.isCompleted }
        runBlocking { app.pairLocally(server, token = "7|eld_second") }

        val after = runBlocking { app.graph.api() }
        assertNotNull(after)
        assertTrue(before !== after)
    }

    @Test
    fun `messages are shown once and consumed`() {
        assertNull(vm.message.value)
        vm.showMessage("Copied")
        assertEquals("Copied", vm.message.value)
        vm.showMessage("Copied with timestamps")
        assertEquals("Copied with timestamps", vm.message.value)
        vm.consumeMessage()
        assertNull(vm.message.value)
    }

    @Test
    fun `updateSettings persists the change and the settings flow follows`() {
        val job = vm.updateSettings { it.copy(appLockEnabled = true, micSource = "mic") }
        pumpUntil("settings flow") { job.isCompleted && vm.settings.value.appLockEnabled }

        assertEquals("mic", vm.settings.value.micSource)
        assertTrue(settings().appLockEnabled)
    }

    @Test
    fun `connecting Shizuku while it is not running explains what to do`() {
        vm.connectShizuku()

        assertEquals("Shizuku is not running. Start it (Wireless debugging) and try again.", vm.message.value)
    }

    @Test
    fun `the ADB command targets this package and the handoff authority`() {
        val command = vm.adbCommand()

        assertTrue(command, command.startsWith("adb shell"))
        assertTrue(command, command.contains("com.echoledger.mobile.capture.HelperMain"))
        assertTrue(command, command.contains("${app.packageName}.handoff"))
        assertTrue(command, command.contains("pm path ${app.packageName}"))
    }
}
