package com.echoledger.mobile.net

import com.echoledger.mobile.core.CheckRow
import com.echoledger.mobile.core.CheckState
import com.echoledger.mobile.core.PhoneFacts
import com.echoledger.mobile.data.ArtifactDto
import com.echoledger.mobile.data.BriefSections
import com.echoledger.mobile.data.CreateSessionRequest
import com.echoledger.mobile.data.DeviceTranscript
import com.echoledger.mobile.data.FinishSessionRequest
import com.echoledger.mobile.data.OpenSessionRequest
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.UsageReport
import com.echoledger.mobile.privilege.Capabilities
import java.io.File
import java.security.MessageDigest
import java.util.UUID
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assume.assumeTrue
import org.junit.Test

/**
 * The phone's real client against a real server, both ways: what it sends must pass the server's validation and what comes
 * back must read. Skipped unless a disposable server is named:
 *   ECHOLEDGER_TEST_SERVER=http://127.0.0.1:8791 ECHOLEDGER_TEST_EMAIL=… ECHOLEDGER_TEST_PASSWORD=… gradlew testFullDebugUnitTest
 * It changes data, so never point it at anything but a throwaway database. Answers that depend on the server's setup (no AI
 * key, no speech provider) are fine; a refused body (422), a missing route (404 or 405) or an unreadable answer is not.
 */
class LiveServerContractTest {
    private val base: String? = System.getenv("ECHOLEDGER_TEST_SERVER")
    private val out = mutableListOf<String>()
    private val problems = mutableListOf<String>()

    private suspend fun <T> step(name: String, allowed: Set<String> = emptySet(), block: suspend () -> T): T? = try {
        block().also { out += "ok    $name" }
    } catch (e: ApiException) {
        val line = "${e.status} ${e.code ?: "-"} $name: ${e.message?.take(160)}"
        val fine = e.code in allowed || e.status in setOf(401, 403, 409, 429) || (e.status == 503)
        if (!fine) problems += line
        out += (if (fine) "soft  " else "BAD   ") + line
        null
    } catch (e: Exception) {
        val line = "${e.javaClass.simpleName} $name: ${e.message?.take(220)}"
        problems += line
        out += "BAD   $line"
        null
    }

    private fun sha(bytes: ByteArray) = MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }

    @Test
    fun `every call the phone makes passes the server and reads back`() = runBlocking {
        assumeTrue("no test server named", base != null)
        val signedOut = ApiClient(base!!, emptyList(), null)
        val signedIn = signedOut.signInWithPassword(
            System.getenv("ECHOLEDGER_TEST_EMAIL"),
            System.getenv("ECHOLEDGER_TEST_PASSWORD"),
            null,
            "Live test",
            "1.5.0",
            "14"
        )
        val api = ApiClient(base, emptyList(), signedIn.token)
        val suffix = UUID.randomUUID().toString().take(8)

        step("config") { api.config() }
        step("account") { api.account() }
        step("clients") { api.clients() }
        step("storage") { api.storage() }
        step("security") { api.security() }
        step("activity") { api.activity() }
        step("activity read") { api.markActivityRead() }
        step("bin") { api.bin() }
        step("android release") { api.androidRelease() }
        val library = step("library") { api.library(null, null) }
        step("folder") { api.libraryFolder("whatsapp") }
        step("shared folder") { api.libraryFolder("shared") }
        step("folder counts") { api.libraryFolderCounts() }
        step("search") { api.search("retainer") }
        step("search bookmarked") { api.search("retainer", tag = "Decision", bookmarked = true) }
        step("report device") {
            api.reportDevice(
                PhoneFacts(
                    "Xiaomi",
                    "Redmi",
                    "23078PND5G",
                    "14",
                    "1.5.0",
                    Capabilities(
                        sdkInt = 34,
                        helperConnected = false,
                        micPermission = true,
                        notificationPermission = true,
                        overlayPermission = false
                    ),
                    batteryExempt = false,
                    hasPhoneLine = true,
                    phoneCallsInBuild = true,
                    signedIn = true,
                    liveTranslateInPlan = true,
                    memoryGb = 3
                ),
                listOf(CheckRow("voice_notes", "Voice notes", CheckState.OK, "Works."))
            )
        }

        // One recording from start to finish, as the recorder does it.
        val bytes = ByteArray(4096) { (it % 251).toByte() }
        val artifact =
            ArtifactDto(
                role = "primary",
                mime = "audio/ogg",
                codec = "opus",
                channels = 2,
                sampleRate = 48000,
                sizeBytes = bytes.size.toLong(),
                sha256 = sha(bytes),
                durationMs = 5000
            )
        val open = step("open session") {
            api.openSession(OpenSessionRequest(UUID.randomUUID().toString(), "whatsapp", null, "2026-11-01T10:00:00Z"))
        }
        val finished = open?.let {
            step("finish session") {
                api.finishSession(
                    it.session.id,
                    FinishSessionRequest("2026-11-01T10:05:00Z", 300000, null, listOf(artifact), "Live test call")
                )
            }
        }
        step("stt token", setOf("provider_unavailable", "off")) {
            api.sttToken("live_captions", listOf("me", "client"), null, open?.session?.id)
        }
        step("dictation token", setOf("provider_unavailable")) { api.sttToken("dictation", listOf("me"), null) }
        step("live translate token") { api.liveTranslateToken("game", "en", listOf("ar")) }
        val recording = finished?.recordings?.firstOrNull()
        if (recording != null) {
            step("recording status") { api.recordingStatus(recording.id) }
            step("chunk") { api.uploadChunk(recording.id, 0, bytes, sha(bytes)) }
            step("complete") { api.complete(recording.id) }
        }
        val full = step("create session") {
            api.createSession(
                CreateSessionRequest(
                    UUID.randomUUID().toString(), "voice_note", null, "A note", "2026-11-01T11:00:00Z", "2026-11-01T11:01:00Z", 60000, null,
                    listOf(
                        artifact
                    )
                )
            )
        }
        step("usage") {
            api.reportUsage(UsageReport(UUID.randomUUID().toString(), "live_captions", "assemblyai", open?.session?.id, 60000, 2))
        }
        full?.session?.id?.let { id ->
            step("post transcript") {
                api.postTranscript(
                    id,
                    DeviceTranscript("live", "assemblyai", null, listOf(SegmentDto(1, "Me", 0, 900, "Hello there.")))
                )
            }
        }

        // A recording that has a transcript.
        val item = library?.data?.firstOrNull { it.transcript?.finalTranscriptId != null } ?: library?.data?.firstOrNull()
        val id = item?.id
        if (id != null) {
            val detail = step("session detail") { api.sessionDetail(id) }
            val transcriptId = item.transcript?.finalTranscriptId
            step("opened") { api.markOpened(id) }
            step("rename") { api.updateSession(id, title = "Renamed $suffix") }
            step("pin") { api.updateSession(id, pinned = true) }
            step("labels") { api.updateSession(id, labels = listOf("Sample")) }
            step("keep") { api.updateSession(id, keep = true) }
            step("screens") { api.screens(id) }
            step("meeting pack") { api.meetingPack(id, "zip", null) }
            step("notes") { api.sessionNotes(id) }
            step("make notes", setOf("ai_unavailable", "no_client")) { api.makeNotes(id, "en") }
            step("retranscribe") { api.retranscribe(id, "ur") }
            if (transcriptId != null) {
                step("transcript") { api.transcript(transcriptId) }
                step("views") { api.transcriptViews(transcriptId) }
                step("make views", setOf("views_unavailable")) { api.makeTranscriptViews(transcriptId) }
                step("export") { api.export(transcriptId, "txt") }
                step("suggestion") { runCatching { api.decideSuggestion(transcriptId, 0, true) } }
            }
            step("export sessions") { api.exportSessions(listOf(id), "txt") }
            val old = step("old share") { api.createShare(id, 86400, "secret-1234", false) }
            step("public link") { api.createShareLink(id, null, "public", null, emptyList(), false, null) }
            step("password link") { api.createShareLink(id, null, "password", "secret-1234", emptyList(), true, "2030-01-01T00:00:00Z") }
            step("people link") { api.createShareLink(id, null, "people", null, listOf("someone@example.test"), false, null) }
            val links = step("shares") { api.shares(id) }
            links?.data?.firstOrNull()?.let { step("revoke share") { api.revokeShare(it.id) } }
            old?.let { step("revoke old") { api.revokeShare(it.id) } }
            step("resolve link") { api.resolveLink("$base/admin/sessions/$id") }
            step("bulk label") { api.bulk(listOf(id), "label", label = "Bulk") }
            detail?.let { step("bulk pin") { api.bulk(listOf(id), "pin") } }
        }

        // A project, through everything its pages do.
        val project = step("create client") { api.createClient("Live client $suffix") }
        val folder = step("create project") { api.createProject("Live project $suffix", null) }
        step("subfolder") { api.createProject("Sub $suffix", folder?.id) }
        if (folder != null) {
            val p = folder.id
            step("rename project") { api.updateProject(p, name = "Live project renamed $suffix") }
            step("language") { api.setClientLanguage(p, "ur") }
            step("ai notes") { api.setClientAiNotes(p, true) }
            step("workspace") { api.workspace(p) }
            step("brief") { api.updateBrief(p, BriefSections(goal = "A goal", scope = listOf("Login"), people = listOf("Omar"))) }
            step("refresh brief", setOf("ai_unavailable")) { api.refreshBrief(p) }
            val memory = step("memory", setOf("ai_unavailable")) { api.addMemory(p, "decision", "They chose the second plan.") }
            memory?.let {
                step("memory edit") { api.updateMemory(p, it.id, text = "Changed.", pinned = true) }
                step("memory delete") { api.deleteMemory(p, it.id) }
            }
            val file = step("file") { api.uploadClientFile(p, "notes.txt", "text/plain", "Some notes.".toByteArray()) }
            file?.let { step("delete file") { api.deleteClientFile(p, it.id) } }
            step("instructions") { api.addInstructions(p, "Rules", "Keep answers short.") }
            step("ask", setOf("ai_unavailable")) { api.ask(p, "What did they choose?", "en") }
            step("bundle") { api.bundle(p) }
            step("project screens") { api.projectScreens(p, true, null) }
            val rule = step("rule") { api.addFilingRule(p, "contact", "+9230012345$suffix".take(14).filter { it.isDigit() || it == '+' }) }
            step("rules") { api.filingRules(p) }
            step("delete rule") { rule?.let { api.deleteFilingRule(p, it.id) } }
            step("project link") { api.createShareLink(null, p, "public", null, emptyList(), true, null) }
            step("project shares") { api.projectShares(p) }
            step("send project") { api.sendToTeammates(null, p, listOf("teammate@example.test"), "Please look.") }
            step("keyterm") { api.addKeyterm("EchoLedger", p) }
            if (id != null) {
                step("file a recording") { api.updateSession(id, clientId = p) }
                step("bulk move") { api.bulk(listOf(id), "move", clientId = p) }
                step("send recording") { api.sendToTeammates(id, null, listOf("teammate@example.test"), null) }
                val sent = step("sent list") { api.sentOf(id, null) }
                sent?.firstOrNull()?.let { step("stop sharing") { api.stopSharing(it.id) } }
                step("sent list of a project") { api.sentOf(null, p) }
                step("unfile") { api.updateSession(id, clearClient = true) }
            }
        }
        step("request links") { api.requestLinks() }
        val request = step("request link") { api.createRequestLink("Send me the file", folder?.id, "2030-01-01T00:00:00Z") }
        request?.let { step("revoke request link") { api.revokeRequestLink(it.id) } }
        step("shared with me") { api.sharedWithMe() }
        project?.let { step("delete client") { api.deleteProject(it.id) } }
        folder?.let { step("delete project") { api.deleteProject(it.id) } }

        // Housekeeping last.
        // An upload that never finished goes at once; a finished recording waits in the bin.
        full?.session?.id?.let { rid -> step("delete unfinished") { api.deleteSession(rid) } }
        if (id != null) {
            step("delete session") { api.deleteSession(id) }
            step("bin again") { api.bin() }
            step("restore") { api.restoreSession(id) }
            step("delete again") { api.deleteSession(id) }
            step("erase") { api.eraseFromBin(id) }
        }
        val export = step("start export") { api.startExport() }
        export?.let { step("export status") { api.exportStatus(it.id) } }

        File("build/live-contract.txt").writeText(out.joinToString("\n"))
        assertEquals(problems.joinToString("\n"), 0, problems.size)
    }
}
