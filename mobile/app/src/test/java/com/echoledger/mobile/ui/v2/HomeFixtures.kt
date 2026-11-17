package com.echoledger.mobile.ui.v2

import com.echoledger.mobile.core.ReadyChip
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.ui.v2.home.HomeFacts

/** Home's facts at the moments that matter, with Pakistani callers and real lengths. */
object HomeFixtures {
    fun waiting(id: String, caller: String, durationMs: Long, state: String = "pending_decision", app: String = "whatsapp") =
        LocalRecording(
            id = id,
            sourceApp = app,
            startedAt = "2026-11-17T06:00:00Z",
            endedAt = "2026-11-17T06:04:12Z",
            durationMs = durationMs,
            mime = "audio/ogg",
            codec = "opus",
            channels = 2,
            sampleRate = 48_000,
            plainSize = 1,
            sha256 = "x",
            state = state,
            callerName = caller
        )

    fun facts(
        chip: ReadyChip = ReadyChip.READY,
        captureReady: Boolean = true,
        mic: Boolean = true,
        notifications: Boolean = true,
        live: LiveRecording = LiveRecording(),
        urdu: Boolean = false,
        library: Boolean = true,
        pending: List<LocalRecording> = emptyList(),
        language: String? = "ur",
        syncing: Boolean = false,
        hasSynced: Boolean = true,
        offline: Boolean = false
    ) = HomeFacts(
        chip = chip,
        callsPossible = true,
        captureReady = captureReady,
        micAllowed = mic,
        notificationsAllowed = notifications,
        live = live,
        library = if (library) V2Fixtures.library(urdu) else emptyList(),
        pending = pending,
        transcriptionLanguage = language,
        syncing = syncing,
        hasSynced = hasSynced,
        offline = offline,
        usedMinutes = 212,
        monthlyMinutes = 300
    )
}
