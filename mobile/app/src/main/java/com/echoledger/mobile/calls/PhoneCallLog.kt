package com.echoledger.mobile.calls

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.provider.CallLog
import android.provider.ContactsContract
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.core.PhoneCalls
import com.echoledger.mobile.data.CallerDto

/**
 * Who a recorded SIM phone call was with (since v1.2): the number from the call log (READ_CALL_LOG) and the contact's
 * name (the call log's cached name, else a contacts lookup with READ_CONTACTS). Without the permissions: nothing.
 */
object PhoneCallLog {
    /** The dialer writes the row when the call ends; wait for it this many times. */
    private const val TRIES = 4
    private const val WAIT_MS = 750L

    /** False in the Google Play build (since v1.5): it has no call log permission, so it never asks for it. */
    val available: Boolean get() = BuildConfig.CALL_LOG

    fun find(context: Context, startMs: Long, endMs: Long): CallerDto? {
        if (!readable(context)) return null
        repeat(TRIES) { attempt ->
            val entry = PhoneCalls.pick(rows(context, startMs - PhoneCalls.BEFORE_MS), startMs, endMs)
            if (entry != null) {
                val name = entry.name ?: entry.number?.let { contactName(context, it) }
                return PhoneCalls.caller(entry.copy(name = name))
            }
            if (attempt < TRIES - 1) Thread.sleep(WAIT_MS)
        }
        return null
    }

    /**
     * Since v1.5: the talk time of the SIM call recorded from [startMs] to [endMs] (the call log's duration counts from
     * the answer; 0 = never answered); null without the permission or a matching row.
     */
    fun talkTimeMs(context: Context, startMs: Long, endMs: Long): Long? {
        if (!readable(context)) return null
        repeat(TRIES) { attempt ->
            PhoneCalls.pick(rows(context, startMs - PhoneCalls.BEFORE_MS), startMs, endMs)?.durationS?.let { return it * 1000 }
            if (attempt < TRIES - 1) Thread.sleep(WAIT_MS)
        }
        return null
    }

    /**
     * Since v1.8: the SIM calls since [sinceMs] for the missed-recording detector, by their time and talk time only (the
     * number and the name are not read). Answered = an incoming or outgoing call with talk time; none without the
     * permission (and in the Google Play build).
     */
    fun calls(context: Context, sinceMs: Long): List<MissedRecordings.Sighting> {
        if (!readable(context)) return emptyList()
        return runCatching {
            context.contentResolver.query(
                CallLog.Calls.CONTENT_URI,
                arrayOf(CallLog.Calls.DATE, CallLog.Calls.DURATION, CallLog.Calls.TYPE),
                "${CallLog.Calls.DATE} >= ?",
                arrayOf(sinceMs.toString()),
                "${CallLog.Calls.DATE} ASC"
            )?.use { cursor ->
                buildList {
                    while (cursor.moveToNext() && size < 500) {
                        add(sighting(cursor.getLong(0), cursor.getLong(1), cursor.getInt(2)))
                    }
                }
            }.orEmpty()
        }.getOrDefault(emptyList())
    }

    /** One call-log row as the detector reads it ([type] is `CallLog.Calls.TYPE`). */
    fun sighting(dateMs: Long, durationS: Long, type: Int): MissedRecordings.Sighting = MissedRecordings.Sighting(
        app = com.echoledger.mobile.core.SourceApp.PHONE_CALL.wire,
        startMs = dateMs,
        endMs = dateMs + durationS * 1000,
        answered = durationS > 0 && (type == CallLog.Calls.INCOMING_TYPE || type == CallLog.Calls.OUTGOING_TYPE),
        by = MissedRecordings.CALL_LOG
    )

    private fun rows(context: Context, sinceMs: Long): List<PhoneCalls.LogEntry> = runCatching {
        context.contentResolver.query(
            CallLog.Calls.CONTENT_URI,
            arrayOf(CallLog.Calls.NUMBER, CallLog.Calls.CACHED_NAME, CallLog.Calls.DATE, CallLog.Calls.DURATION),
            "${CallLog.Calls.DATE} >= ?",
            arrayOf(sinceMs.toString()),
            "${CallLog.Calls.DATE} DESC"
        )?.use { cursor ->
            buildList {
                while (cursor.moveToNext() && size < 10) {
                    add(
                        PhoneCalls.LogEntry(
                            number = cursor.getString(0),
                            name = cursor.getString(1),
                            dateMs = cursor.getLong(2),
                            durationS = if (cursor.isNull(3)) null else cursor.getLong(3)
                        )
                    )
                }
            }
        }.orEmpty()
    }.getOrDefault(emptyList())

    /** The contact's name for [number] (READ_CONTACTS), or null: an unsaved number, or no permission. */
    fun contactNameOf(context: Context, number: String): String? = contactName(context, number)

    private fun contactName(context: Context, number: String): String? {
        if (!granted(context, Manifest.permission.READ_CONTACTS)) return null
        return runCatching {
            val uri = Uri.withAppendedPath(ContactsContract.PhoneLookup.CONTENT_FILTER_URI, Uri.encode(number))
            context.contentResolver.query(uri, arrayOf(ContactsContract.PhoneLookup.DISPLAY_NAME), null, null, null)?.use { cursor ->
                if (cursor.moveToFirst()) cursor.getString(0) else null
            }
        }.getOrNull()
    }

    fun granted(context: Context, permission: String): Boolean =
        context.checkSelfPermission(permission) == PackageManager.PERMISSION_GRANTED

    /** The call log may be read: this build has it and the person allowed it. */
    fun readable(context: Context): Boolean = available && granted(context, Manifest.permission.READ_CALL_LOG)

    /** What turning on phone calls asks for; nothing in the Google Play build, which records no calls. */
    fun permissionsToAsk(): Array<String> = listOfNotNull(
        Manifest.permission.READ_CALL_LOG.takeIf { available },
        Manifest.permission.READ_CONTACTS.takeIf { BuildConfig.CAPTURE_HELPER },
        PhoneAppRecordings.permission().takeIf { BuildConfig.CAPTURE_HELPER }
    ).toTypedArray()
}
