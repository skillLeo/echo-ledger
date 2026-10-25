package com.echoledger.mobile.net

import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.ArtifactDto
import com.echoledger.mobile.data.ClientsResponse
import com.echoledger.mobile.data.CreateSessionRequest
import com.echoledger.mobile.data.CreateSessionResponse
import com.echoledger.mobile.data.DeviceTranscript
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.LibraryRepository
import com.echoledger.mobile.data.LibraryResponse
import com.echoledger.mobile.data.RecordingUpload
import com.echoledger.mobile.data.RegisterResponse
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.ServerConfig
import com.echoledger.mobile.data.SessionDetailDto
import com.echoledger.mobile.data.SttTokenResponse
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.data.UploadState
import com.echoledger.mobile.ui.formatDuration
import com.echoledger.mobile.ui.formatWhen
import java.time.ZoneOffset
import java.util.Locale
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.Dispatcher
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import mockwebserver3.RecordedRequest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test

/**
 * Contract between the Laravel server and the Android client: responses captured from a running server (tokens and
 * signatures replaced by placeholders, see src/test/resources/fixtures/server/index.json) must decode with the app's
 * own DTOs and Json configuration, and [ApiClient] must turn them into the values the screens read.
 */
class ServerContractTest {
    private var server: ContractServer? = null

    @After
    fun tearDown() {
        server?.close()
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------

    private fun raw(name: String): String {
        val loader = checkNotNull(javaClass.classLoader) { "no class loader" }
        val stream = checkNotNull(loader.getResourceAsStream("fixtures/server/$name.json")) { "fixture $name is missing" }
        return stream.use { String(it.readBytes(), Charsets.UTF_8) }
    }

    private fun json(name: String): JsonElement = ApiJson.parseToJsonElement(raw(name))

    private fun obj(name: String): JsonObject = json(name).jsonObject

    private fun String.field(key: String): String = ApiJson.parseToJsonElement(this).jsonObject.getValue(key).jsonPrimitive.content

    private fun fixtureNames(): List<String> {
        val index = obj("index")
        return index.keys.sorted()
    }

    private fun isId(value: String) = value.length == 26 && value.all { it.isDigit() || it in 'a'..'z' }

    private fun isIsoWithOffset(value: String) = Regex("""^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$""").matches(value)

    private fun contractServer(vararg routes: Route): ContractServer = ContractServer(routes.toList()).also { server = it }

    private fun client(withToken: Boolean = true) = ApiClient(server!!.baseUrl, pins = emptyList(), token = if (withToken) TOKEN else null)

    private class Route(val method: String, val path: Regex, val status: Int, val body: String)

    private fun route(method: String, path: String, status: Int, fixture: String) =
        Route(method, Regex(path), status, if (fixture.isEmpty()) "" else raw(fixture))

    // ---- the fixture set ----------------------------------------------------------------------------------------------

    @Test
    fun `every indexed fixture exists and is valid JSON`() {
        val names = fixtureNames()
        assertTrue("only ${names.size} fixtures", names.size >= 80)
        for (name in names) {
            val body = raw(name)
            assertTrue("$name is empty", body.isNotBlank())
            ApiJson.parseToJsonElement(body)
        }
    }

    @Test
    fun `fixtures hold placeholders instead of secrets`() {
        for (name in fixtureNames()) {
            val text = raw(name)
            for ((marker, allowed) in listOf(
                "eld_" to "TOKEN_PLACEHOLDER",
                "signature=" to "SIGNATURE_PLACEHOLDER",
                "token=" to "STREAMING_TOKEN_PLACEHOLDER"
            )) {
                var from = text.indexOf(marker)
                while (from >= 0) {
                    assertTrue("$name: $marker is not followed by $allowed", text.startsWith(allowed, from + marker.length))
                    from = text.indexOf(marker, from + 1)
                }
            }
            for (forbidden in listOf("127.0.0.1", "xampp", "\"trace\"", "\"exception\"")) {
                assertFalse("$name contains $forbidden", text.contains(forbidden))
            }
        }
    }

    @Test
    fun `error fixtures use the documented error shape`() {
        val index = obj("index")
        val statuses = mutableSetOf<Int>()
        for ((name, entry) in index) {
            val status = entry.jsonObject.getValue("status").jsonPrimitive.int
            if (status < 400) continue
            statuses += status
            val body = obj(name)
            assertTrue("$name ($status) has no message", !body["message"]?.jsonPrimitive?.contentOrNull.isNullOrEmpty())
            body["errors"]?.takeIf { it !is JsonNull }?.let { errors ->
                errors.jsonObject.forEach { (field, messages) ->
                    val allStrings = messages.jsonArray.all { it is JsonPrimitive && it.isString }
                    assertTrue("$name: errors.$field is not a list of strings", allStrings)
                }
            }
        }
        for (status in listOf(401, 403, 404, 409, 413, 422, 429)) assertTrue("no fixture for HTTP $status", status in statuses)
    }

    // ---- pairing and configuration -------------------------------------------------------------------------------------

    @Test
    fun `register response for an Android device is a phone with its first config`() {
        val r = ApiJson.decodeFromString(RegisterResponse.serializer(), raw("register_201_android"))
        assertTrue(r.token, r.token.startsWith("2|eld_"))
        assertTrue(isIsoWithOffset(r.tokenExpiresAt!!))
        assertTrue(isId(r.device.id))
        assertEquals("S25 Ultra", r.device.name)
        assertEquals("android", r.device.platform)
        assertEquals("phone", r.device.sourceDevice)
        assertEquals(r.device.id, r.config.device.id)
        assertEquals(5_242_880L, r.config.upload.chunkSizeBytes)
        assertEquals(2_147_483_648L, r.config.upload.maxRecordingBytes)
        assertTrue(r.config.pins.isEmpty())
    }

    @Test
    fun `register response for a desktop device is accepted by the phone's model too`() {
        val r = ApiJson.decodeFromString(RegisterResponse.serializer(), raw("register_201_windows"))
        assertEquals("desktop", r.device.sourceDevice)
        assertEquals("windows", r.device.platform)
    }

    @Test
    fun `config carries every section the app reads`() {
        val config = ApiJson.decodeFromString(ServerConfig.serializer(), raw("config_200_android"))
        assertTrue(isIsoWithOffset(config.serverTime))
        assertEquals(30, config.retentionDays)
        assertEquals(5_242_880L, config.upload.chunkSizeBytes)
        for (mime in listOf("audio/ogg", "audio/opus", "audio/flac", "audio/wav")) {
            assertTrue("$mime not accepted", mime in config.upload.acceptedMimes)
        }
        assertEquals("both", config.captions.channels)
        assertEquals(setOf("live_captions", "dictation", "final_transcript", "voice_note"), config.providers.keys)
        assertEquals("cloud", config.providers.getValue("live_captions").mode)
        assertNull(config.providers.getValue("live_captions").requiredArtifacts)
        val final = config.providers.getValue("final_transcript")
        assertEquals("assemblyai", final.provider)
        assertEquals("async_webhook", final.mode)
        assertEquals(listOf("primary"), final.requiredArtifacts)
        assertEquals("ogg_opus", final.preferredPrimary)
        assertEquals("ogg_opus", config.providers.getValue("voice_note").preferredPrimary)
    }

    @Test
    fun `the config inside register matches get config`() {
        val registered = ApiJson.decodeFromString(RegisterResponse.serializer(), raw("register_201_android")).config
        val fetched = ApiJson.decodeFromString(ServerConfig.serializer(), raw("config_200_android"))
        assertEquals(registered.copy(serverTime = ""), fetched.copy(serverTime = ""))
    }

    @Test
    fun `clients list has what the record screen picks from`() {
        val clients = ApiJson.decodeFromString(ClientsResponse.serializer(), raw("clients_200"))
        assertEquals(listOf("Acme Holdings", "Birch & Co"), clients.data.map { it.name })
        assertEquals(listOf(3, 0), clients.data.map { it.keytermCount })
        assertTrue(clients.data.all { isId(it.id) })
    }

    // ---- live captions ---------------------------------------------------------------------------------------------------

    @Test
    fun `live caption grants carry the url the streamer connects to`() {
        val token = ApiJson.decodeFromString(SttTokenResponse.serializer(), raw("stt_token_200_live_captions"))
        assertEquals("assemblyai", token.provider)
        assertEquals("cloud", token.mode)
        assertTrue(isIsoWithOffset(token.expiresAt!!))
        assertEquals(listOf("Acme Holdings", "Farah Khan", "Q3 forecast"), token.keyterms)
        assertEquals(listOf("me", "client"), token.grants.map { it.channel })
        for (grant in token.grants) {
            assertEquals("websocket", grant.transport)
            assertEquals("assemblyai_v3", grant.protocol)
            assertTrue(grant.wsUrl!!.startsWith("wss://streaming.assemblyai.com/v3/ws?"))
            assertEquals(10_800L, grant.maxSessionSeconds)
            val audio = grant.audio!!
            assertEquals(Triple("pcm_s16le", 16_000, 1), Triple(audio.encoding, audio.sampleRate, audio.channels))
            assertEquals(50, audio.frameMs)
            assertEquals(1, grant.initMessages.size)
            val init = grant.initMessages.single().jsonObject
            assertEquals("UpdateConfiguration", init.getValue("type").jsonPrimitive.content)
            assertEquals(token.keyterms, init.getValue("keyterms_prompt").jsonArray.map { it.jsonPrimitive.content })
        }
    }

    @Test
    fun `dictation grants cover only the microphone and need no configuration`() {
        val token = ApiJson.decodeFromString(SttTokenResponse.serializer(), raw("stt_token_200_dictation"))
        assertTrue(token.keyterms.isEmpty())
        assertEquals(listOf("me"), token.grants.map { it.channel })
        assertTrue(token.grants.single().initMessages.isEmpty())
    }

    // ---- sessions and uploads --------------------------------------------------------------------------------------------

    @Test
    fun `a new session comes back with its upload slot`() {
        val created = ApiJson.decodeFromString(CreateSessionResponse.serializer(), raw("sessions_store_201"))
        val session = created.session
        assertTrue(isId(session.id))
        assertFalse(session.deleted)
        assertEquals("desktop", session.sourceDevice)
        assertEquals("Desktop", session.sourceDeviceLabel)
        assertEquals("zoom", session.sourceApp)
        assertEquals("Zoom", session.sourceAppLabel)
        assertEquals("Quarterly review", session.title)
        assertEquals("uploading", session.status)
        assertEquals("Acme Holdings", session.client?.name)
        assertEquals("Work laptop", session.device?.name)
        assertEquals(8_000L, session.durationMs)
        assertNull(session.transcript?.finalStatus)
        assertFalse(session.transcript!!.liveAvailable)
        assertEquals(listOf("primary"), created.requiredArtifacts)
        val slot = created.recordings.single()
        assertTrue(isId(slot.id))
        assertEquals("primary", slot.role)
        assertEquals(UploadState(5_242_880L, 1, emptyList(), "pending"), slot.upload)
    }

    @Test
    fun `repeating a session returns the same session`() {
        val first = ApiJson.decodeFromString(CreateSessionResponse.serializer(), raw("sessions_store_201"))
        val again = ApiJson.decodeFromString(CreateSessionResponse.serializer(), raw("sessions_store_200_existing"))
        assertEquals(first, again)
    }

    @Test
    fun `a session posted without title or client is given a display title`() {
        val created = ApiJson.decodeFromString(CreateSessionResponse.serializer(), raw("sessions_store_201_minimal"))
        assertEquals("WhatsApp call", created.session.title)
        assertNull(created.session.client)
        assertEquals("phone", created.session.sourceDevice)
        val note = ApiJson.decodeFromString(CreateSessionResponse.serializer(), raw("sessions_store_201_voice_note"))
        assertEquals("Voice note", note.session.sourceAppLabel)
    }

    @Test
    fun `the server's chunk count matches the app's chunk plan`() {
        val created = ApiJson.decodeFromString(CreateSessionResponse.serializer(), raw("sessions_store_201_two_chunks"))
        val upload = created.recordings.single().upload
        val plan = ChunkPlanner.plan(5_767_168L, upload.chunkSizeBytes)
        assertEquals(upload.totalChunks, plan.size)
        assertEquals(listOf(5_242_880, 524_288), plan.map { it.length })
        val single = ApiJson.decodeFromString(CreateSessionResponse.serializer(), raw("sessions_store_201"))
        assertEquals(1, ChunkPlanner.plan(512_044L, single.recordings.single().upload.chunkSizeBytes).size)
    }

    @Test
    fun `upload state tracks the received chunks`() {
        val pending = ApiJson.decodeFromString(RecordingUpload.serializer(), raw("recordings_show_200_pending"))
        assertTrue(pending.upload.receivedChunks.isEmpty())
        val uploading = ApiJson.decodeFromString(RecordingUpload.serializer(), raw("recordings_show_200_uploading"))
        assertEquals(listOf(0), uploading.upload.receivedChunks)
        assertEquals(listOf(1), ChunkPlanner.missing(uploading.upload.totalChunks, uploading.upload.receivedChunks))
        val ready = ApiJson.decodeFromString(RecordingUpload.serializer(), raw("recordings_show_200_ready"))
        assertEquals("ready", ready.upload.status)
        assertEquals("primary", ready.role)
    }

    @Test
    fun `chunk acknowledgements decode as upload state with no chunk size`() {
        for (name in listOf("chunks_200_partial", "chunks_200_resend", "chunks_200_single", "chunks_200_last")) {
            assertNull("$name must not repeat the chunk size", obj(name)["chunk_size_bytes"])
            val state = ApiJson.decodeFromString(UploadState.serializer(), raw(name))
            assertEquals(name, 0L, state.chunkSizeBytes)
            assertEquals("uploading", state.status)
        }
        val last = ApiJson.decodeFromString(UploadState.serializer(), raw("chunks_200_last"))
        assertEquals(listOf(0, 1), last.receivedChunks)
        assertEquals(2, last.totalChunks)
    }

    // ---- session detail -----------------------------------------------------------------------------------------------------

    @Test
    fun `session detail decodes as a library item and offers playback once ready`() {
        for (name in listOf(
            "sessions_show_200_uploading",
            "sessions_show_200_completed",
            "sessions_show_200_completed_android",
            "sessions_show_200_failed",
            "sessions_show_200_processing",
            "sessions_show_200_with_pending_artifact"
        )) {
            val item = ApiJson.decodeFromString(LibraryItem.serializer(), raw(name))
            assertTrue(name, isId(item.id) && !item.deleted)
            val detail = ApiJson.decodeFromString(SessionDetailDto.serializer(), raw(name))
            if (name == "sessions_show_200_uploading") {
                assertNull("no playback while the recording is still uploading", detail.playbackUrl)
            } else {
                val url = detail.playbackUrl!!
                assertTrue(url, url.startsWith("https://") && "/audio?expires=" in url && "&signature=" in url)
            }
        }
    }

    // ---- library ------------------------------------------------------------------------------------------------------------

    @Test
    fun `library page items carry what the list renders`() {
        val page = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_page"))
        assertTrue(isIsoWithOffset(page.serverTime))
        assertNull(page.nextCursor)
        assertEquals(5, page.data.size)
        for (item in page.data) {
            assertTrue(isId(item.id))
            assertFalse(item.deleted)
            assertTrue(isIsoWithOffset(item.updatedAt))
            assertTrue(isIsoWithOffset(item.startedAt!!))
            assertTrue(item.title!!.isNotEmpty())
            assertTrue(item.sourceDevice in setOf("phone", "desktop", "web"))
            assertTrue(item.sourceDeviceLabel!!.isNotEmpty() && item.sourceAppLabel!!.isNotEmpty())
            assertNotNull(item.durationMs)
            assertNotNull(item.transcript)
        }
        assertEquals(setOf("phone", "desktop"), page.data.map { it.sourceDevice }.toSet())
    }

    @Test
    fun `server timestamps and durations format for display`() {
        val page = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_page"))
        for (item in page.data) {
            assertTrue("${item.id}: started_at", formatWhen(item.startedAt, ZoneOffset.UTC, Locale.US).isNotEmpty())
            assertTrue("${item.id}: duration_ms", formatDuration(item.durationMs).isNotEmpty())
        }
        val shown = formatWhen("2026-10-24T15:56:41+00:00", ZoneOffset.UTC, Locale.US)
        assertTrue(shown, shown.startsWith("Oct 24 · 3:56"))
        assertEquals("1:30", formatDuration(page.data.single { it.title == "WhatsApp Business call" }.durationMs))
    }

    @Test
    fun `library item states cover completed processing and failed`() {
        val page = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_page"))
        fun byTitle(title: String) = page.data.single { it.title == title }
        val done = byTitle("Quarterly review")
        assertEquals("completed", done.status)
        assertEquals("completed", done.transcript?.finalStatus)
        assertTrue(isId(done.transcript!!.finalTranscriptId!!))
        assertTrue(isId(done.transcript.liveTranscriptId!!))
        assertTrue(done.transcript.liveAvailable)
        assertTrue(done.transcript.preview!!.startsWith("Me: "))

        val processing = byTitle("Vendor sync")
        assertEquals("processing", processing.status)
        assertEquals("submitted", processing.transcript?.finalStatus)
        assertNull(processing.transcript?.preview)
        assertEquals("Birch & Co", processing.client?.name)

        val failed = byTitle("WhatsApp call")
        assertEquals("failed", failed.status)
        assertEquals("failed", failed.transcript?.finalStatus)
        assertNull(failed.client)

        val note = byTitle("Shopping list")
        assertEquals("voice_note", note.sourceApp)
        assertEquals("S25 Ultra", note.device?.name)
    }

    @Test
    fun `cursor pages chain without gaps or repeats`() {
        val first = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_cursor_page1"))
        val second = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_cursor_page2"))
        assertEquals(2, first.data.size)
        assertEquals(2, second.data.size)
        assertTrue(!first.nextCursor.isNullOrEmpty())
        assertTrue(!second.nextCursor.isNullOrEmpty())
        assertEquals(4, (first.data + second.data).map { it.id }.toSet().size)
        assertTrue(first.data[0].updatedAt >= first.data[1].updatedAt)
        assertTrue(first.data[1].updatedAt >= second.data[0].updatedAt)
    }

    @Test
    fun `filters return subsets of the full library`() {
        val all = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_page")).data.map { it.id }.toSet()
        val phone = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_filter_phone")).data
        assertEquals(3, phone.size)
        assertTrue(phone.all { it.sourceDevice == "phone" && it.id in all })
        val zoom = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_filter_zoom")).data
        assertEquals(listOf("zoom"), zoom.map { it.sourceApp })
        val failed = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_filter_failed")).data
        assertEquals(listOf("failed"), failed.map { it.status })
        val acme = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_filter_client")).data
        assertEquals(listOf("Acme Holdings"), acme.map { it.client?.name })
    }

    @Test
    fun `a tombstone decodes and removes the cached item`() {
        val tombstones = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_since_tombstone"))
        val gone = tombstones.data.single()
        assertTrue(gone.deleted)
        assertTrue(isIsoWithOffset(gone.updatedAt))
        assertNull(gone.title)
        assertNull(gone.status)
        val tombstoneKeys = obj("library_200_since_tombstone").getValue("data").jsonArray.single().jsonObject.keys
        assertEquals(setOf("id", "client_ref", "deleted", "updated_at"), tombstoneKeys)

        val before = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_page")).data
        assertTrue(before.any { it.id == gone.id })
        val merged = LibraryRepository.merge(before, tombstones.data)
        assertEquals(before.size - 1, merged.size)
        val fresh = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_page_after_delete")).data
        assertEquals("merging the tombstone equals a fresh full sync", fresh.map { it.id }.toSet(), merged.map { it.id }.toSet())
    }

    @Test
    fun `a sync with no changes is an empty page`() {
        val page = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_since_empty"))
        assertTrue(page.data.isEmpty())
        assertNull(page.nextCursor)
        assertTrue(isIsoWithOffset(page.serverTime))
    }

    @Test
    fun `a full sync after deletions carries tombstones that never enter the cache`() {
        val page = ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_with_tombstones"))
        val deleted = page.data.count { it.deleted }
        val live = page.data.size - deleted
        assertTrue("$deleted tombstones, $live live", deleted >= 1 && live >= 1)
        val cache = LibraryRepository.merge(emptyList(), page.data)
        assertEquals(live, cache.size)
        assertTrue(cache.none { it.deleted })
    }

    // ---- transcripts -------------------------------------------------------------------------------------------------------

    @Test
    fun `a completed stereo transcript decodes into segments`() {
        val t = ApiJson.decodeFromString(TranscriptDto.serializer(), raw("transcripts_show_200_final_completed"))
        assertEquals(Triple("final", "completed", "assemblyai"), Triple(t.kind, t.status, t.provider))
        assertEquals("en", t.language)
        assertNull(t.error)
        assertTrue(t.text!!.startsWith("Me: This is a test"))
        assertEquals(4, t.segments.size)
        assertEquals(listOf(1 to "Me", 2 to "Client", 1 to "Me", 2 to "Client"), t.segments.map { it.channel to it.speaker })
        assertTrue(t.segments.all { it.startMs < it.endMs && it.text.isNotEmpty() })
        assertEquals(t.segments.sortedBy { it.startMs }, t.segments)
        assertEquals(SegmentDto(1, "Me", 400, 3_700, "This is a test of the EchoLedger transcription system."), t.segments[0])
    }

    @Test
    fun `a mono transcript is one speaker on channel one`() {
        val t = ApiJson.decodeFromString(TranscriptDto.serializer(), raw("transcripts_show_200_final_mono"))
        assertEquals(listOf(1 to "Speaker"), t.segments.map { it.channel to it.speaker })
    }

    @Test
    fun `a failed transcript carries a reason and no text`() {
        val t = ApiJson.decodeFromString(TranscriptDto.serializer(), raw("transcripts_show_200_final_failed"))
        assertEquals("failed", t.status)
        assertNull(t.text)
        assertNull(t.language)
        assertTrue(t.segments.isEmpty())
        assertTrue(t.error!!.length > 10)
    }

    @Test
    fun `a transcript still in flight decodes with no segments`() {
        val t = ApiJson.decodeFromString(TranscriptDto.serializer(), raw("transcripts_show_200_final_processing"))
        assertEquals("submitted", t.status)
        assertNull(t.text)
        assertNull(t.error)
        assertTrue(t.segments.isEmpty())
    }

    @Test
    fun `live transcripts posted by the app come back intact`() {
        val stored = ApiJson.decodeFromString(TranscriptDto.serializer(), raw("transcripts_store_201_live"))
        val fetched = ApiJson.decodeFromString(TranscriptDto.serializer(), raw("transcripts_show_200_live"))
        assertEquals(stored, fetched)
        assertEquals(listOf(1 to "Me", 2 to "Client"), stored.segments.map { it.channel to it.speaker })
        val posted = ApiJson.encodeToString(
            DeviceTranscript.serializer(),
            DeviceTranscript("live", "assemblyai", "en", stored.segments)
        )
        val back = ApiJson.parseToJsonElement(posted).jsonObject.getValue("segments").jsonArray.map { it.jsonObject }
        for ((sent, got) in back.zip(stored.segments)) {
            assertEquals(got.text, sent.getValue("text").jsonPrimitive.content)
            assertEquals(got.startMs, sent.getValue("start_ms").jsonPrimitive.content.toLong())
        }
    }

    // ---- settings that change the shape of the answers ----------------------------------------------------------------------------

    @Test
    fun `the caption policy decides which channels get a grant`() {
        val clientOnly = ApiJson.decodeFromString(SttTokenResponse.serializer(), raw("stt_token_200_client_only"))
        assertEquals("the server drops the microphone grant", listOf("client"), clientOnly.grants.map { it.channel })
        assertEquals(
            "client_only",
            ApiJson.decodeFromString(ServerConfig.serializer(), raw("config_200_captions_client_only")).captions.channels
        )

        val off = ApiJson.decodeFromString(SttTokenResponse.serializer(), raw("stt_token_200_captions_off"))
        assertEquals("off", off.mode)
        assertNull(off.provider)
        assertNull(off.expiresAt)
        assertTrue(off.grants.isEmpty() && off.keyterms.isEmpty())
        assertEquals("off", ApiJson.decodeFromString(ServerConfig.serializer(), raw("config_200_captions_off")).captions.channels)

        val dictation = ApiJson.decodeFromString(SttTokenResponse.serializer(), raw("stt_token_200_dictation_captions_off"))
        assertEquals("dictation ignores the caption policy", listOf("me"), dictation.grants.map { it.channel })
    }

    @Test
    fun `on-device captions hand out no grants`() {
        val config = ApiJson.decodeFromString(ServerConfig.serializer(), raw("config_200_on_device_captions"))
        val live = config.providers.getValue("live_captions")
        assertEquals("whispercpp_local" to "on_device", live.provider to live.mode)
        val token = ApiJson.decodeFromString(SttTokenResponse.serializer(), raw("stt_token_200_on_device"))
        assertEquals("whispercpp_local" to "on_device", token.provider to token.mode)
        assertTrue(token.grants.isEmpty())
        assertNull(token.expiresAt)
    }

    @Test
    fun `providers that need channel files say so in config and in the session response`() {
        val config = ApiJson.decodeFromString(ServerConfig.serializer(), raw("config_200_channel_artifacts"))
        val final = config.providers.getValue("final_transcript")
        assertEquals("sync", final.mode)
        assertEquals("flac", final.preferredPrimary)
        assertEquals(listOf("primary", "channel_1", "channel_2"), final.requiredArtifacts)

        val created = ApiJson.decodeFromString(CreateSessionResponse.serializer(), raw("sessions_store_201_three_artifacts"))
        assertEquals(listOf("primary", "channel_1", "channel_2"), created.requiredArtifacts)
        // API.md promises no order for the upload slots; the upload worker finds the primary one by role.
        assertEquals(listOf("channel_1", "channel_2", "primary"), created.recordings.map { it.role }.sorted())
        assertEquals("primary", created.recordings.first { it.role == "primary" }.role)
        assertTrue(created.recordings.all { it.upload.status == "pending" })
    }

    @Test
    fun `an on-device final provider is announced as device mode`() {
        val config = ApiJson.decodeFromString(ServerConfig.serializer(), raw("config_200_device_mode"))
        assertEquals("device", config.providers.getValue("final_transcript").mode)
    }

    @Test
    fun `pins arrive with registration and the app accepts them`() {
        val registered = ApiJson.decodeFromString(RegisterResponse.serializer(), raw("register_201_with_pins"))
        val fetched = ApiJson.decodeFromString(ServerConfig.serializer(), raw("config_200_with_pins"))
        assertEquals(2, registered.config.pins.size)
        assertEquals(registered.config.pins, fetched.pins)
        assertEquals("the app's own pin parser keeps every pin the server sent", fetched.pins, Pins.normalize(fetched.pins))
        assertNotNull(Pins.pinnerFor("https://ledger.example.com", fetched.pins))
    }

    @Test
    fun `repeating the creation of a deleted session returns its tombstone`() {
        val again = ApiJson.decodeFromString(CreateSessionResponse.serializer(), raw("sessions_store_200_after_delete"))
        assertTrue(again.session.deleted)
        assertTrue(isId(again.session.id) && isIsoWithOffset(again.session.updatedAt))
        assertTrue("there is nothing left to upload to", again.recordings.isEmpty())
        assertEquals(listOf("primary"), again.requiredArtifacts)
    }

    // ---- requests the app sends ------------------------------------------------------------------------------------------------

    @Test
    fun `the session request the app serialises matches the server's validation rules`() {
        val body = ApiJson.encodeToString(
            CreateSessionRequest.serializer(),
            CreateSessionRequest(
                clientRef = "3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22",
                sourceApp = "whatsapp",
                clientId = null,
                title = null,
                startedAt = "2026-10-24T09:00:00Z",
                endedAt = "2026-10-24T09:00:08Z",
                durationMs = 8_000,
                language = null,
                artifacts = listOf(ArtifactDto("primary", "audio/ogg", "opus", 2, 48_000, 10_485_760, "ab".repeat(32), 8_000))
            )
        )
        val sent = ApiJson.parseToJsonElement(body).jsonObject
        // Required by POST /api/sessions: client_ref, source_app, started_at, artifacts; null members are omitted.
        for (key in listOf("client_ref", "source_app", "started_at", "artifacts")) assertTrue(key, key in sent)
        assertEquals(setOf("client_ref", "source_app", "started_at", "ended_at", "duration_ms", "artifacts"), sent.keys)
        val artifact = sent.getValue("artifacts").jsonArray.single().jsonObject
        for (key in listOf("role", "mime", "channels", "size_bytes", "sha256")) assertTrue(key, key in artifact)
        assertEquals(64, artifact.getValue("sha256").jsonPrimitive.content.length)
        val upload = obj("config_200_android").getValue("upload").jsonObject
        val accepted = upload.getValue("accepted_mimes").jsonArray.map { it.jsonPrimitive.content }
        assertTrue("audio/ogg" in accepted)
    }

    // ---- the typed client over the wire ------------------------------------------------------------------------------------------

    @Test
    fun `the client runs a whole session lifecycle against server responses`() = runBlocking {
        val created = ApiJson.decodeFromString(CreateSessionResponse.serializer(), raw("sessions_store_201"))
        val sessionId = created.session.id
        val recordingId = created.recordings.single().id
        val transcriptId = obj("transcripts_show_200_final_completed").getValue("id").jsonPrimitive.content
        contractServer(
            route("POST", "/api/devices/register", 201, "register_201_android"),
            route("GET", "/api/config", 200, "config_200_android"),
            route("GET", "/api/clients", 200, "clients_200"),
            route("POST", "/api/stt/token", 200, "stt_token_200_live_captions"),
            route("POST", "/api/sessions", 201, "sessions_store_201"),
            route("GET", "/api/recordings/$recordingId", 200, "recordings_show_200_uploading"),
            route("POST", "/api/recordings/$recordingId/chunks", 200, "chunks_200_last"),
            route("POST", "/api/recordings/$recordingId/complete", 202, "recordings_complete_202"),
            route("POST", "/api/sessions/$sessionId/transcripts", 201, "transcripts_store_201_live"),
            route("GET", "/api/library", 200, "library_200_page"),
            route("GET", "/api/sessions/$sessionId", 200, "sessions_show_200_completed"),
            route("GET", "/api/transcripts/$transcriptId", 200, "transcripts_show_200_final_completed"),
            route("DELETE", "/api/sessions/$sessionId", 204, "")
        )

        val registered = client(withToken = false).register("ABCD-EFGH", "S25 Ultra", "0.1.3", "16")
        assertEquals("phone", registered.device.sourceDevice)

        val api = client()
        assertEquals(30, api.config().retentionDays)
        assertEquals(listOf("Acme Holdings", "Birch & Co"), api.clients().data.map { it.name })
        assertEquals(2, api.sttToken("live_captions", listOf("me", "client"), "01m5qa084edsznvjc59xnyt37x").grants.size)

        val request = CreateSessionRequest(
            clientRef = created.session.clientRef!!,
            sourceApp = "zoom",
            clientId = null,
            title = null,
            startedAt = "2026-10-24T15:56:41Z",
            endedAt = "2026-10-24T15:56:49Z",
            durationMs = 8_000,
            language = null,
            artifacts = listOf(ArtifactDto("primary", "audio/wav", "pcm_s16le", 2, 16_000, 512_044, "0".repeat(64), 8_000))
        )
        val slot = api.createSession(request).recordings.single()
        assertEquals("pending", slot.upload.status)
        assertEquals(listOf(0), api.recordingStatus(slot.id).upload.receivedChunks)
        val state = api.uploadChunk(slot.id, 1, byteArrayOf(1, 2, 3), "ab".repeat(32))
        assertEquals(Triple(listOf(0, 1), 2, "uploading"), Triple(state.receivedChunks, state.totalChunks, state.status))
        api.complete(slot.id)
        api.postTranscript(sessionId, DeviceTranscript("live", "assemblyai", "en", emptyList()))

        assertEquals(5, api.library(null, null).data.size)
        assertTrue(api.sessionDetail(sessionId).playbackUrl!!.contains("/audio?expires="))
        assertEquals(4, api.transcript(transcriptId).segments.size)
        api.deleteSession(sessionId)

        val seen = server!!.requests
        assertTrue(seen.filter { it.url.encodedPath != "/api/devices/register" }.all { it.headers["Authorization"] == "Bearer $TOKEN" })
        assertNull(seen.first { it.url.encodedPath == "/api/devices/register" }.headers["Authorization"])
        assertTrue(seen.all { it.headers["Accept"] == "application/json" })
    }

    @Test
    fun `the library query sends since and cursor`() = runBlocking {
        contractServer(route("GET", "/api/library", 200, "library_200_cursor_page1"))
        val page = client().library("2026-10-24T15:58:56+00:00", "abc")
        assertNotNull(page.nextCursor)
        val sent = server!!.requests.single().url
        assertEquals("2026-10-24T15:58:56+00:00", sent.queryParameter("since"))
        assertEquals("abc", sent.queryParameter("cursor"))
        assertEquals("100", sent.queryParameter("limit"))
        assertTrue("the + of the UTC offset must be percent-encoded", "%2B" in sent.encodedQuery!!)
    }

    @Test
    fun `server errors surface the message the user sees`() = runBlocking {
        val cases = listOf(
            Triple("errors_403_missing_ability", 403, "Invalid ability provided."),
            Triple("errors_404_session", 404, null),
            Triple("sessions_store_422_validation", 422, null),
            Triple("recordings_complete_422_missing_chunks", 422, "Upload is incomplete."),
            Triple("chunks_409_already_complete", 409, "Recording upload is already complete."),
            Triple("chunks_413_too_large", 413, "Chunk is larger than expected."),
            Triple("chunks_422_checksum_mismatch", 422, "Chunk checksum mismatch."),
            Triple("errors_429_stt_token", 429, "Too Many Attempts."),
            Triple("transcripts_store_422_final_from_device", 422, "This session is transcribed by the server provider.")
        )
        for ((fixture, status, message) in cases) {
            val expected = message ?: raw(fixture).field("message")
            contractServer(Route("*", Regex(".*"), status, raw(fixture)))
            try {
                callFor(client(), fixture)
                fail("$fixture: expected an ApiException")
            } catch (e: ApiException) {
                assertEquals(fixture, status, e.status)
                assertEquals(fixture, expected, e.message)
            }
            server?.close()
        }
    }

    @Test
    fun `every unauthorised variant means the phone must pair again`() = runBlocking {
        val variants = listOf(
            "errors_401_missing_token",
            "errors_401_invalid_token",
            "errors_401_expired_token",
            "errors_401_revoked_token"
        )
        for (fixture in variants) {
            contractServer(Route("GET", Regex("/api/config"), 401, raw(fixture)))
            try {
                client().config()
                fail("$fixture: expected NotPairedException")
            } catch (_: NotPairedException) {
                // expected
            }
            server?.close()
        }
    }

    @Test
    fun `a bad pairing code reports the server's reason`() = runBlocking {
        for ((fixture, expected) in listOf(
            "register_422_invalid_code" to "This pairing code is invalid, expired, or already used.",
            "register_422_used_code" to "This pairing code is invalid, expired, or already used.",
            "register_422_validation" to "The pairing code field is required. (and 2 more errors)"
        )) {
            contractServer(Route("POST", Regex("/api/devices/register"), 422, raw(fixture)))
            try {
                client(withToken = false).register("ABCD-EFGH", "S25 Ultra", "0.1.3", "16")
                fail("$fixture: expected an ApiException")
            } catch (e: ApiException) {
                assertEquals(422, e.status)
                assertEquals(expected, e.message)
            }
            server?.close()
        }
    }

    /** The call that produces the error held by [fixture]. */
    private suspend fun callFor(api: ApiClient, fixture: String) {
        val finalFromDevice = DeviceTranscript("final", "parakeet_local", null, emptyList())
        when (fixture) {
            "errors_403_missing_ability" -> api.library(null, null)
            "errors_404_session" -> api.sessionDetail("01zzzzzzzzzzzzzzzzzzzzzzzz")
            "sessions_store_422_validation" -> api.createSession(sampleRequest())
            "recordings_complete_422_missing_chunks" -> api.complete("r1")
            "errors_429_stt_token" -> api.sttToken("dictation", listOf("me"), null)
            "transcripts_store_422_final_from_device" -> api.postTranscript("s1", finalFromDevice)
            else -> api.uploadChunk("r1", 1, byteArrayOf(0), "ab")
        }
    }

    private fun sampleRequest() = CreateSessionRequest(
        clientRef = "3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22",
        sourceApp = "zoom",
        clientId = null,
        title = null,
        startedAt = "2026-10-24T15:56:41Z",
        endedAt = null,
        durationMs = null,
        language = null,
        artifacts = listOf(ArtifactDto("primary", "audio/wav", null, 2, null, 1, "0".repeat(64), null))
    )

    // ---- a scripted server -------------------------------------------------------------------------------------------------------

    private class ContractServer(private val routes: List<Route>) : AutoCloseable {
        private val mock = MockWebServer()
        val requests = CopyOnWriteArrayList<RecordedRequest>()
        val baseUrl: String get() = "http://localhost:${mock.port}"

        init {
            mock.dispatcher = object : Dispatcher() {
                override fun dispatch(request: RecordedRequest): MockResponse {
                    requests += request
                    val route = routes.firstOrNull {
                        (it.method == "*" || it.method == request.method) && it.path.matches(request.url.encodedPath)
                    }
                    val builder = MockResponse.Builder().code(route?.status ?: 404).addHeader("Content-Type", "application/json")
                    builder.body(route?.body ?: """{"message":"The route could not be found."}""")
                    return builder.build()
                }
            }
            mock.start()
        }

        override fun close() = mock.close()
    }

    private companion object {
        const val TOKEN = "2|eld_TOKEN_PLACEHOLDER"
    }
}
