package com.echoledger.mobile.testsupport

import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.data.SegmentDto
import java.io.ByteArrayInputStream

const val DEFAULT_RECORDING_ID = "11111111-2222-3333-4444-555555555555"

/** Pseudo-random but reproducible audio-like bytes: every offset holds a distinct-enough value to catch slicing errors. */
fun sampleBytes(size: Int): ByteArray = ByteArray(size) { ((it * 31 + it / 251) % 256).toByte() }

/** Stores a recording (metadata plus the encrypted payload) exactly like the capture and share paths do. */
fun EchoLedgerApp.storeRecording(
    id: String = DEFAULT_RECORDING_ID,
    bytes: ByteArray = sampleBytes(2_500),
    state: String = "queued",
    sourceApp: String = "whatsapp",
    startedAt: String = "2026-10-24T09:00:00Z",
    endedAt: String = "2026-10-24T09:01:00Z",
    durationMs: Long = 60_000,
    mime: String = "audio/ogg",
    codec: String = "opus",
    channels: Int = 2,
    sampleRate: Int = 48_000,
    clientId: String? = null,
    lastError: String? = null,
    liveSegments: List<SegmentDto> = emptyList(),
    liveProvider: String? = null
): LocalRecording = graph.recordings.importStream(
    LocalRecording(
        id = id,
        sourceApp = sourceApp,
        clientId = clientId,
        startedAt = startedAt,
        endedAt = endedAt,
        durationMs = durationMs,
        mime = mime,
        codec = codec,
        channels = channels,
        sampleRate = sampleRate,
        plainSize = 0,
        sha256 = "",
        state = state,
        lastError = lastError,
        liveSegments = liveSegments,
        liveProvider = liveProvider
    ),
    ByteArrayInputStream(bytes)
)
