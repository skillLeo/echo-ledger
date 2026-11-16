package com.echoledger.mobile.calls

import android.content.Context
import com.echoledger.mobile.core.CallNotices
import com.echoledger.mobile.core.CallTargets
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.recording.JournalIo
import java.io.File
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer

/**
 * Calls seen in the call apps' and the dialer's own call notifications (since v1.8, P0 0.2): an answered call (its
 * notification's timer runs) starts one, the notification going away ends it. They tell the missed-recording detector
 * about calls the capture helper never reported. Only the app and the times are kept, for [KEEP_DAYS]; never who the
 * call was with. The Google Play build has no notification listener, so it has none.
 */
object CallSightings {
    @Serializable
    data class Seen(val app: String, val startMs: Long, val endMs: Long? = null, val pkg: String)

    const val KEEP_DAYS = 7L
    private const val KEEP_MS = KEEP_DAYS * 24 * 60 * 60 * 1000
    private const val MAX = 300

    /** The source of a call notification's package: a call app, or a dialer for a SIM call; null for anything else. */
    fun appOf(pkg: String): String? = when (pkg) {
        in CallNotices.DIALERS -> SourceApp.PHONE_CALL.wire
        in CallTargets.KNOWN.keys -> CallTargets.KNOWN.getValue(pkg).wire
        else -> null
    }

    // ---- pure (unit-tested) -----------------------------------------------------------------------------------------

    /** [pkg]'s call was answered at [answeredAtMs]: a new sighting, unless one of that app is already open. */
    fun answered(list: List<Seen>, pkg: String, answeredAtMs: Long, nowMs: Long): List<Seen> {
        val app = appOf(pkg) ?: return list
        if (list.any { it.pkg == pkg && it.endMs == null }) return list
        return rotate(list + Seen(app, answeredAtMs, null, pkg), nowMs)
    }

    /** [pkg]'s call notification went away at [nowMs]: its open sighting ends. */
    fun ended(list: List<Seen>, pkg: String, nowMs: Long): List<Seen> =
        list.map { if (it.pkg == pkg && it.endMs == null) it.copy(endMs = nowMs) else it }

    fun rotate(list: List<Seen>, nowMs: Long): List<Seen> = list.filter { it.startMs >= nowMs - KEEP_MS }.takeLast(MAX)

    /** As the detector reads them: answered, with an end once the notification went away. */
    fun sightings(list: List<Seen>): List<MissedRecordings.Sighting> =
        list.map { MissedRecordings.Sighting(it.app, it.startMs, it.endMs, answered = true, by = MissedRecordings.NOTIFICATION) }

    // ---- on the phone (the file is read and written on the journal's I/O thread only) -------------------------------

    /** The sightings kept, with every change made before this call. */
    fun all(context: Context): List<Seen> {
        val app = context.applicationContext ?: context
        return JournalIo.read { synchronized(this) { read(app) } }
    }

    /** Changes the sightings on the I/O thread (a call notification never waits for the file). */
    fun update(context: Context, change: (List<Seen>) -> List<Seen>) {
        val app = context.applicationContext ?: context
        JournalIo.post {
            synchronized(this) {
                val before = read(app)
                val after = change(before)
                if (after != before) write(app, after)
            }
        }
    }

    /** What is older than [KEEP_DAYS] goes when the detector reads them, not only when a new call is seen. */
    fun trim(context: Context, nowMs: Long = System.currentTimeMillis()) = update(context) { rotate(it, nowMs) }

    fun clear(context: Context) {
        val app = context.applicationContext ?: context
        JournalIo.read { synchronized(this) { file(app).delete() } }
    }

    private fun read(context: Context): List<Seen> = runCatching {
        ApiJson.decodeFromString(ListSerializer(Seen.serializer()), file(context).readText())
    }.getOrDefault(emptyList())

    private fun write(context: Context, list: List<Seen>) {
        runCatching {
            val target = file(context)
            val tmp = File(target.parentFile, target.name + ".tmp")
            tmp.writeText(ApiJson.encodeToString(ListSerializer(Seen.serializer()), list))
            if (!tmp.renameTo(target)) {
                target.delete()
                tmp.renameTo(target)
            }
        }
    }

    private fun file(context: Context) = File(context.noBackupFilesDir, "call-sightings.json")
}
