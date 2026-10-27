package com.echoledger.mobile.recording

import android.content.Context
import android.media.AudioManager
import android.media.MediaRecorder
import android.os.ParcelFileDescriptor
import com.echoledger.mobile.capture.ICaptureHelper
import com.echoledger.mobile.core.CallTargets
import java.io.File
import kotlin.math.abs
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/**
 * Setup → Call capture → "Test call capture": records about ten seconds through the capture helper exactly like a
 * call (nothing is kept or uploaded) and says, stage by stage and in plain words, what works and how to fix what
 * does not. [classify] is pure, so every outcome is unit-tested.
 */
object CaptureTest {
    const val SECONDS = 10

    enum class Check { HELPER, APP, MY_VOICE, OTHER_SIDE, FILE }

    /** [ok] null: not checked (for example the other side outside a call). */
    data class Stage(val check: Check, val ok: Boolean?, val title: String, val detail: String)

    enum class Verdict { READY, PARTLY, NOT_READY }

    data class Result(val verdict: Verdict, val headline: String, val fix: String?, val stages: List<Stage>)

    /** What the run measured. Bytes are per channel (16-bit samples); peaks are 0–1. */
    data class Measured(
        val helperConnected: Boolean,
        val helperStale: Boolean = false,
        val whatsapp: Boolean = false,
        val whatsappBusiness: Boolean = false,
        val started: Boolean = false,
        val meBytes: Long = 0,
        val clientBytes: Long = 0,
        val mePeak: Float = 0f,
        val clientPeak: Float = 0f,
        val inCall: Boolean = false,
        val fileBytes: Long = 0,
        val helperProblem: String? = null,
        val otherAppsRecording: Int = 0
    )

    /** A level at or above this is sound, not the silence of a muted or blocked recorder. */
    const val HEARD = 0.003f

    fun classify(m: Measured): Result {
        val stages = mutableListOf<Stage>()
        if (!m.helperConnected) {
            stages += Stage(Check.HELPER, false, "Call capture helper", "Not running.")
            return Result(
                Verdict.NOT_READY,
                "Call capture isn't running, so calls are not recorded.",
                "Open Shizuku and tap Start (after a restart it has to be started again), then come back here and tap Connect via Shizuku. Without Shizuku, run the setup command from a computer again.",
                stages
            )
        }
        stages += Stage(
            Check.HELPER,
            !m.helperStale,
            "Call capture helper",
            if (m.helperStale) "Running, but an older version." else "Running."
        )
        val apps = listOfNotNull("WhatsApp".takeIf { m.whatsapp }, "WhatsApp Business".takeIf { m.whatsappBusiness })
        stages += Stage(
            Check.APP,
            apps.isNotEmpty(),
            "WhatsApp",
            if (apps.isEmpty()) "Neither WhatsApp nor WhatsApp Business is installed." else "${apps.joinToString(" and ")} found."
        )
        val meHeard = m.meBytes > 0 && m.mePeak >= HEARD
        stages += Stage(
            Check.MY_VOICE,
            meHeard,
            "Your voice",
            when {
                !m.started || m.meBytes == 0L -> "No sound arrived from the microphone."
                !meHeard -> "Sound arrived but it was silent."
                else -> "Heard."
            }
        )
        val clientHeard = m.clientBytes > 0 && m.clientPeak >= HEARD
        stages += Stage(
            Check.OTHER_SIDE,
            if (m.inCall) clientHeard else null,
            "The other side",
            when {
                !m.inCall -> "Checked only during a call."
                clientHeard -> "Heard."
                else -> "Silent during the call."
            }
        )
        stages += Stage(
            Check.FILE,
            m.fileBytes > 0,
            "Saving",
            if (m.fileBytes > 0) "A test file of ${m.fileBytes / 1024} KB was written and deleted." else "Nothing could be saved."
        )

        val reason = m.helperProblem?.let { " What Android said: $it" }.orEmpty()
        val otherRecorder = if (m.otherAppsRecording > 0) {
            " Another app is using the microphone right now; close it."
        } else {
            ""
        }
        return when {
            !m.started || (m.meBytes == 0L && m.clientBytes == 0L) -> Result(
                Verdict.NOT_READY,
                "No sound reached EchoLedger, so a call would be saved empty.",
                "Turn off or uninstall other call recorders (for example CallVault): Android lets only one app capture a call." +
                    "$otherRecorder " +
                    "Then tap Connect via Shizuku again and repeat the test.$reason",
                stages
            )

            m.helperStale -> Result(
                Verdict.PARTLY,
                "Call capture works but runs an older version, which misses recent fixes.",
                "Tap Connect via Shizuku again (or restart the phone and start Shizuku), then repeat the test.",
                stages
            )

            !meHeard -> Result(
                Verdict.PARTLY,
                "Your microphone came through silent.",
                "Speak during the test. If it stays silent, another app may be holding the microphone (a call recorder, for example CallVault).$otherRecorder$reason",
                stages
            )

            m.inCall && !clientHeard -> Result(
                Verdict.PARTLY,
                "Your voice is recorded, but the other side of the call is silent.",
                "This phone may keep call audio away from other apps. Recordings will contain your side only.$reason",
                stages
            )

            apps.isEmpty() -> Result(
                Verdict.PARTLY,
                "Capture works, but WhatsApp is not installed.",
                "Install WhatsApp or WhatsApp Business.",
                stages
            )

            m.fileBytes <= 0 -> Result(
                Verdict.PARTLY,
                "Sound arrived but could not be saved.",
                "Free some storage space and repeat the test.",
                stages
            )

            else -> Result(
                Verdict.READY,
                if (m.inCall) {
                    "Call capture works: both sides were heard."
                } else {
                    "Call capture works. To check the other side too, run the test during a WhatsApp call."
                },
                null,
                stages
            )
        }
    }

    /** Runs the test through [helper] (null: not connected). Must not run while a recording is going on. */
    suspend fun run(context: Context, helper: ICaptureHelper?, helperStale: Boolean, micSource: Int): Result = withContext(Dispatchers.IO) {
        val installed = { pkg: String -> runCatching { context.packageManager.getPackageInfo(pkg, 0) }.isSuccess }
        val audio = context.getSystemService(AudioManager::class.java)
        val base = Measured(
            helperConnected = helper != null && runCatching { helper.asBinder().pingBinder() }.getOrDefault(false),
            helperStale = helperStale,
            whatsapp = installed(CallTargets.WHATSAPP),
            whatsappBusiness = installed(CallTargets.WHATSAPP_BUSINESS),
            inCall = audio?.mode == AudioManager.MODE_IN_COMMUNICATION,
            otherAppsRecording = runCatching { audio?.activeRecordingConfigurations?.size ?: 0 }.getOrDefault(0)
        )
        if (!base.helperConnected || helper == null) return@withContext classify(base).also { log(context, it) }

        val since = System.currentTimeMillis()
        val pipe = ParcelFileDescriptor.createPipe()
        val started = runCatching { helper.startCapture(pipe[1], Pcm.CAPTURE_RATE, micSource) }.getOrDefault(false)
        pipe[1].close()
        var me = 0L
        var client = 0L
        var mePeak = 0f
        var clientPeak = 0f
        val file = File(context.cacheDir, "capture-test.ogg")
        if (started) {
            val encoder = OpusOggEncoder(file, Pcm.CAPTURE_RATE, 2)
            val stopAt = System.nanoTime() + SECONDS * 1_000_000_000L
            val input = ParcelFileDescriptor.AutoCloseInputStream(pipe[0])
            val reader = Thread {
                val buffer = ByteArray(Pcm.CAPTURE_RATE / 100 * 4 * 4)
                runCatching {
                    while (true) {
                        val n = input.read(buffer)
                        if (n <= 0) break
                        encoder.feed(buffer, n)
                        val (l, r) = Pcm.deinterleave(Pcm.fromLittleEndianBytes(buffer, n))
                        me += l.size * 2L
                        client += r.size * 2L
                        mePeak = maxOf(mePeak, peak(l))
                        clientPeak = maxOf(clientPeak, peak(r))
                    }
                }
            }.apply { start() }
            while (System.nanoTime() < stopAt) Thread.sleep(100)
            runCatching { helper.stopCapture() }
            reader.join(3_000)
            runCatching { input.close() }
            runCatching { encoder.finish() }
        } else {
            pipe[0].close()
        }
        val measured = base.copy(
            started = started,
            meBytes = me,
            clientBytes = client,
            mePeak = mePeak,
            clientPeak = clientPeak,
            fileBytes = file.length(),
            helperProblem = CaptureProblems.load(context)?.takeIf { it.at.toEpochMilli() >= since }?.message
        )
        file.delete()
        classify(measured).also { log(context, it) }
    }

    private fun peak(samples: ShortArray): Float {
        var max = 0
        for (s in samples) max = maxOf(max, abs(s.toInt()))
        return max / 32768f
    }

    private fun log(context: Context, result: Result) {
        CaptureLog.add(
            context,
            "capture test",
            "${result.verdict}: " +
                result.stages.joinToString("; ") {
                    "${it.title} ${if (it.ok == null) {
                        "-"
                    } else if (it.ok) {
                        "ok"
                    } else {
                        "fail"
                    }} (${it.detail})"
                }
        )
    }

    /** The microphone source the recording uses (Settings → Recording → microphone). */
    fun micSourceFor(setting: String): Int =
        if (setting == "mic") MediaRecorder.AudioSource.MIC else MediaRecorder.AudioSource.VOICE_COMMUNICATION
}
