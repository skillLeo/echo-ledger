package com.echoledger.mobile.calls

import android.Manifest
import android.content.ContentUris
import android.content.Context
import android.content.pm.ApplicationInfo
import android.net.Uri
import android.os.Build
import android.provider.CallLog
import android.provider.MediaStore
import android.telecom.TelecomManager
import android.util.Log
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.core.PhoneCalls
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.recording.CallJournal
import com.echoledger.mobile.recording.CaptureLog
import com.echoledger.mobile.recording.RecordingService
import java.io.File
import java.io.InputStream
import java.time.Instant
import java.time.LocalDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.UUID
import java.util.concurrent.TimeUnit
import kotlin.math.abs
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext

/**
 * The Phone app's own call recordings as the SIM-call path (since v1.3 for Samsung after a silent line; since v1.8, 0.3,
 * for every SIM call): when a phone maker keeps the phone line for its own dialer (One UI on the S25), the Phone app can
 * record each call itself (Samsung: Phone → ⋮ → Settings → Record calls → Auto record calls), and EchoLedger brings that
 * recording in right after the call: found in the media store in a known call-recording folder, made by that phone's
 * Phone app, matched to the SIM call in the call log, named after the contact or the number, shown on the after-call
 * card, then kept, transcribed and expired like any EchoLedger recording.
 *
 * Only calls the person asked EchoLedger to record are brought in (phone calls set to Always with auto-record on, or
 * Record chosen on the ask notification), as the capture journal tells. When EchoLedger recorded the call itself (with
 * sound), its own recording is kept and the Phone app's copy is left alone. A file is brought in only when the Phone app
 * made it, it was written during the call (and not changed since), and its length and size fit the call; a file the
 * phone does not say the maker of waits for the person to import it. Files are read through the media store only.
 * Nothing in the Phone app's folder is ever deleted or moved, unless the person turns on "Delete the Phone app's copy
 * after import"; Android then asks first.
 */
object PhoneAppRecordings {
    /**
     * A folder where a Phone app keeps its call recordings ([path] as the media store's RELATIVE_PATH, without slashes),
     * the apps that write its files there ([owners], the media store's OWNER_PACKAGE_NAME) and the phone makers whose
     * Phone app it is ([makers], Build.MANUFACTURER in lower case).
     */
    data class Folder(val brand: String, val path: String, val owners: Set<String>, val makers: Set<String>)

    private val SAMSUNG = setOf("com.samsung.android.dialer", "com.samsung.android.incallui")
    private val XIAOMI = setOf("com.android.incallui", "com.android.soundrecorder")
    private val OPPO = setOf(
        "com.android.incallui",
        "com.oplus.dialer",
        "com.oplus.incallui",
        "com.coloros.soundrecorder",
        "com.oneplus.dialer",
        "com.oneplus.incallui"
    )
    private val VIVO = setOf("com.android.incallui", "com.vivo.incallui", "com.android.bbksoundrecorder")
    private val HUAWEI = setOf("com.android.incallui", "com.huawei.soundrecorder", "com.hihonor.soundrecorder")

    private val SAMSUNG_MAKERS = setOf("samsung")
    private val XIAOMI_MAKERS = setOf("xiaomi", "redmi", "poco")
    private val OPPO_MAKERS = setOf("oppo", "realme", "oneplus")
    private val VIVO_MAKERS = setOf("vivo", "iqoo")
    private val HUAWEI_MAKERS = setOf("huawei", "honor")

    /** Google's Phone app, the dialer of many phones (its own recordings stay inside it, out of every other app's reach). */
    const val GOOGLE_DIALER = "com.google.android.dialer"

    /**
     * The known folders: Samsung's from its own help and the One UI file layout (Recordings/Call since One UI 4, Call
     * before), Xiaomi's from its support FAQ; the others as their dialers are widely reported to save them. Each one names
     * calls, so a voice memo folder never matches. Google's Phone app (Pixel and others) keeps its recordings inside the
     * app, where no other app can read them, so there is none for it.
     */
    val FOLDERS: List<Folder> = listOf(
        Folder("Samsung", "Recordings/Call", SAMSUNG, SAMSUNG_MAKERS),
        Folder("Samsung", "Call", SAMSUNG, SAMSUNG_MAKERS),
        Folder("Xiaomi, Redmi, POCO", "MIUI/sound_recorder/call_rec", XIAOMI, XIAOMI_MAKERS),
        Folder("Oppo, realme, OnePlus", "Music/Recordings/Call Recordings", OPPO, OPPO_MAKERS),
        Folder("Oppo, realme, OnePlus", "Recordings/Call Recordings", OPPO, OPPO_MAKERS),
        Folder("OnePlus", "Record/PhoneRecord", OPPO, setOf("oneplus")),
        Folder("vivo", "Record/Call", VIVO, VIVO_MAKERS),
        Folder("Huawei, Honor", "Sounds/CallRecord", HUAWEI, HUAWEI_MAKERS)
    )

    /** Every app that may own a call recording in a known folder, for the manifest's package visibility (Android 11+). */
    val OWNERS: Set<String> get() = FOLDERS.flatMap { it.owners }.toSet() + GOOGLE_DIALER

    /** Whether this phone's maker has a known call-recording folder (else there is nothing to watch or bring in). */
    fun supported(manufacturer: String): Boolean = FOLDERS.any { manufacturer.trim().lowercase() in it.makers }

    /** The known folder [relativePath] is in (a subfolder counts, case and slashes do not), or null; never a path that climbs. */
    fun folderOf(relativePath: String?): Folder? {
        val path = relativePath?.trim()?.trim('/')?.lowercase() ?: return null
        if (path.split('/').any { it == ".." || it == "." }) return null
        return FOLDERS.firstOrNull { f -> f.path.lowercase().let { path == it || path.startsWith("$it/") } }
    }

    /**
     * One audio file in the media store: [addedMs] and [modifiedMs] as the media store says (DATE_ADDED, DATE_MODIFIED),
     * [owner] the app that made it (OWNER_PACKAGE_NAME; null when the phone does not say), [sizeBytes] 0 when unknown.
     */
    data class MediaFile(
        val id: Long,
        val name: String,
        val path: String,
        val addedMs: Long,
        val durationMs: Long,
        val modifiedMs: Long = addedMs,
        val owner: String? = null,
        val sizeBytes: Long = 0
    ) {
        val endMs: Long get() = maxOf(addedMs, modifiedMs)
    }

    /**
     * One answered SIM call from the call log: when it started (rang or was dialled), its talk time, and who it was
     * with as the phone knows it (only for naming and matching, on the phone).
     */
    data class LogCall(val dateMs: Long, val talkS: Long, val number: String?, val name: String?, val incoming: Boolean)

    /** A call may ring or dial this long before it is answered (the recording starts at the answer). */
    const val RING_MAX_MS = 90_000L

    /** A file written this long after the call ended can still be its recording (the Phone app saves it at the end). */
    const val AFTER_END_MS = 3 * 60_000L

    /** A time in a file's name is believed when it is this close to the file's own times (else the clock or zone moved). */
    private const val STAMP_SLACK_MS = 2 * 60_000L

    /** At most this much audio per second of call (384 kbps: far above any phone's call recorder) ... */
    const val MAX_BYTES_PER_S = 48_000L

    /** ... and never more than this for one call. */
    const val MAX_BYTES = 256L * 1024 * 1024

    private val STAMPS = listOf(
        Regex("(?<!\\d)(\\d{8})_?(\\d{6})(?!\\d)") to "yyyyMMddHHmmss",
        Regex("(?<!\\d)(\\d{6})_(\\d{6})(?!\\d)") to "yyMMddHHmmss",
        Regex("(?<!\\d)(\\d{12})(?!\\d)") to "yyMMddHHmmss",
        Regex("(\\d{4})-(\\d{2})-(\\d{2})[ _](\\d{2})-(\\d{2})-(\\d{2})") to "yyyyMMddHHmmss"
    )

    /**
     * The start time a Phone app writes into a recording's name ("Call recording Ali_261029_101500.m4a",
     * "Ali(03001234567)_20261029101500.mp3", "2026-10-29 10-15-00"), in the phone's time zone; null without one. A
     * number that only looks like a time is passed over.
     */
    fun stampOf(name: String, zone: ZoneId = ZoneId.systemDefault()): Long? {
        for ((regex, pattern) in STAMPS) {
            for (m in regex.findAll(name)) {
                val digits = m.groupValues.drop(1).joinToString("")
                val time = runCatching { LocalDateTime.parse(digits, DateTimeFormatter.ofPattern(pattern)) }.getOrNull() ?: continue
                if (time.year < 2000) continue
                return time.atZone(zone).toInstant().toEpochMilli()
            }
        }
        return null
    }

    /** When [file] recorded: from the time in its name (when it agrees with the file's times), else its end minus its length. */
    fun spanOf(file: MediaFile, zone: ZoneId = ZoneId.systemDefault()): LongRange {
        val byTimes = file.endMs - file.durationMs
        val start = stampOf(file.name, zone)?.takeIf { it in (byTimes - STAMP_SLACK_MS)..(file.endMs + STAMP_SLACK_MS) } ?: byTimes
        return start..maxOf(file.endMs, start + file.durationMs)
    }

    /** Whether [file]'s name names [call]'s person: the number's last 7 digits, or the contact's name. */
    fun names(file: MediaFile, call: LogCall): Boolean {
        val digits = call.number?.filter(Char::isDigit)?.takeLast(7)?.takeIf { it.length >= 5 }
        val who = call.name?.trim()?.takeIf { it.length >= 2 }?.lowercase()
        return (digits != null && file.name.filter(Char::isDigit).contains(digits)) || (who != null && file.name.lowercase().contains(who))
    }

    /** From just before [call] rang to 3 minutes after it can have ended: when its recording's file is written. */
    fun windowOf(call: LogCall): LongRange = (call.dateMs - 5_000)..(call.dateMs + RING_MAX_MS + call.talkS * 1000 + AFTER_END_MS)

    /** A recording of a call with [talkS] of talk lasts about as long (the Phone app may start it as the call is dialled). */
    fun lengthFits(durationMs: Long, talkS: Long): Boolean {
        val talkMs = talkS * 1000
        return durationMs in (talkMs * 85 / 100 - 5_000)..(talkMs + RING_MAX_MS + 10_000)
    }

    /** The most bytes a recording of a call with [talkS] of talk can have. */
    fun maxBytes(talkS: Long): Long = minOf(MAX_BYTES, (talkS + 30) * MAX_BYTES_PER_S + 1_000_000)

    /**
     * Each call with its recording (pure, unit-tested), one file per call: files in a known folder, at least 1 s long, not
     * in [handled], written during the call (added and last changed between the call's start and 3 minutes after it can
     * have ended), as long as the call, that started between the call's start and its answer. The pairs that fit best go
     * first: a file naming the person, then the closest length to the talk time and the closest start.
     */
    fun match(
        calls: List<LogCall>,
        files: List<MediaFile>,
        handled: Set<Long> = emptySet(),
        zone: ZoneId = ZoneId.systemDefault(),
        trustOf: ((MediaFile, LogCall) -> Trust)? = null
    ): List<Pair<LogCall, MediaFile>> {
        val usable = files.filter { it.id !in handled && it.durationMs >= 1_000 && folderOf(it.path) != null }
        val pairs = calls.filter { it.talkS > 0 }.flatMap { call ->
            usable.mapNotNull { file ->
                // Trust comes before the slot: another app's or an oversize file never takes the call's place, and a
                // file the phone does not name the maker of only when no trusted one fits.
                val trust = trustOf?.invoke(file, call) ?: Trust.OK
                if (trust == Trust.NOT_THE_PHONE_APP || trust == Trust.TOO_LARGE) return@mapNotNull null
                val span = spanOf(file, zone)
                val talkMs = call.talkS * 1000
                val window = windowOf(call)
                val fits = span.first in (call.dateMs - 5_000)..(call.dateMs + RING_MAX_MS) &&
                    span.last in call.dateMs..window.last &&
                    file.addedMs in window && file.modifiedMs in window &&
                    lengthFits(file.durationMs, call.talkS)
                if (!fits) return@mapNotNull null
                val score = (if (trust == Trust.UNKNOWN_MAKER) 10_000_000_000L else 0L) +
                    (if (names(file, call)) 0L else 1_000_000_000L) + abs(file.durationMs - talkMs) + abs(span.first - call.dateMs) / 4
                Triple(call, file, score)
            }
        }
        val out = mutableListOf<Pair<LogCall, MediaFile>>()
        pairs.sortedBy { it.third }.forEach { (call, file, _) ->
            if (out.none { it.first == call || it.second.id == file.id }) out += call to file
        }
        return out.sortedBy { it.first.dateMs }
    }

    /** Whether a file that matched a call may be brought in by itself. */
    enum class Trust {
        /** Made by this phone's Phone app, and no larger than a recording of the call can be. */
        OK,

        /** The phone does not say which app made it: the person imports it, or not. */
        UNKNOWN_MAKER,

        /** Another app made it: never brought in. */
        NOT_THE_PHONE_APP,

        /** Larger than a recording of this call can be: never brought in. */
        TOO_LARGE
    }

    /** This phone, as trust needs it: its maker, its default dialer, and whether an app came with the phone. */
    class ThisPhone(val manufacturer: String, val defaultDialer: String?, val isSystemApp: (String) -> Boolean)

    /**
     * Whether [file], matched to [call], may be brought in (pure): its size fits the call, and [ownerTrust] trusts its
     * maker.
     */
    fun trust(file: MediaFile, call: LogCall, phone: ThisPhone): Trust {
        if (file.sizeBytes > maxBytes(call.talkS)) return Trust.TOO_LARGE
        return ownerTrust(file, phone)
    }

    /**
     * Who made [file] (pure): the phone's default dialer, or this phone maker's own Phone app as a system app (installed
     * with the phone, or an update of one). A package name alone proves nothing: an app installed later may take any name.
     */
    fun ownerTrust(file: MediaFile, phone: ThisPhone): Trust {
        val owner = file.owner?.trim()?.takeIf { it.isNotEmpty() } ?: return Trust.UNKNOWN_MAKER
        if (phone.defaultDialer != null && owner == phone.defaultDialer) return Trust.OK
        val folder = folderOf(file.path) ?: return Trust.NOT_THE_PHONE_APP
        val thisMaker = phone.manufacturer.trim().lowercase() in folder.makers
        return if (thisMaker && owner in folder.owners && phone.isSystemApp(owner)) Trust.OK else Trust.NOT_THE_PHONE_APP
    }

    /**
     * EchoLedger's own journal entry for each call (pure): a SIM call it saw (not an import) starting from just before the
     * call's start to its answer and talk time, one entry per call, the closest start first.
     */
    fun entriesFor(calls: List<LogCall>, journal: List<CallJournal.Call>): Map<LogCall, CallJournal.Call> {
        val mine = journal.filter { it.app == SourceApp.PHONE_CALL.wire && it.seenBy != CallJournal.Seen.PHONE_APP }
        val pairs = calls.flatMap { call ->
            mine.filter { it.atMs in (call.dateMs - 10_000)..(call.dateMs + RING_MAX_MS + call.talkS * 1000) }
                .map { Triple(call, it, abs(it.atMs - call.dateMs)) }
        }
        val out = LinkedHashMap<LogCall, CallJournal.Call>()
        pairs.sortedBy { it.third }.forEach { (call, entry, _) ->
            if (call !in out && out.values.none { it.id == entry.id }) out[call] = entry
        }
        return out
    }

    /** EchoLedger's own recording of a call: its times, and whether the two sides were kept apart. */
    data class Own(val startMs: Long, val endMs: Long, val channels: Int)

    /**
     * EchoLedger's own recording (with sound) of the call the Phone app recorded over [span], or null: it covers at least 3 s
     * of it (half of a shorter one), so the last second of the call before does not count.
     */
    fun ownFor(span: LongRange, own: List<Own>): Own? {
        val need = minOf(CallStateMachine.MIN_DURATION_MS, (span.last - span.first) / 2).coerceAtLeast(1)
        return own.map { it to (minOf(it.endMs, span.last) - maxOf(it.startMs, span.first)) }
            .filter { it.second >= need }
            .maxByOrNull { it.second }
            ?.first
    }

    /** What becomes of the Phone app's recording of a call. */
    enum class Verdict {
        /** Brought in as the call's recording. */
        IMPORT,

        /** EchoLedger is still recording or saving the call: decided on a later look. */
        WAIT,

        /** EchoLedger recorded the call itself: its own recording is the one kept. */
        OWN,

        /** Shorter than 3 s, or than "Skip calls shorter than". */
        SHORT,

        /** The person discarded EchoLedger's recording of the call. */
        DISCARDED,

        /** The person did not ask for this call to be recorded. */
        NOT_ASKED
    }

    /** An entry still open this long after its last news is no longer being saved. */
    private const val SAVING_MS = 30 * 60_000L

    /**
     * What to do with the Phone app's recording of [call] (pure, unit-tested): [entry] is EchoLedger's journal entry for
     * the call (null when it never saw it), [own] its own recording with sound, [choices] the person's choices when the
     * call rang, [shortestS] "Skip calls shorter than", [busy] EchoLedger is recording or saving now. A call EchoLedger
     * did not see is wanted when phone calls were set to Always with auto-record on; one it saw follows what became of it.
     */
    fun verdict(
        call: LogCall,
        entry: CallJournal.Call?,
        own: Own?,
        choices: CallJournal.Choices?,
        shortestS: Int,
        nowMs: Long,
        busy: Boolean = false
    ): Verdict = when {
        entry != null && !entry.finished && (busy || nowMs - entry.lastActivityMs() < SAVING_MS) -> Verdict.WAIT

        own != null -> Verdict.OWN

        call.talkS * 1000 < CallStateMachine.MIN_DURATION_MS || (shortestS > 0 && call.talkS < shortestS) -> Verdict.SHORT

        entry != null -> when (CallJournal.Result.of(entry.result)) {
            CallJournal.Result.KEPT -> Verdict.OWN

            CallJournal.Result.DISCARDED -> Verdict.DISCARDED

            CallJournal.Result.NOT_WANTED -> Verdict.NOT_ASKED

            // It failed (no sound, the line would not open), was taken for unanswered, or never came back.
            else -> Verdict.IMPORT
        }

        choices != null && asked(choices) -> Verdict.IMPORT

        else -> Verdict.NOT_ASKED
    }

    /** Every SIM call is wanted: phone calls set to Always, auto-record on, and not a viewer. */
    private fun asked(choices: CallJournal.Choices): Boolean =
        choices.autoRecord && !choices.viewer && PhoneCalls.mode(choices.phoneCalls) == PhoneCalls.ALWAYS

    /** The outcome in plain words, for the capture log and Settings (no names or numbers). */
    fun note(
        verdict: Verdict,
        call: LogCall,
        file: MediaFile,
        entry: CallJournal.Call?,
        own: Own?,
        choices: CallJournal.Choices?,
        shortestS: Int
    ): String = when (verdict) {
        Verdict.IMPORT -> "imported the Phone app's recording (${file.durationMs / 1000} s)."

        Verdict.WAIT -> "waiting: EchoLedger is still saving its own recording of this call."

        Verdict.OWN -> if (own != null) {
            "EchoLedger's own recording kept (" + (if (own.channels == 2) "both sides apart" else "both voices on one track") +
                "); the Phone app's copy stays in its folder."
        } else {
            "EchoLedger recorded this call itself; the Phone app's copy stays in its folder."
        }

        Verdict.SHORT -> if (shortestS > 0 && call.talkS * 1000 >= CallStateMachine.MIN_DURATION_MS) {
            "not imported, shorter than $shortestS s (Skip calls shorter than)."
        } else {
            "not imported, shorter than ${CallStateMachine.MIN_DURATION_MS / 1000} s."
        }

        Verdict.DISCARDED -> "not imported: EchoLedger's recording of this call was discarded."

        Verdict.NOT_ASKED -> when {
            entry?.note != null -> "not imported: ${entry.note}."
            choices != null && !choices.autoRecord -> "not imported: auto-record was off."
            else -> "not imported: Record phone calls was not set to Always."
        }
    }

    /**
     * Why a matched file was not brought in by itself; another app that made a file in the call folder is named (its
     * package, so the phone's own Phone app can be told apart when a phone names it differently).
     */
    fun note(trust: Trust, owner: String? = null): String = when (trust) {
        Trust.OK -> ""

        Trust.UNKNOWN_MAKER ->
            "waiting for you: the phone does not say which app made the file; import it in Settings if it is this call's."

        Trust.NOT_THE_PHONE_APP -> "not imported: another app" + (owner?.let { " ($it)" } ?: "") + " made the file in the call folder."

        Trust.TOO_LARGE -> "not imported: the file is larger than a recording of this call can be."
    }

    /** "Call with Omar" or "Call with +92 300 1234567": the contact's name, else the number. */
    fun title(name: String?, number: String?): String? = name?.trim()?.takeIf { it.isNotEmpty() }?.let { "Call with $it" }
        ?: number?.trim()?.takeIf { it.any(Char::isDigit) }?.let { "Call with $it" }

    // ---- the guide (Settings → Calls & meetings → Auto-record apps → Phone calls) ---------------------------------

    /** Where the Phone app turns on its own call recording, in plain words, for this phone's maker. */
    fun guide(manufacturer: String): String = when {
        manufacturer.equals("samsung", ignoreCase = true) ->
            "Phone app → ⋮ → Settings → Record calls → Auto record calls → All calls."

        manufacturer.equals("xiaomi", ignoreCase = true) || manufacturer.equals("redmi", ignoreCase = true) ||
            manufacturer.equals("poco", ignoreCase = true) ->
            "Phone app → Settings → Call recording → Record calls automatically → All numbers."

        else -> "In your Phone app's settings, turn on recording every call automatically, if your phone has it."
    }

    /** What the phone-line notification says when a SIM call gave no sound. */
    fun silentLineNotice(context: Context, settings: AppSettings, manufacturer: String = Build.MANUFACTURER): String = when {
        !settings.phoneAppImport -> "The phone line gave no sound, so this call was not recorded."

        phoneAppRecords(context, manufacturer) ->
            "The phone line gave no sound. EchoLedger will bring in the Phone app's own recording of this call in a moment."

        manufacturer.equals("samsung", ignoreCase = true) ->
            "The phone line gave no sound. Let the Phone app record calls: ${guide(manufacturer)} EchoLedger then brings in each recording."

        else ->
            "The phone line gave no sound. If your Phone app can record every call, turn that on: EchoLedger then brings in each recording."
    }

    /**
     * Samsung's Phone app setting "Auto record calls" (a system setting, when Android lets apps read it); null on other
     * phones or when it cannot be read, so nothing is claimed.
     */
    fun samsungAutoRecord(context: Context, manufacturer: String = Build.MANUFACTURER): Boolean? {
        if (!manufacturer.equals("samsung", ignoreCase = true)) return null
        return runCatching { android.provider.Settings.System.getString(context.contentResolver, SAMSUNG_AUTO_RECORD) }
            .getOrNull()
            ?.trim()
            ?.let { it == "1" }
    }

    /** The system setting where One UI keeps "Auto record calls" (1 on, 0 off). */
    const val SAMSUNG_AUTO_RECORD = "record_calls_automatically_on_off"

    /** Whether the Phone app records calls by itself (Samsung's setting; unknown elsewhere). */
    fun phoneAppRecords(context: Context, manufacturer: String = Build.MANUFACTURER): Boolean =
        samsungAutoRecord(context, manufacturer) == true

    /** EchoLedger may read the Phone app's recordings (audio files) and the call log; never in the Google Play build. */
    fun allowed(context: Context): Boolean = PhoneCallLog.readable(context) && PhoneCallLog.granted(context, permission())

    fun permission(): String = if (Build.VERSION.SDK_INT >=
        33
    ) {
        Manifest.permission.READ_MEDIA_AUDIO
    } else {
        Manifest.permission.READ_EXTERNAL_STORAGE
    }

    /** Whether the import runs: its switch on, phone calls recorded (Always or Ask each time), and not a viewer. */
    fun wanted(settings: AppSettings): Boolean =
        settings.phoneAppImport && !settings.plan.viewer && PhoneCalls.mode(settings.phoneCalls) != PhoneCalls.OFF

    // ---- what the import reads and keeps ----------------------------------------------------------------------------

    /** Where the files and the calls come from: the media store and the call log on the phone, fakes in tests. */
    interface Sources {
        fun files(sinceMs: Long): List<MediaFile>

        fun calls(sinceMs: Long): List<LogCall>

        /** [file]'s row as it is now (a file changed since it was matched is not brought in), or null when it is gone. */
        fun reload(file: MediaFile): MediaFile?

        /** Its bytes, through the media store only (never a path on the disk). */
        fun open(file: MediaFile): InputStream?

        /** This phone: its maker, its default dialer, and which apps came with it (what trust is decided on). */
        fun thisPhone(): ThisPhone
    }

    /** The phone's media store and call log; read only, it never deletes or moves anything. */
    class Phone(private val context: Context) : Sources {
        private val columns = arrayOf(
            MediaStore.Audio.Media._ID,
            MediaStore.Audio.Media.DISPLAY_NAME,
            MediaStore.Audio.Media.RELATIVE_PATH,
            MediaStore.Audio.Media.DATE_ADDED,
            MediaStore.Audio.Media.DURATION,
            MediaStore.Audio.Media.DATE_MODIFIED,
            MediaStore.MediaColumns.OWNER_PACKAGE_NAME,
            MediaStore.Audio.Media.SIZE
        )

        private fun query(selection: String, args: Array<String>, limit: Int): List<MediaFile> = runCatching {
            context.contentResolver.query(
                MediaStore.Audio.Media.EXTERNAL_CONTENT_URI,
                columns,
                selection,
                args,
                "${MediaStore.Audio.Media.DATE_ADDED} DESC"
            )
                ?.use { c ->
                    buildList {
                        while (c.moveToNext() && size < limit) {
                            add(
                                MediaFile(
                                    id = c.getLong(0),
                                    name = c.getString(1).orEmpty(),
                                    path = c.getString(2).orEmpty(),
                                    addedMs = c.getLong(3) * 1000,
                                    durationMs = c.getLong(4),
                                    modifiedMs = c.getLong(5) * 1000,
                                    owner = if (c.isNull(6)) null else c.getString(6),
                                    sizeBytes = if (c.isNull(7)) 0 else c.getLong(7)
                                )
                            )
                        }
                    }
                }.orEmpty()
        }.getOrDefault(emptyList())

        override fun files(sinceMs: Long): List<MediaFile> =
            query("${MediaStore.Audio.Media.DATE_ADDED} >= ?", arrayOf(((sinceMs - 10_000) / 1000).toString()), 200)

        override fun reload(file: MediaFile): MediaFile? =
            query("${MediaStore.Audio.Media._ID} = ?", arrayOf(file.id.toString()), 1).firstOrNull()

        override fun calls(sinceMs: Long): List<LogCall> {
            if (!PhoneCallLog.readable(context)) return emptyList()
            return runCatching {
                context.contentResolver.query(
                    CallLog.Calls.CONTENT_URI,
                    arrayOf(
                        CallLog.Calls.DATE,
                        CallLog.Calls.DURATION,
                        CallLog.Calls.TYPE,
                        CallLog.Calls.NUMBER,
                        CallLog.Calls.CACHED_NAME
                    ),
                    "${CallLog.Calls.DATE} >= ?",
                    arrayOf(sinceMs.toString()),
                    "${CallLog.Calls.DATE} ASC"
                )?.use { c ->
                    buildList {
                        while (c.moveToNext() && size < 200) {
                            val type = c.getInt(2)
                            if (type != CallLog.Calls.INCOMING_TYPE && type != CallLog.Calls.OUTGOING_TYPE) continue
                            add(
                                LogCall(
                                    c.getLong(0),
                                    c.getLong(1),
                                    c.getString(3),
                                    c.getString(4),
                                    incoming =
                                        type == CallLog.Calls.INCOMING_TYPE
                                )
                            )
                        }
                    }
                }.orEmpty()
            }.getOrDefault(emptyList())
        }

        override fun open(file: MediaFile): InputStream? = context.contentResolver.openInputStream(uris(listOf(file.id)).single())

        override fun thisPhone(): ThisPhone = ThisPhone(
            manufacturer = Build.MANUFACTURER,
            defaultDialer = runCatching { context.getSystemService(TelecomManager::class.java)?.defaultDialerPackage }.getOrNull(),
            isSystemApp = { pkg ->
                runCatching {
                    val flags = context.packageManager.getApplicationInfo(pkg, 0).flags
                    flags and (ApplicationInfo.FLAG_SYSTEM or ApplicationInfo.FLAG_UPDATED_SYSTEM_APP) != 0
                }.getOrDefault(false)
            }
        )
    }

    /** What the import remembers between runs (no names or numbers): the files it settled, the last outcome, the deletes. */
    private fun prefs(context: Context) = context.getSharedPreferences("phone_app_recordings", Context.MODE_PRIVATE)

    private fun ids(text: String?): List<Long> = text?.split(',')?.mapNotNull { it.toLongOrNull() }.orEmpty()

    /** The last call's outcome, for Settings ("Last call 10:42: Imported the Phone app's recording (12 s)."), or null. */
    fun lastNote(context: Context): Pair<Long, String>? {
        val p = prefs(context)
        val text = p.getString(NOTE, null) ?: return null
        return p.getLong(NOTE_AT, 0) to text
    }

    /** The Phone app's copies the person agreed to delete after import, waiting for Android's confirmation. */
    fun pendingDeletes(context: Context): List<Long> = ids(prefs(context).getString(DELETE, null))

    fun clearPendingDeletes(context: Context) = prefs(context).edit().remove(DELETE).apply()

    /** The media store addresses of [ids], for Android's delete confirmation and for reading. */
    fun uris(ids: List<Long>): List<Uri> = ids.map { ContentUris.withAppendedId(MediaStore.Audio.Media.EXTERNAL_CONTENT_URI, it) }

    /**
     * The Phone app's copies to ask Android to delete now (the app shows Android's own confirmation, which the person
     * answers): only imported ones, only with "Delete the Phone app's copy after import" on (Android 11 and later). With
     * the option off they are forgotten, so nothing is ever deleted.
     */
    fun takeDeletes(context: Context, settings: AppSettings): List<Uri> {
        val ids = pendingDeletes(context)
        if (ids.isEmpty()) return emptyList()
        clearPendingDeletes(context)
        return if (settings.deletePhoneAppCopy && Build.VERSION.SDK_INT >= 30) uris(ids) else emptyList()
    }

    /** A matched recording the phone does not say the maker of, waiting for the person: the file, and its call's start. */
    data class Candidate(val fileId: Long, val callAtMs: Long)

    /** The recordings waiting for the person to import them (newest last, at most 5). */
    fun candidates(context: Context): List<Candidate> = prefs(context).getString(ASK, null).orEmpty().split(',').mapNotNull { part ->
        val n = part.split(':').mapNotNull { it.toLongOrNull() }
        if (n.size == 2) Candidate(n[0], n[1]) else null
    }

    private fun saveCandidates(context: Context, list: List<Candidate>) =
        prefs(context).edit().putString(ASK, list.takeLast(5).joinToString(",") { "${it.fileId}:${it.callAtMs}" }).apply()

    /** "Not this call": the recording is no longer offered. */
    fun dropCandidate(context: Context, fileId: Long) = saveCandidates(context, candidates(context).filter { it.fileId != fileId })

    /** What one run did: the recordings it imported, the calls it settled, and the ones EchoLedger is still saving. */
    data class Outcome(val imported: List<String> = emptyList(), val settled: Int = 0, val waiting: Int = 0)

    /** One run at a time: the watch, the look after a call and an import the person asked for may start together. */
    private val running = Mutex()

    /** One run: the SIM calls of the last day, each with the Phone app's recording when it has one. */
    suspend fun run(context: Context, sources: Sources, nowMs: Long = System.currentTimeMillis()): Outcome = running.withLock {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        val settings = graph.settings.current()
        if (!wanted(settings)) return@withLock Outcome()
        // A journal without the person's choices yet (the first run after the update): the ones of now, from its start.
        CallJournal.update(context, nowMs) { b ->
            if (b.settings.isEmpty()) CallJournal.choices(b, CallJournal.choicesOf(settings), b.since) else b
        }
        val book = CallJournal.book(context)
        val since = maxOf(book.since, nowMs - DAY_MS)
        val p = prefs(context)
        val handled = ids(p.getString(HANDLED, null))
        val phone = sources.thisPhone()
        val files = sources.files(since - RING_MAX_MS).filter { it.id !in handled }
        // A file another app made in a call folder is never looked at again (and never takes a call's place).
        val foreign = files.filter { folderOf(it.path) != null && ownerTrust(it, phone) == Trust.NOT_THE_PHONE_APP }
        foreign.forEach { log(context, note(Trust.NOT_THE_PHONE_APP, it.owner)) }
        if (foreign.isNotEmpty()) markHandled(context, foreign.map { it.id })
        val pairs = match(
            sources.calls(since - RING_MAX_MS).filter { it.dateMs >= since - RING_MAX_MS },
            files - foreign.toSet(),
            handled.toSet(),
            trustOf = { file, call -> trust(file, call, phone) }
        )
        if (pairs.isEmpty()) return@withLock Outcome()
        val entries = entriesFor(pairs.map { it.first }, book.calls)
        val own = ownRecordings(book, graph.recordings.all())
        val busy = RecordingService.isRecording || RecordingService.isSaving
        val imported = mutableListOf<String>()
        val done = (handled + foreign.map { it.id }).toMutableList()
        var settled = 0
        var waiting = 0
        for ((call, file) in pairs) {
            val entry = entries[call]
            val mine = ownFor(spanOf(file), own)
            val choices = book.choicesAt(call.dateMs)
            val shortest = settings.skipCallsShorterThanS
            val verdict = verdict(call, entry, mine, choices, shortest, nowMs, busy)
            if (verdict == Verdict.WAIT) {
                waiting++
                continue
            }
            var note = note(verdict, call, file, entry, mine, choices, shortest)
            var waitsForPerson = false
            if (verdict == Verdict.IMPORT) {
                val trust = trust(file, call, phone)
                if (trust == Trust.OK) {
                    when (val copy = importFile(context, sources, file, call, settings)) {
                        // A file that cannot be read now is looked at again on the next run.
                        Copy.Later -> continue

                        is Copy.Refused -> note = copy.note

                        is Copy.Done -> imported += copy.stored.id
                    }
                } else {
                    note = note(trust, file.owner)
                    if (trust == Trust.UNKNOWN_MAKER) {
                        waitsForPerson = true
                        saveCandidates(context, candidates(context).filter { it.fileId != file.id } + Candidate(file.id, call.dateMs))
                        Notifications.phoneAppRecordingToCheck(context, call.dateMs)
                    }
                }
            }
            done += file.id
            settled++
            // The detector may judge the call now; one waiting for the person still waits (at most its time limit).
            if (!waitsForPerson) PendingImports.doneOverlapping(context, call.dateMs, call.dateMs + RING_MAX_MS + call.talkS * 1000)
            log(context, note)
            if (call.dateMs >= p.getLong(NOTE_AT, 0)) {
                p.edit().putString(NOTE, note.replaceFirstChar { it.uppercase() }).putLong(NOTE_AT, call.dateMs).apply()
            }
        }
        markHandled(context, done)
        Outcome(imported, settled, waiting)
    }

    /**
     * Files settled for good are added to the handled list at once and written before going on ([commit]), so a run
     * cancelled after a file was stored never brings it in twice.
     */
    private fun markHandled(context: Context, ids: List<Long>) {
        val p = prefs(context)
        val now = (ids(p.getString(HANDLED, null)) + ids).distinct().takeLast(MAX_HANDLED)
        p.edit().putString(HANDLED, now.joinToString(",")).commit()
    }

    /** Tests only: runs right after a file was stored and marked handled, where a cancellation could come. */
    internal var afterStoreForTest: (suspend () -> Unit)? = null

    /**
     * The person chose "Import this recording" for a file the phone does not say the maker of: it is checked again (the
     * call it belongs to, written during it and not changed since, its length and size) and brought in. Returns the note.
     */
    suspend fun importCandidate(context: Context, fileId: Long, sources: Sources = Phone(context)): String = running.withLock {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        val settings = graph.settings.current()
        val candidate = candidates(context).firstOrNull { it.fileId == fileId } ?: return@withLock "not imported: it is no longer waiting."

        // Whatever the answer, it is no longer offered; only a file that could not be read now stays for another try.
        fun answered(note: String): String {
            dropCandidate(context, fileId)
            log(context, note)
            return note
        }
        val file = sources.reload(MediaFile(fileId, "", "", 0, 0)) ?: return@withLock answered("not imported: the file is gone.")
        val call =
            match(sources.calls(candidate.callAtMs - RING_MAX_MS), listOf(file)).firstOrNull {
                it.first.dateMs == candidate.callAtMs
            }?.first
                ?: return@withLock answered("not imported: the file no longer fits the call.")
        val trust = trust(file, call, sources.thisPhone())
        if (trust == Trust.TOO_LARGE || trust == Trust.NOT_THE_PHONE_APP) return@withLock answered(note(trust, file.owner))
        when (val copy = importFile(context, sources, file, call, settings)) {
            Copy.Later -> "not imported yet: the file could not be read.".also { log(context, it) }

            is Copy.Refused -> answered(copy.note)

            is Copy.Done -> answered("imported the Phone app's recording (${file.durationMs / 1000} s), as you chose.").also { note ->
                prefs(context).edit().putString(NOTE, note.replaceFirstChar { it.uppercase() }).putLong(NOTE_AT, call.dateMs).apply()
            }
        }
    }

    /** One line in the capture log and in logcat (`adb logcat -s EchoLedgerSim`), next to the SIM call's own. */
    private fun log(context: Context, text: String) {
        CaptureLog.add(context, "phone app recording", text)
        runCatching { Log.i(RecordingService.SIM_TAG, "phone app recording: $text") }
    }

    /**
     * EchoLedger's own SIM-call recordings with sound on the phone. One skipped (unanswered, short), one that failed for
     * silence on its way out, or one imported from the Phone app does not count.
     */
    private fun ownRecordings(book: CallJournal.Book, recordings: List<LocalRecording>): List<Own> = recordings
        .filter { rec ->
            rec.sourceApp == SourceApp.PHONE_CALL.wire && rec.durationMs > 0 && rec.state != SkippedCalls.STATE &&
                rec.captureWarning?.startsWith(IMPORTED) != true &&
                CallJournal.Result.of(book.call(rec.id)?.result) != CallJournal.Result.FAILED
        }
        .mapNotNull { rec ->
            val start = runCatching { Instant.parse(rec.startedAt).toEpochMilli() }.getOrNull() ?: return@mapNotNull null
            Own(start, start + rec.durationMs, rec.channels)
        }

    /** How a copy went: brought in, refused for good (said why), or to be tried again later. */
    private sealed interface Copy {
        data class Done(val stored: LocalRecording) : Copy

        data class Refused(val note: String) : Copy

        data object Later : Copy
    }

    /**
     * Copies [file] into EchoLedger's encrypted storage as [call]'s recording, through the media store only; the original
     * is only read. Its row is read again first (a file changed since it was matched is not this call's), and the copy
     * stops at the most a recording of the call can be.
     */
    private suspend fun importFile(context: Context, sources: Sources, file: MediaFile, call: LogCall, settings: AppSettings): Copy {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        val now = sources.reload(file) ?: return Copy.Refused("not imported: the file is gone.")
        if (now.modifiedMs != file.modifiedMs || now.sizeBytes != file.sizeBytes || now.durationMs != file.durationMs) {
            return Copy.Refused("not imported: the file changed after the call.")
        }
        val limit = maxBytes(call.talkS)
        val copy = File(context.cacheDir, "import-${UUID.randomUUID()}.m4a")
        val copied: Long? = runCatching {
            sources.open(file)?.use { input ->
                copy.outputStream().use { out ->
                    val buffer = ByteArray(64 * 1024)
                    var total = 0L
                    while (true) {
                        val n = input.read(buffer)
                        if (n < 0) break
                        total += n
                        if (total > limit) return@use -1L
                        out.write(buffer, 0, n)
                    }
                    total
                }
            }
        }.getOrNull()
        when {
            copied == null -> {
                copy.delete()
                log(context, "not imported yet: the file could not be read.")
                return Copy.Later
            }

            copied < 0 -> {
                copy.delete()
                return Copy.Refused(note(Trust.TOO_LARGE))
            }

            file.sizeBytes > 0 && copied != file.sizeBytes -> {
                copy.delete()
                return Copy.Refused("not imported: the file changed while it was read.")
            }
        }
        val name = call.name?.takeIf { it.isNotBlank() } ?: call.number?.let { PhoneCallLog.contactNameOf(context, it) }
        val span = spanOf(file)
        val mp3 = file.name.endsWith(".mp3", ignoreCase = true)
        val meta = LocalRecording(
            id = UUID.randomUUID().toString(),
            sourceApp = SourceApp.PHONE_CALL.wire,
            clientId = settings.clientId,
            startedAt = Instant.ofEpochMilli(span.first).toString(),
            endedAt = Instant.ofEpochMilli(span.first + file.durationMs).toString(),
            durationMs = file.durationMs,
            mime = if (mp3) "audio/mpeg" else "audio/mp4",
            codec = if (mp3) "mp3" else "aac",
            channels = 1,
            sampleRate = 48_000,
            plainSize = 0,
            sha256 = "",
            state = PostCallPrompt.stateFor(settings.afterCall),
            language = settings.transcriptionLanguage,
            callerName = name,
            callerSource = if (name != null) "contacts" else "phone",
            callerNumber = call.number?.trim()?.takeIf { it.any(Char::isDigit) }?.take(40),
            captureWarning = "$IMPORTED (the Phone app recorded this call; its copy stays in its folder).",
            // The same file sent twice (an interrupted run) is skipped by the server rather than kept twice.
            skipDuplicate = true
        )
        val stored = runCatching { graph.recordings.importPlainFile(meta, copy) }.getOrNull()
        copy.delete()
        if (stored == null) return Copy.Later
        // Stored: settled for good at once, so nothing can bring this file in a second time.
        markHandled(context, listOf(file.id))
        afterStoreForTest?.invoke()
        // The rest happens even when the run is being cancelled now.
        withContext(NonCancellable) { afterStore(context, stored, file, call, span, settings) }
        return Copy.Done(stored)
    }

    /** After a file was stored as a call's recording: the journal, a missed report it covers, the delete, the card. */
    private suspend fun afterStore(
        context: Context,
        stored: LocalRecording,
        file: MediaFile,
        call: LogCall,
        span: LongRange,
        settings: AppSettings
    ) {
        // The capture journal: this phone call is recorded, by the Phone app.
        CallJournal.update(context) { book ->
            CallJournal.finish(
                CallJournal.seen(book, stored.id, SourceApp.PHONE_CALL.wire, CallJournal.Seen.PHONE_APP, span.first),
                stored.id,
                CallJournal.Result.KEPT,
                span.first + file.durationMs,
                "imported from the Phone app",
                file.durationMs
            )
        }
        // A missed recording found for this call before the import (the Phone app was slow) is withdrawn.
        MissedRecordingStore.all(context)
            .filter { it.app == SourceApp.PHONE_CALL.wire && !it.sent && abs(it.startedAtMs - call.dateMs) <= MissedRecordings.MATCH_MS }
            .forEach { MissedRecordingStore.withdraw(context, it.ref) }
        if (settings.deletePhoneAppCopy && Build.VERSION.SDK_INT >= 30) {
            prefs(context).edit().putString(DELETE, (pendingDeletes(context) + file.id).distinct().joinToString(",")).apply()
        }
        withContext(Dispatchers.Main.immediate) { PostCallPrompt.show(context, stored, settings.afterCall, settings.themeMode) }
    }

    /** The start of the line every imported recording says (also how the import knows its own). */
    const val IMPORTED = "Imported from the Phone app's own call recording"
    private const val DAY_MS = 24 * 60 * 60 * 1000L
    private const val MAX_HANDLED = 300
    private const val HANDLED = "handled"
    private const val DELETE = "delete"
    private const val ASK = "ask"
    private const val NOTE = "note"
    private const val NOTE_AT = "note_at"

    // ---- when it runs -----------------------------------------------------------------------------------------------

    /**
     * Starts watching the media store while the import is wanted and allowed, and stops otherwise (the app opened, the
     * Phone calls settings changed). A new audio file anywhere then starts a run within a minute, even with the app closed.
     */
    fun sync(context: Context, settings: AppSettings) {
        runCatching {
            if (wanted(settings) && allowed(context)) watch(context) else WorkManager.getInstance(context).cancelUniqueWork(WATCH)
        }
    }

    /** The media store watch (WorkManager's content trigger); each run watches [again] after itself. */
    fun watch(context: Context, again: Boolean = false) {
        val request = OneTimeWorkRequestBuilder<Worker>()
            .setConstraints(
                Constraints.Builder()
                    .addContentUriTrigger(MediaStore.Audio.Media.EXTERNAL_CONTENT_URI, true)
                    .setTriggerContentUpdateDelay(5, TimeUnit.SECONDS)
                    .setTriggerContentMaxDelay(60, TimeUnit.SECONDS)
                    .build()
            )
            .setInputData(workDataOf(KEY_WATCH to true))
            .build()
        val policy = if (again) ExistingWorkPolicy.APPEND_OR_REPLACE else ExistingWorkPolicy.KEEP
        WorkManager.getInstance(context).enqueueUniqueWork(WATCH, policy, request)
    }

    /**
     * The SIM call from [startMs] to [endMs], which EchoLedger recorded (or tried to), just ended: look a little later,
     * when the Phone app has saved its recording, and a few more times while it (or EchoLedger itself) is still saving.
     * Until then the missed-recording detector leaves the call alone.
     */
    fun schedule(context: Context, startMs: Long, endMs: Long) {
        PendingImports.add(context, startMs, endMs)
        runCatching {
            val request = OneTimeWorkRequestBuilder<Worker>().setInitialDelay(20, TimeUnit.SECONDS).build()
            // Never REPLACE: that would cancel a look in the middle of storing a file; the new look runs after it.
            WorkManager.getInstance(context).enqueueUniqueWork(SOON, ExistingWorkPolicy.APPEND_OR_REPLACE, request)
        }
    }

    private const val KEY_WATCH = "watch"
    const val WATCH = "phone-app-recordings-watch"
    const val SOON = "phone-app-recordings-soon"
    private const val TRIES = 5

    class Worker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
        override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
            val context = applicationContext
            val watching = inputData.getBoolean(KEY_WATCH, false)
            val settings = (context as EchoLedgerApp).graph.settings.current()
            // Not wanted any more: the watch ends here (turning the import back on starts it again).
            if (!wanted(settings) || !allowed(context)) {
                if (!watching && wanted(settings)) log(context, "not imported: no permission to read audio files or the call log.")
                if (!watching) gaveUp(context)
                return@withContext Result.success()
            }
            val outcome = run(context, Phone(context))
            if (watching) watch(context, again = true)
            // Right after a call the Phone app (or EchoLedger itself) may still be saving: look again a few times.
            val again = !watching && (outcome.waiting > 0 || outcome.settled == 0) && runAttemptCount + 1 < TRIES
            if (again) return@withContext Result.retry()
            if (!watching) gaveUp(context)
            Result.success()
        }

        /** The looks after a call are over: the detector may judge what is left, a little later. */
        private fun gaveUp(context: Context) {
            if (PendingImports.waiting(context).isEmpty()) return
            PendingImports.clear(context)
            MissedRecordingWorker.soon(context)
        }
    }
}
