package com.echoledger.mobile.net

import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.CompleteAnswer
import com.echoledger.mobile.data.CreateSessionResponse
import com.echoledger.mobile.data.RecordingUpload
import com.echoledger.mobile.data.ServerConfig
import com.echoledger.mobile.data.TranscriptDto
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** The v1.1 server answers (docs/API.md "since v1.1") decode with the app's own types. */
class ServerContractV11Test {
    private fun raw(name: String): String {
        val stream = checkNotNull(javaClass.classLoader!!.getResourceAsStream("fixtures/server/$name.json")) { "fixture $name is missing" }
        return stream.bufferedReader().use { it.readText() }
    }

    private fun code(name: String): String? = ApiJson.parseToJsonElement(raw(name)).jsonObject["code"]?.jsonPrimitive?.content

    @Test
    fun `the v1_1 config names its contract version and the month usage`() {
        val config = ApiJson.decodeFromString(ServerConfig.serializer(), raw("config_200_v1_1"))

        assertEquals("1.1", config.apiVersion)
        assertNotNull(config.usage)
        assertTrue(config.usage!!.warnAtPercent in 1..100)
    }

    @Test
    fun `an open session has no upload slots and a finished one has them`() {
        val open = ApiJson.decodeFromString(CreateSessionResponse.serializer(), raw("sessions_store_201_open"))
        val finished = ApiJson.decodeFromString(CreateSessionResponse.serializer(), raw("sessions_finish_200"))

        assertTrue(open.recordings.isEmpty())
        assertEquals("recording", open.session.status)
        assertTrue(finished.recordings.any { it.role == "primary" })
    }

    @Test
    fun `a failed upload names its reason and needs every chunk again`() {
        val failed = ApiJson.decodeFromString(RecordingUpload.serializer(), raw("recordings_show_200_failed"))

        assertEquals("failed", failed.upload.status)
        assertTrue(failed.upload.receivedChunks.isEmpty())
        assertNotNull(failed.upload.error)
        assertEquals("verification_failed", code("recordings_complete_422_verification_failed"))
    }

    @Test
    fun `a failed transcript carries its error code`() {
        val transcript = ApiJson.decodeFromString(TranscriptDto.serializer(), raw("transcripts_show_200_final_failed_no_audio"))

        assertEquals("failed", transcript.status)
        assertEquals("no_audio", transcript.errorCode)
    }

    @Test
    fun `every refusal the apps branch on has its machine-readable code`() {
        assertEquals("budget_exceeded", code("stt_token_403_budget_exceeded"))
        assertEquals("session_not_recording", code("stt_token_409_session_not_recording"))
        assertEquals("session_required", code("stt_token_422_session_required"))
        assertEquals("session_not_found", code("sessions_finish_404"))
        assertEquals("rate_limited", code("errors_429_retry_after"))
    }

    @Test
    fun `usage answers say whether a report was new`() {
        val duplicate = ApiJson.parseToJsonElement(raw("usage_200_duplicate")).jsonObject.getValue("report").jsonObject
        assertEquals("true", duplicate.getValue("duplicate").jsonPrimitive.content)
        assertEquals("ready", ApiJson.decodeFromString(CompleteAnswer.serializer(), """{"status":"ready"}""").status)
    }
}
