package com.echoledger.mobile.calls

import android.content.Context
import com.echoledger.mobile.core.Owner
import com.echoledger.mobile.data.ApiJson
import java.io.File
import java.util.UUID
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.serialization.builtins.ListSerializer

/**
 * This phone's missed recordings (since v1.8, P0 0.2): the Activity items with their reason and fix button, and the
 * reports still to send. Kept 31 days in no-backup storage: a day past the 30 the server takes, so a report too old to
 * send is refused on the phone rather than lost on the way. Safe from any thread; [items] follows every change.
 */
object MissedRecordingStore {
    private const val KEEP_MS = 31L * 24 * 60 * 60 * 1000
    private const val MAX = 200

    private val _items = MutableStateFlow<List<MissedItem>>(emptyList())
    private var file: File? = null

    /** Newest first. */
    val items: StateFlow<List<MissedItem>> = _items.asStateFlow()

    @Synchronized
    fun all(context: Context): List<MissedItem> {
        load(context)
        return _items.value
    }

    /**
     * New items for what the detector found under [owner] (the account signed in); returns them, each with its own
     * `client_ref`. A call already here (its app family and start time) is never added twice.
     */
    @Synchronized
    fun add(
        context: Context,
        found: List<MissedRecordings.Found>,
        nowMs: Long = System.currentTimeMillis(),
        owner: Owner? = null
    ): List<MissedItem> {
        if (found.isEmpty()) return emptyList()
        val before = all(context)
        val fresh = mutableListOf<MissedItem>()
        for (f in found) {
            val twice = (before + fresh).any {
                MissedRecordings.sameApp(it.app, f.app) && kotlin.math.abs(it.startedAtMs - f.startedAtMs) <= MissedRecordings.MATCH_MS
            }
            if (twice) continue
            val fix = f.fix.takeIf { it != f.reason.fix }?.name
            fresh += MissedItem(
                ref = UUID.randomUUID().toString(),
                app = f.app,
                startedAtMs = f.startedAtMs,
                durationS = f.durationS,
                reason = f.reason.wire,
                detail = f.detail,
                foundAtMs = nowMs,
                owner = owner,
                fix = fix
            )
        }
        if (fresh.isEmpty()) return emptyList()
        save(context, fresh + before, nowMs)
        return fresh
    }

    @Synchronized
    fun change(context: Context, ref: String, transform: (MissedItem) -> MissedItem) {
        save(context, all(context).map { if (it.ref == ref) transform(it) else it })
    }

    fun markRead(context: Context, ref: String) = change(context, ref) { it.copy(read = true) }

    fun dismiss(context: Context, ref: String) = change(context, ref) { it.copy(read = true, dismissed = true) }

    fun sent(context: Context, ref: String, serverId: String?) = change(context, ref) { it.copy(sent = true, serverId = serverId) }

    fun refused(context: Context, ref: String) = change(context, ref) { it.copy(refused = true) }

    /** A recording of the call turned up after all: the item leaves Activity and is never sent. */
    fun withdraw(context: Context, ref: String) = change(context, ref) { it.copy(read = true, dismissed = true, withdrawn = true) }

    /**
     * A sign-in as [next] after [previous]: when it is someone else, the items found without an account are stamped with
     * [previous] (they were found under it), as recordings are, so they never show or go out under the new account.
     */
    @Synchronized
    fun stamp(context: Context, previous: Owner?, next: Owner) {
        if (previous == null || previous.mayUploadAs(next)) return
        val items = all(context)
        if (items.none { it.owner == null }) return
        save(context, items.map { if (it.owner == null) it.copy(owner = previous) else it })
    }

    /** What [current] sees and sends: its own items (and those found before any account was known). */
    fun forAccount(items: List<MissedItem>, current: Owner?): List<MissedItem> =
        items.filter { !it.withdrawn && Owner.mayUpload(it.owner, current) }

    @Synchronized
    fun clear(context: Context) {
        load(context)
        file?.delete()
        _items.value = emptyList()
    }

    private fun save(context: Context, list: List<MissedItem>, nowMs: Long = System.currentTimeMillis()) {
        load(context)
        val kept = list.filter { it.startedAtMs >= nowMs - KEEP_MS }.sortedByDescending { it.startedAtMs }.take(MAX)
        _items.value = kept
        val target = file ?: return
        runCatching {
            val tmp = File(target.parentFile, target.name + ".tmp")
            tmp.writeText(ApiJson.encodeToString(ListSerializer(MissedItem.serializer()), kept))
            if (!tmp.renameTo(target)) {
                target.delete()
                tmp.renameTo(target)
            }
        }
    }

    private fun load(context: Context) {
        val target = File(context.noBackupFilesDir, "missed-recordings.json")
        if (file == target) return
        file = target
        _items.value = runCatching {
            ApiJson.decodeFromString(ListSerializer(MissedItem.serializer()), target.readText())
        }.getOrDefault(emptyList())
    }
}
