package com.echoledger.mobile.testsupport

/**
 * JSON bodies shaped exactly like the Laravel API produces them (web/app/Http/Resources, the device, config, session
 * and recording controllers, and docs/API.md). Timestamps use Carbon's toAtomString form, `+00:00` rather than `Z`.
 */
object ServerFixtures {
    // Placeholder pins in the documented format (current and backup); they match no real certificate.
    const val PIN_A = "sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
    const val PIN_B = "sha256/BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBE="
    const val DEVICE_ID = "01JDEVICE0000000000000000A"
    const val SESSION_ID = "01JSESSION000000000000000A"
    const val RECORDING_ID = "01JRECORD0000000000000000A"
    const val TRANSCRIPT_ID = "01JTRANSCRIPT00000000000A"

    fun deviceObject(name: String = "Pixel 9"): String {
        val createdAt = "2026-10-24T09:00:00+00:00"
        return """{"id":"$DEVICE_ID","name":"$name","platform":"android","source_device":"phone","created_at":"$createdAt"}"""
    }

    fun config(pins: List<String> = listOf(PIN_A, PIN_B), captions: String = "both") = """
        {
          "device": {"id":"$DEVICE_ID","name":"Pixel 9","platform":"android","source_device":"phone"},
          "server_time": "2026-10-24T09:00:00+00:00",
          "retention_days": 30,
          "upload": {
            "chunk_size_bytes": 5242880,
            "max_recording_bytes": 2147483648,
            "accepted_mimes": ["audio/ogg","audio/opus","audio/flac","audio/wav","audio/x-wav","audio/mpeg","audio/mp4","audio/aac","audio/webm","audio/amr"]
          },
          "providers": {
            "live_captions": {"provider":"assemblyai","mode":"cloud"},
            "dictation": {"provider":"assemblyai","mode":"cloud"},
            "final_transcript": {"provider":"assemblyai","mode":"async_webhook","required_artifacts":["primary"],"preferred_primary":"ogg_opus"},
            "voice_note": {"provider":"assemblyai","mode":"async_webhook","required_artifacts":["primary"],"preferred_primary":"ogg_opus"}
          },
          "captions": {"channels":"$captions"},
          "pins": [${pins.joinToString(",") { "\"$it\"" }}]
        }
    """.trimIndent()

    fun registerResponse(token: String = "7|eld_0123456789abcdef", pins: List<String> = listOf(PIN_A, PIN_B), name: String = "Pixel 9") =
        """
        {
          "device": ${deviceObject(name)},
          "token": "$token",
          "token_expires_at": "2027-10-24T09:00:00+00:00",
          "config": ${config(pins)}
        }
        """.trimIndent()

    fun clients() = """
        {"data":[
          {"id":"01JCLIENT0000000000000000A","name":"Acme Holdings","color":"#8B5CF6","keyterm_count":12,"updated_at":"2026-10-20T10:00:00+00:00"},
          {"id":"01JCLIENT0000000000000000B","name":"Zed Logistics","color":null,"keyterm_count":0,"updated_at":null}
        ]}
    """.trimIndent()

    fun libraryItem(
        id: String,
        title: String? = "Call with Acme",
        startedAt: String = "2026-10-20T10:00:00+00:00",
        updatedAt: String = "2026-10-20T10:05:00+00:00",
        sourceDevice: String = "phone",
        sourceApp: String = "whatsapp",
        status: String = "completed",
        finalStatus: String? = "completed",
        preview: String? = "Thanks for calling about the invoice",
        client: String? = "Acme Holdings"
    ): String {
        val deviceLabel = sourceDevice.replaceFirstChar { it.uppercase() }
        val appLabel = when (sourceApp) {
            "whatsapp" -> "WhatsApp"
            "whatsapp_business" -> "WhatsApp Business"
            "google_meet" -> "Google Meet"
            "zoom" -> "Zoom"
            "voice_note" -> "Voice note"
            else -> "Other"
        }
        val icon = mapOf("phone" to "device-phone-mobile", "desktop" to "computer-desktop", "web" to "globe-alt")[sourceDevice]
        val titleJson = title?.let { "\"$it\"" } ?: "null"
        val clientJson = client?.let { """{"id":"01JCLIENT0000000000000000A","name":"$it"}""" } ?: "null"
        val finalId = finalStatus?.let { "\"$TRANSCRIPT_ID\"" } ?: "null"
        val finalStatusJson = finalStatus?.let { "\"$it\"" } ?: "null"
        val previewJson = preview?.let { "\"$it\"" } ?: "null"
        return """
            {"id":"$id","client_ref":"3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22","deleted":false,
             "source_device":"$sourceDevice","source_device_label":"$deviceLabel","source_device_icon":"$icon",
             "source_app":"$sourceApp","source_app_label":"$appLabel",
             "device":{"id":"$DEVICE_ID","name":"Pixel 9"},"client":$clientJson,
             "title":$titleJson,"started_at":"$startedAt","ended_at":"2026-10-20T10:42:10+00:00","duration_ms":2530000,"status":"$status",
             "transcript":{"final_status":$finalStatusJson,"final_transcript_id":$finalId,"live_available":true,"live_transcript_id":null,"preview":$previewJson},
             "updated_at":"$updatedAt"}
        """.trimIndent()
    }

    fun tombstone(id: String, updatedAt: String = "2026-10-21T08:00:00+00:00") =
        """{"id":"$id","client_ref":"9a1d0000-0000-4000-8000-000000000000","deleted":true,"updated_at":"$updatedAt"}"""

    fun libraryPage(items: List<String>, nextCursor: String? = null, serverTime: String = "2026-10-24T09:05:00+00:00") =
        """{"data":[${items.joinToString(",")}],"next_cursor":${nextCursor?.let { "\"$it\"" } ?: "null"},"server_time":"$serverTime"}"""

    /** `POST /api/sessions` response: a pending upload slot for the primary artifact. */
    fun sessionCreated(
        recordingId: String = RECORDING_ID,
        chunkSize: Long = 5_242_880,
        totalChunks: Int = 1,
        received: List<Int> = emptyList(),
        uploadStatus: String = "pending",
        sessionId: String = SESSION_ID
    ) = """
        {"session": ${libraryItem(sessionId, finalStatus = null, status = "uploading", preview = null)},
         "recordings":[{"id":"$recordingId","role":"primary",
           "upload":{"chunk_size_bytes":$chunkSize,"total_chunks":$totalChunks,"received_chunks":[${received.joinToString(
        ","
    )}],"status":"$uploadStatus"}}],
         "required_artifacts":["primary"]}
    """.trimIndent()

    fun chunkAck(received: List<Int>, total: Int, status: String = "uploading") =
        """{"received_chunks":[${received.joinToString(",")}],"total_chunks":$total,"status":"$status"}"""

    fun recordingStatus(received: List<Int> = listOf(0), total: Int = 2, status: String = "uploading") = """
        {"id":"$RECORDING_ID","role":"primary",
         "upload":{"chunk_size_bytes":5242880,"total_chunks":$total,"received_chunks":[${received.joinToString(",")}],"status":"$status"}}
    """.trimIndent()

    fun transcript(
        status: String = "completed",
        kind: String = "final",
        error: String? = null,
        segments: String = DEFAULT_SEGMENTS,
        text: String? = "Hello there. Hi, thanks for calling."
    ) = """
        {"id":"$TRANSCRIPT_ID","session_id":"$SESSION_ID","kind":"$kind","provider":"assemblyai","status":"$status","language":"en",
         "text":${text?.let { "\"$it\"" } ?: "null"},"segments":$segments,"error":${error?.let { "\"$it\"" } ?: "null"},
         "created_at":"2026-10-24T09:10:00+00:00","completed_at":"2026-10-24T09:11:00+00:00"}
    """.trimIndent()

    const val DEFAULT_SEGMENTS = """[
        {"channel":1,"speaker":"Me","start_ms":250,"end_ms":2600,"text":"Hello there.","words":[{"text":"Hello","start_ms":250,"end_ms":650,"confidence":0.97}]},
        {"channel":2,"speaker":"Client","start_ms":12400,"end_ms":15000,"text":"Hi, thanks for calling.","words":[]}
    ]"""

    fun sessionDetail(playbackUrl: String? = "http://localhost/api/recordings/$RECORDING_ID/audio?expires=1&signature=abc") = """
        ${libraryItem(SESSION_ID).dropLast(1)},
         "recordings":[{"id":"$RECORDING_ID","role":"primary","mime":"audio/ogg","size_bytes":10485760,"duration_ms":2530000,"status":"ready"}],
         "transcripts":[{"id":"$TRANSCRIPT_ID","kind":"final","provider":"assemblyai","status":"completed","language":"en"}],
         "playback_url":${playbackUrl?.let { "\"$it\"" } ?: "null"}}
    """.trimIndent()

    fun validationError(message: String, field: String = "pairing_code") = """{"message":"$message","errors":{"$field":["$message"]}}"""

    /** AssemblyAI streaming grants as produced by AssemblyAiDriver::streamingGrant. */
    fun assemblyAiGrants(
        channels: List<String> = listOf("me", "client"),
        keyterms: List<String> = listOf("Acme Holdings", "Farah Khan")
    ): String {
        val terms = keyterms.joinToString(",") { "\"$it\"" }
        val init = if (keyterms.isEmpty()) "[]" else """[{"type":"UpdateConfiguration","keyterms_prompt":[$terms]}]"""
        val grants = channels.joinToString(",") { channel ->
            """{"channel":"$channel","transport":"websocket","protocol":"assemblyai_v3",
                "ws_url":"wss://streaming.assemblyai.com/v3/ws?speech_model=universal-3-6-pro&encoding=pcm_s16le&sample_rate=16000&inactivity_timeout=120&token=abc",
                "redeem_by":"2026-10-24T09:01:00+00:00","max_session_seconds":10800,
                "audio":{"encoding":"pcm_s16le","sample_rate":16000,"channels":1,"frame_ms":50},"init_messages":$init}"""
        }
        return """{"provider":"assemblyai","mode":"cloud","expires_at":"2026-10-24T09:01:00+00:00",""" +
            """"keyterms":[$terms],"grants":[$grants]}"""
    }
}
